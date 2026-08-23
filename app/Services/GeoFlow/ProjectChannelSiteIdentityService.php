<?php

namespace App\Services\GeoFlow;

use App\Enums\ClientProjectStatus;
use App\Exceptions\ProjectSiteIdentityException;
use App\Models\ClientProject;
use App\Models\DistributionChannel;
use App\Models\ProjectChannelSiteIdentity;
use App\Models\ProjectChannelSiteIdentityHistory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owns the optional public identity of a project-bound GeoFlow Agent target.
 *
 * Distribution channels remain platform-owned and can still be shared through
 * memberships. This service only binds a channel to a project when it is
 * intentionally used as that project's public target site. There is no local
 * project-site route in this phase, so ClientProject::slug keeps its approved
 * `(client_id, slug)` scope rather than becoming a new global route key.
 */
final class ProjectChannelSiteIdentityService
{
    public const IDENTITY_PREFIX = 'project-channel-site:v1:';

    /**
     * Create or reconcile an explicit project-bound public target identity.
     *
     * @throws ProjectSiteIdentityException
     */
    public function provision(ClientProject|int $project, DistributionChannel|int $channel): ProjectChannelSiteIdentity
    {
        return DB::transaction(function () use ($project, $channel): ProjectChannelSiteIdentity {
            $lockedProject = $this->lockProject($project);
            $lockedChannel = $this->lockChannel($channel);
            $this->assertProjectChannelEligible($lockedProject, $lockedChannel);

            $existing = ProjectChannelSiteIdentity::query()
                ->where('distribution_channel_id', (int) $lockedChannel->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing && (int) $existing->client_project_id !== (int) $lockedProject->getKey()) {
                throw new ProjectSiteIdentityException('project_site_channel_already_bound');
            }

            $canonicalUrl = self::canonicalUrl((string) $lockedChannel->endpoint_url);
            $canonicalIdentity = self::canonicalIdentity($canonicalUrl);
            $this->assertIdentityAvailable($canonicalIdentity, $existing?->getKey());

            try {
                if (! $existing) {
                    return ProjectChannelSiteIdentity::query()->create([
                        'client_project_id' => (int) $lockedProject->getKey(),
                        'distribution_channel_id' => (int) $lockedChannel->getKey(),
                        'project_slug_snapshot' => (string) $lockedProject->slug,
                        'canonical_url' => $canonicalUrl,
                        'canonical_identity' => $canonicalIdentity,
                        'status' => ProjectChannelSiteIdentity::STATUS_ACTIVE,
                        'disabled_at' => null,
                    ]);
                }

                $this->replaceCanonicalIdentityIfNeeded($existing, $canonicalUrl, $canonicalIdentity);
                $existing->forceFill([
                    'project_slug_snapshot' => (string) $lockedProject->slug,
                    'status' => ProjectChannelSiteIdentity::STATUS_ACTIVE,
                    'disabled_at' => null,
                ])->save();

                return $existing->fresh();
            } catch (QueryException $exception) {
                if ($this->isUniqueConstraint($exception)) {
                    throw new ProjectSiteIdentityException('project_site_identity_conflict');
                }

                throw $exception;
            }
        });
    }

    /**
     * Reconcile the persisted identity after an administrator changes a channel
     * endpoint or disables the platform channel. It does nothing for channels
     * which have not opted into a project public-site identity.
     */
    public function reconcileChannel(DistributionChannel|int $channel): ?ProjectChannelSiteIdentity
    {
        return DB::transaction(function () use ($channel): ?ProjectChannelSiteIdentity {
            $lockedChannel = $this->lockChannel($channel);
            $identity = ProjectChannelSiteIdentity::query()
                ->where('distribution_channel_id', (int) $lockedChannel->getKey())
                ->lockForUpdate()
                ->first();
            if (! $identity) {
                return null;
            }

            $project = $this->lockProject((int) $identity->client_project_id);
            if ($this->projectStatus($project) !== ClientProjectStatus::ACTIVE->value
                || (string) $lockedChannel->status !== DistributionChannel::STATUS_ACTIVE
                || ! $this->hasActiveMembership($project, $lockedChannel)) {
                return $this->disableLocked($identity, ProjectChannelSiteIdentityHistory::REASON_DISABLED);
            }

            if (! $lockedChannel->isGeoFlowAgent()) {
                return $this->disableLocked($identity, ProjectChannelSiteIdentityHistory::REASON_DISABLED);
            }

            $canonicalUrl = self::canonicalUrl((string) $lockedChannel->endpoint_url);
            $canonicalIdentity = self::canonicalIdentity($canonicalUrl);
            $this->assertIdentityAvailable($canonicalIdentity, (int) $identity->getKey());
            $this->replaceCanonicalIdentityIfNeeded($identity, $canonicalUrl, $canonicalIdentity);
            $identity->forceFill(['project_slug_snapshot' => (string) $project->slug])->save();

            return $identity->fresh();
        });
    }

