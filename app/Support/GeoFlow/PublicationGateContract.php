<?php

namespace App\Support\GeoFlow;

use App\Enums\PublicationGate;
use App\Models\ClientProject;
use InvalidArgumentException;

final class PublicationGateContract
{
    public const TARGET_LOCAL = 'local';

    public const TARGET_CHANNEL = 'channel';

    public const TARGET_MANUAL = 'manual';

    /** @return list<string> */
    public static function targets(): array
    {
        return [self::TARGET_LOCAL, self::TARGET_CHANNEL, self::TARGET_MANUAL];
    }

    /**
     * Evaluate a public transition without mutating the article or dispatching side effects.
     * Review approval and platform approval are intentionally separate inputs.
     *
     * @return array{allowed: bool, code: string, gate: string, target: string}
     */
    public static function evaluate(
        ?ClientProject $project,
        string $articleStatus,
        string $reviewStatus,
        string $target,
        bool $platformApproved = false,
        bool $centralSiteAllowed = false,
        bool $channelMembershipActive = true,
    ): array {
        if (! $project instanceof ClientProject) {
            throw new InvalidArgumentException('A client project context is required.');
        }

        $gate = $project->publication_gate;
        if (! $gate instanceof PublicationGate) {
            throw new InvalidArgumentException('Invalid publication gate.');
        }
        if (! in_array($target, self::targets(), true)) {
            throw new InvalidArgumentException('Invalid publication target.');
        }

        $base = ['allowed' => false, 'gate' => $gate->value, 'target' => $target];
        if ($project->status->value !== 'active') {
            return $base + ['code' => 'project_inactive'];
        }
        if (! in_array($articleStatus, ['draft', 'private'], true)) {
            return $base + ['code' => 'article_not_publishable'];
        }
        if (! in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            return $base + ['code' => 'review_not_approved'];
        }
        if ($target === self::TARGET_LOCAL && ! $centralSiteAllowed) {
            return $base + ['code' => 'central_site_not_allowed'];
        }
        if ($target === self::TARGET_CHANNEL && ! $channelMembershipActive) {
            return $base + ['code' => 'channel_membership_inactive'];
        }
        if ($gate === PublicationGate::PLATFORM_APPROVAL && ! $platformApproved) {
            return $base + ['code' => 'platform_approval_required'];
        }

        return array_merge($base, ['allowed' => true, 'code' => 'allowed']);
    }

    public static function allowsPublicTransition(
        ?ClientProject $project,
        string $articleStatus,
        string $reviewStatus,
        string $target,
        bool $platformApproved = false,
        bool $centralSiteAllowed = false,
        bool $channelMembershipActive = true,
    ): bool {
        return self::evaluate($project, $articleStatus, $reviewStatus, $target, $platformApproved, $centralSiteAllowed, $channelMembershipActive)['allowed'];
    }

    /** @return list<array<string, mixed>> */
    public static function stateMatrix(): array
    {
        $rows = [];
        foreach (PublicationGate::cases() as $gate) {
            foreach (['draft', 'private', 'published'] as $status) {
                foreach (['pending', 'approved', 'rejected', 'auto_approved'] as $review) {
                    foreach (self::targets() as $target) {
                        $rows[] = compact('gate', 'status', 'review', 'target');
                    }
                }
            }
        }

        return $rows;
    }
}
