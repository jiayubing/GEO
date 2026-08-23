<?php

namespace App\Services\GeoFlow;

use App\Enums\PublicationTargetType;
use App\Models\Article;
use App\Models\ClientProject;
use App\Models\ClientProjectDistributionChannel;
use App\Models\DistributionChannel;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\PublicationBatchItem;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Resolves and freezes the inputs for a publication batch item.
 *
 * This service deliberately has no execution or external side effects.  It is
 * the single boundary that turns operator-selected target references into a
 * project-owned, replayable item contract.
 */
final class PublicationBatchTargetResolver
{
    /** @param ClientProject|int $project @param Article|int $article @param array<string,mixed>|string $target */
    public function resolve(ClientProject|int $project, Article|int $article, array|string $target, string $action = 'publish'): array
    {
        $project = $project instanceof ClientProject
            ? $project->fresh()
            : ClientProject::query()->find((int) $project);
        $projectStatus = $project instanceof ClientProject ? (string) ($project->status?->value ?? $project->getRawOriginal('status')) : '';
        if (! $project instanceof ClientProject || $projectStatus !== 'active') {
            throw new DomainException('publication_project_inactive');
        }

        $article = $article instanceof Article
            ? $article->fresh(['task'])
            : Article::query()->with('task')->find((int) $article);
        if (! $article instanceof Article || (int) $article->client_project_id !== (int) $project->getKey()) {
            throw new DomainException('publication_article_project_mismatch');
        }
        if ($article->task_id !== null && (! $article->task || (int) $article->task->client_project_id !== (int) $project->getKey())) {
            throw new DomainException('publication_task_project_mismatch');
        }

        $action = trim($action);
        if ($action === '') {
            throw new DomainException('publication_action_required');
        }
        $target = is_string($target) ? ['target_type' => $target] : $target;
        $type = PublicationTargetType::tryFrom((string) ($target['target_type'] ?? $target['type'] ?? ''));
        if (! $type) {
            throw new DomainException('publication_target_invalid');
        }

        [$identity, $snapshot] = match ($type) {
            PublicationTargetType::LOCAL => $this->local($project),
            PublicationTargetType::CHANNEL => $this->channel($project, $target),
            PublicationTargetType::MANUAL => $this->manual($target),
        };

        $revision = $this->articleRevision($article);
        // Keep article content out of the frozen snapshot; only its digest is
        // needed to detect edits between submission and execution.
        $hash = hash('sha256', (string) $article->content);
        $idempotency = 'publication-v1-'.hash('sha256', implode("\0", [
            (int) $project->getKey(), (int) $article->getKey(), $revision, $hash,
            $type->value, $identity, $action,
        ]));

        return [
            'client_project_id' => (int) $project->getKey(),
            'article_id' => (int) $article->getKey(),
            'target_type' => $type->value,
            'target_identity' => $identity,
            'action' => $action,
            'article_revision' => $revision,
            'article_content_hash' => $hash,
            'target_snapshot' => $snapshot,
            'idempotency_key' => $idempotency,
        ];
    }

    /** Alias used by callers that describe this operation as freezing. */
    public function freeze(ClientProject|int $project, Article|int $article, array|string $target, string $action = 'publish'): array
    {
        return $this->resolve($project, $article, $target, $action);
    }

    public function isStale(PublicationBatchItem $item): bool
    {
        try {
            $current = $this->resolve((int) $item->client_project_id, (int) $item->article_id, [
                'target_type' => (string) $item->target_type->value,
                'channel_id' => $item->target_type->value === 'channel' ? (int) ($item->target_snapshot['channel_id'] ?? 0) : null,
                'persona_id' => $item->target_type->value === 'manual' ? (int) ($item->target_snapshot['persona_id'] ?? 0) : null,
                'account_id' => $item->target_type->value === 'manual' ? ($item->target_snapshot['account_id'] ?? null) : null,
            ], (string) $item->action);
        } catch (DomainException) {
            return true;
        }

        return $current['article_revision'] !== (int) $item->article_revision
            || ! hash_equals((string) $current['article_content_hash'], (string) $item->article_content_hash)
            || $current['target_identity'] !== (string) $item->target_identity
            || $current['target_snapshot'] !== (array) $item->target_snapshot;
    }