    /** @throws ProjectSiteIdentityException */
    public function disable(ClientProject|int $project, DistributionChannel|int $channel): ProjectChannelSiteIdentity
    {
        return DB::transaction(function () use ($project, $channel): ProjectChannelSiteIdentity {
            $lockedProject = $this->lockProject($project);
            $lockedChannel = $this->lockChannel($channel);
            $identity = ProjectChannelSiteIdentity::query()
                ->where('distribution_channel_id', (int) $lockedChannel->getKey())
                ->lockForUpdate()
                ->first();
            if (! $identity || (int) $identity->client_project_id !== (int) $lockedProject->getKey()) {
                throw new ProjectSiteIdentityException('project_site_identity_not_found');
            }

            return $this->disableLocked($identity, ProjectChannelSiteIdentityHistory::REASON_DISABLED);
        });
    }

    /**
     * Resolve the identity to attach to an existing target-site settings payload.
     * A null return deliberately preserves the legacy channel contract: no
     * project-scoped site is inferred just because a membership exists.
     *
     * @return array{version:string,status:string,canonical_url:string,canonical_identity:string,project_id:int,project_slug:string}|null
     */
    public function settingsIdentity(DistributionChannel|int $channel): ?array
    {
        if (! Schema::hasTable('project_channel_site_identities')) {
            return null;
        }

        $channelId = $channel instanceof DistributionChannel ? (int) $channel->getKey() : (int) $channel;
        if ($channelId <= 0) {
            return null;
        }
        $identity = ProjectChannelSiteIdentity::query()
            ->with('clientProject:id,slug,status')
            ->where('distribution_channel_id', $channelId)
            ->first();
        if (! $identity) {
            return null;
        }

        $payload = $this->identityPayload($identity);
        $project = $identity->clientProject;
        $freshChannel = $channel instanceof DistributionChannel
            ? $channel->fresh()
            : DistributionChannel::query()->find($channelId);
        if (! $project instanceof ClientProject || ! $freshChannel instanceof DistributionChannel
            || $this->projectStatus($project) !== ClientProjectStatus::ACTIVE->value
            || (string) $freshChannel->status !== DistributionChannel::STATUS_ACTIVE
            || ! $freshChannel->isGeoFlowAgent()
            || ! $this->hasActiveMembership($project, $freshChannel)) {
            // Keep the persisted state/history untouched on this read path, but
            // never let a newly built or synced public package expose an owner
            // that has become inactive outside the channel admin mutation path.
            $payload['status'] = ProjectChannelSiteIdentity::STATUS_DISABLED;
        }

        return $payload;
    }

    /**
     * Resolve the target content scope at send time from persisted owner facts.
     * No request body project ID is accepted. Legacy unbound channels receive
     * null and continue using the Phase-4 `channel:<id>` target identity.
     *
     * @return array{version:string,status:string,canonical_url:string,canonical_identity:string,project_id:int,project_slug:string}|null
     *
     * @throws ProjectSiteIdentityException
     */
    public function publicationScope(ClientProject|int $project, DistributionChannel|int $channel): ?array
    {
        $projectId = $project instanceof ClientProject ? (int) $project->getKey() : (int) $project;
        $channelId = $channel instanceof DistributionChannel ? (int) $channel->getKey() : (int) $channel;
        if ($projectId <= 0 || $channelId <= 0 || ! Schema::hasTable('project_channel_site_identities')) {
            return null;
        }

        $identity = ProjectChannelSiteIdentity::query()
            ->with('clientProject:id,slug,status')
            ->where('distribution_channel_id', $channelId)
            ->first();
        if (! $identity) {
            return null;
        }
        if ((int) $identity->client_project_id !== $projectId) {
            throw new ProjectSiteIdentityException('project_site_identity_project_mismatch');
        }
        if ((string) $identity->status !== ProjectChannelSiteIdentity::STATUS_ACTIVE) {
            throw new ProjectSiteIdentityException('project_site_identity_disabled');
        }

        $freshProject = $identity->clientProject;
        $freshChannel = $channel instanceof DistributionChannel
            ? $channel->fresh()
            : DistributionChannel::query()->find($channelId);
        if (! $freshProject instanceof ClientProject || ! $freshChannel instanceof DistributionChannel
            || $this->projectStatus($freshProject) !== ClientProjectStatus::ACTIVE->value
            || (string) $freshChannel->status !== DistributionChannel::STATUS_ACTIVE
            || ! $this->hasActiveMembership($freshProject, $freshChannel)) {
            throw new ProjectSiteIdentityException('project_site_identity_inactive');
        }
        if (! (bool) ($freshChannel->frontendCapabilitiesCache()['supports_project_site_identity'] ?? false)) {
            throw new ProjectSiteIdentityException('project_site_identity_capability_unavailable');
        }

        return $this->identityPayload($identity, $freshProject);
    }

    /**
     * Resolve an active public identity by its canonical URL. The caller can
     * consistently turn all unavailable/retired identities into a 404 without
     * ever falling back to another project or the central site.
     *
     * @return array{version:string,status:string,canonical_url:string,canonical_identity:string,project_id:int,project_slug:string}
     *
     * @throws ProjectSiteIdentityException
     */
    public function resolveActiveCanonicalUrl(string $canonicalUrl): array
    {
        $canonicalUrl = self::canonicalUrl($canonicalUrl);
        $canonicalIdentity = self::canonicalIdentity($canonicalUrl);
        $identity = ProjectChannelSiteIdentity::query()
            ->with(['clientProject:id,slug,status', 'distributionChannel:id,status'])
            ->where('canonical_identity', $canonicalIdentity)
            ->first();
        if (! $identity || (string) $identity->status !== ProjectChannelSiteIdentity::STATUS_ACTIVE) {
            throw new ProjectSiteIdentityException('project_site_identity_not_found');
        }
        if (! $identity->clientProject instanceof ClientProject
            || ! $identity->distributionChannel instanceof DistributionChannel
            || $this->projectStatus($identity->clientProject) !== ClientProjectStatus::ACTIVE->value
            || (string) $identity->distributionChannel->status !== DistributionChannel::STATUS_ACTIVE
            || ! $this->hasActiveMembership($identity->clientProject, $identity->distributionChannel)) {
            throw new ProjectSiteIdentityException('project_site_identity_not_found');
        }

        return $this->identityPayload($identity, $identity->clientProject);
    }