    public function assertFresh(PublicationBatchItem $item): void
    {
        if ($this->isStale($item)) {
            throw new DomainException('publication_item_stale');
        }
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function local(ClientProject $project): array
    {
        return ['local:project:'.(int) $project->getKey(), [
            'target_type' => 'local', 'project_id' => (int) $project->getKey(), 'project_slug' => (string) $project->slug,
            'configuration_version' => (string) ($project->updated_at?->toAtomString() ?? ''),
        ]];
    }

    /** @param array<string,mixed> $target @return array{0:string,1:array<string,mixed>} */
    private function channel(ClientProject $project, array $target): array
    {
        $id = (int) ($target['channel_id'] ?? $target['distribution_channel_id'] ?? 0);
        $membership = ClientProjectDistributionChannel::query()->with('channel')
            ->where('client_project_id', $project->getKey())->where('distribution_channel_id', $id)
            ->where('status', 'active')->whereNull('revoked_at')->first();
        $channel = $membership?->channel;
        if (! $membership || ! $channel instanceof DistributionChannel || $channel->status !== DistributionChannel::STATUS_ACTIVE) {
            throw new DomainException('publication_channel_not_member');
        }
        if (trim((string) $channel->endpoint_url) === '' || trim((string) $channel->channelType()) === '') {
            throw new DomainException('publication_channel_not_configured');
        }
        $cap = $channel->frontendCapabilitiesCache();
        $configVersion = hash('sha256', json_encode([
            'updated_at' => $channel->updated_at?->toAtomString(), 'channel_type' => $channel->channelType(),
            'capability_version' => $cap['capability_version'] ?? '', 'package_version' => $cap['package_version'] ?? '',
        ], JSON_THROW_ON_ERROR));

        return ['channel:'.$id, [
            'target_type' => 'channel', 'channel_id' => $id, 'channel_type' => $channel->channelType(),
            'domain' => (string) $channel->domain, 'capability_version' => (string) ($cap['capability_version'] ?? ''),
            'package_version' => (string) ($cap['package_version'] ?? ''), 'configuration_version' => $configVersion,
        ]];
    }

    /** @param array<string,mixed> $target @return array{0:string,1:array<string,mixed>} */
    private function manual(array $target): array
    {
        $persona = ManualPublicationPersona::query()->whereKey((int) ($target['persona_id'] ?? 0))->where('is_active', true)->first();
        if (! $persona instanceof ManualPublicationPersona) {
            throw new DomainException('publication_manual_persona_inactive');
        }
        $account = null;
        if (! empty($target['account_id'])) {
            $account = ManualPublicationAccount::query()->whereKey((int) $target['account_id'])->where('is_active', true)->first();
            if (! $account || (int) $account->persona_id !== (int) $persona->getKey()) {
                throw new DomainException('publication_manual_account_invalid');
            }
        }
        $platform = (string) ($account?->platform ?? $target['platform'] ?? '');
        if ($platform === '') {
            throw new DomainException('publication_manual_platform_required');
        }
        $identity = 'manual:persona:'.(int) $persona->getKey().':account:'.(int) ($account?->getKey() ?? 0).':'.$platform;

        return [$identity, [
            'target_type' => 'manual', 'persona_id' => (int) $persona->getKey(), 'account_id' => $account?->getKey(),
            'platform' => $platform, 'configuration_version' => hash('sha256', (string) ($persona->updated_at?->toAtomString().'|'.$account?->updated_at?->toAtomString())),
        ]];
    }

    private function articleRevision(Article $article): int
    {
        if (isset($article->revision) && is_numeric($article->revision)) {
            return max(1, (int) $article->revision);
        }

        return max(1, $article->updated_at instanceof Carbon ? $article->updated_at->getTimestamp() : 1);
    }
}