    /**
     * A deliberately read-only migration preflight. It classifies every current
     * channel endpoint without changing the database and highlights the exact
     * canonical collisions that must be resolved before provisioning identities.
     *
     * @return array{channels:int,eligible_channels:int,ready:int,legacy_unbound:int,invalid:list<array<string,mixed>>,unsupported:list<array<string,mixed>>,conflicts:list<array<string,mixed>>,bound:int,disabled:int}
     */
    public function conflictReport(): array
    {
        $rows = DistributionChannel::query()
            ->orderBy('id')
            ->get(['id', 'name', 'endpoint_url', 'channel_type', 'status']);
        $byCanonical = [];
        $invalid = [];
        $unsupported = [];
        foreach ($rows as $channel) {
            if (! $channel->isGeoFlowAgent()) {
                $unsupported[] = [
                    'channel_id' => (int) $channel->getKey(),
                    'code' => 'project_site_channel_type_unsupported',
                ];

                continue;
            }
            try {
                $canonicalUrl = self::canonicalUrl((string) $channel->endpoint_url);
            } catch (ProjectSiteIdentityException $exception) {
                $invalid[] = [
                    'channel_id' => (int) $channel->getKey(),
                    'code' => $exception->identityCode,
                ];

                continue;
            }
            $identity = self::canonicalIdentity($canonicalUrl);
            $byCanonical[$identity][] = [
                'channel_id' => (int) $channel->getKey(),
                'canonical_url' => $canonicalUrl,
            ];
        }

        $conflicts = [];
        foreach ($byCanonical as $canonicalIdentity => $channels) {
            if (count($channels) > 1) {
                $conflicts[] = [
                    'code' => 'project_site_identity_conflict',
                    'canonical_identity' => $canonicalIdentity,
                    'channels' => $channels,
                ];
            }
        }

        $bound = Schema::hasTable('project_channel_site_identities')
            ? ProjectChannelSiteIdentity::query()->count()
            : 0;
        $disabled = Schema::hasTable('project_channel_site_identities')
            ? ProjectChannelSiteIdentity::query()->where('status', ProjectChannelSiteIdentity::STATUS_DISABLED)->count()
            : 0;

        return [
            'channels' => $rows->count(),
            'eligible_channels' => $rows->count() - count($unsupported),
            'ready' => count($byCanonical),
            'legacy_unbound' => max(0, $rows->count() - count($unsupported) - $bound),
            'invalid' => $invalid,
            'unsupported' => $unsupported,
            'conflicts' => $conflicts,
            'bound' => $bound,
            'disabled' => $disabled,
        ];
    }

    /** @throws ProjectSiteIdentityException */
    public static function canonicalUrl(string $endpointUrl): string
    {
        $endpointUrl = trim($endpointUrl);
        if ($endpointUrl === '' || preg_match('/\s/', $endpointUrl) === 1) {
            throw new ProjectSiteIdentityException('project_site_canonical_url_invalid');
        }
        if (! str_contains($endpointUrl, '://')) {
            $endpointUrl = 'https://'.$endpointUrl;
        }

        $parts = parse_url($endpointUrl);
        if (! is_array($parts)) {
            throw new ProjectSiteIdentityException('project_site_canonical_url_invalid');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = rtrim(strtolower((string) ($parts['host'] ?? '')), '.');
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (! in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ($port !== null && ($port < 1 || $port > 65535))) {
            throw new ProjectSiteIdentityException('project_site_canonical_url_invalid');
        }

        $path = (string) ($parts['path'] ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new ProjectSiteIdentityException('project_site_canonical_url_invalid');
        }
        if ($segments !== [] && strtolower((string) end($segments)) === 'index.php') {
            array_pop($segments);
        }
        $normalizedPath = $segments === [] ? '' : '/'.implode('/', $segments);
        $authority = $host;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $authority .= ':'.$port;
        }

        return $scheme.'://'.$authority.$normalizedPath;
    }

    public static function canonicalIdentity(string $canonicalUrl): string
    {
        return self::IDENTITY_PREFIX.$canonicalUrl;
    }

    /** @throws ProjectSiteIdentityException */
    private function lockProject(ClientProject|int $project): ClientProject
    {
        $id = $project instanceof ClientProject ? (int) $project->getKey() : (int) $project;
        $locked = ClientProject::query()->whereKey($id)->lockForUpdate()->first();
        if (! $locked) {
            throw new ProjectSiteIdentityException('project_site_project_not_found');
        }

        return $locked;
    }

    /** @throws ProjectSiteIdentityException */
    private function lockChannel(DistributionChannel|int $channel): DistributionChannel
    {
        $id = $channel instanceof DistributionChannel ? (int) $channel->getKey() : (int) $channel;
        $locked = DistributionChannel::query()->whereKey($id)->lockForUpdate()->first();
        if (! $locked) {
            throw new ProjectSiteIdentityException('project_site_channel_not_found');
        }

        return $locked;
    }

    /** @throws ProjectSiteIdentityException */
    private function assertProjectChannelEligible(ClientProject $project, DistributionChannel $channel): void
    {
        if ($this->projectStatus($project) !== ClientProjectStatus::ACTIVE->value) {
            throw new ProjectSiteIdentityException('project_site_project_inactive');
        }
        if ((string) $channel->status !== DistributionChannel::STATUS_ACTIVE) {
            throw new ProjectSiteIdentityException('project_site_channel_inactive');
        }
        if (! $channel->isGeoFlowAgent()) {
            throw new ProjectSiteIdentityException('project_site_channel_type_unsupported');
        }
        if (! $this->hasActiveMembership($project, $channel)) {
            throw new ProjectSiteIdentityException('project_site_channel_membership_inactive');
        }
    }

    private function hasActiveMembership(ClientProject $project, DistributionChannel $channel): bool
    {
        return DB::table('client_project_distribution_channels')
            ->where('client_project_id', (int) $project->getKey())
            ->where('distribution_channel_id', (int) $channel->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->exists();
    }

    /** @throws ProjectSiteIdentityException */
    private function assertIdentityAvailable(string $canonicalIdentity, ?int $exceptIdentityId = null): void
    {
        $active = ProjectChannelSiteIdentity::query()
            ->where('canonical_identity', $canonicalIdentity)
            ->when($exceptIdentityId !== null, static fn ($query) => $query->where('id', '!=', $exceptIdentityId))
            ->exists();
        if ($active) {
            throw new ProjectSiteIdentityException('project_site_identity_conflict');
        }

        $history = ProjectChannelSiteIdentityHistory::query()
            ->where('canonical_identity', $canonicalIdentity)
            ->when($exceptIdentityId !== null, static fn ($query) => $query->where('project_channel_site_identity_id', '!=', $exceptIdentityId))
            ->exists();
        if ($history) {
            throw new ProjectSiteIdentityException('project_site_identity_historical_url_reserved');
        }
    }

    /** @throws ProjectSiteIdentityException */
    private function replaceCanonicalIdentityIfNeeded(ProjectChannelSiteIdentity $identity, string $canonicalUrl, string $canonicalIdentity): void
    {
        if ($identity->canonical_identity === $canonicalIdentity) {
            return;
        }

        $this->assertIdentityAvailable($canonicalIdentity, (int) $identity->getKey());
        ProjectChannelSiteIdentityHistory::query()->firstOrCreate(
            ['canonical_identity' => (string) $identity->canonical_identity],
            [
                'project_channel_site_identity_id' => (int) $identity->getKey(),
                'project_slug_snapshot' => (string) $identity->project_slug_snapshot,
                'canonical_url' => (string) $identity->canonical_url,
                'reason' => ProjectChannelSiteIdentityHistory::REASON_CANONICAL_CHANGED,
                'retired_at' => now(),
            ],
        );
        $identity->forceFill([
            'canonical_url' => $canonicalUrl,
            'canonical_identity' => $canonicalIdentity,
        ])->save();
    }

    private function disableLocked(ProjectChannelSiteIdentity $identity, string $reason): ProjectChannelSiteIdentity
    {
        ProjectChannelSiteIdentityHistory::query()->firstOrCreate(
            ['canonical_identity' => (string) $identity->canonical_identity],
            [
                'project_channel_site_identity_id' => (int) $identity->getKey(),
                'project_slug_snapshot' => (string) $identity->project_slug_snapshot,
                'canonical_url' => (string) $identity->canonical_url,
                'reason' => $reason,
                'retired_at' => now(),
            ],
        );
        $identity->forceFill([
            'status' => ProjectChannelSiteIdentity::STATUS_DISABLED,
            'disabled_at' => $identity->disabled_at ?? now(),
        ])->save();

        return $identity->fresh();
    }

    /**
     * @return array{version:string,status:string,canonical_url:string,canonical_identity:string,project_id:int,project_slug:string}
     */
    private function identityPayload(ProjectChannelSiteIdentity $identity, ?ClientProject $project = null): array
    {
        $project ??= $identity->clientProject;

        return [
            'version' => '1',
            'status' => (string) $identity->status,
            'canonical_url' => (string) $identity->canonical_url,
            'canonical_identity' => (string) $identity->canonical_identity,
            'project_id' => (int) $identity->client_project_id,
            'project_slug' => $project instanceof ClientProject ? (string) $project->slug : (string) $identity->project_slug_snapshot,
        ];
    }

    private function projectStatus(ClientProject $project): string
    {
        return (string) ($project->status?->value ?? $project->getRawOriginal('status'));
    }

    private function isUniqueConstraint(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? '');

        return $state === '23000' || $state === '23505' || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
