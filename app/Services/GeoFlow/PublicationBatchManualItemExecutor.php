<?php

namespace App\Services\GeoFlow;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ClientProject;
use App\Models\ManualPublication;
use App\Models\PublicationBatch;
use App\Models\PublicationBatchItem;
use App\Support\GeoFlow\PublicationGateContract;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Bridges an approved manual item to ManualPublicationService without owning its state machine. */
final class PublicationBatchManualItemExecutor
{
    public function __construct(
        private readonly PublicationBatchTargetResolver $targets,
        private readonly ManualPublicationService $manualPublications,
    ) {}

    public function execute(PublicationBatchItem $item): PublicationBatchItem
    {
        return DB::transaction(function () use ($item): PublicationBatchItem {
            $locked = PublicationBatchItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->target_type?->value !== 'manual') {
                return $this->fail($locked, 'publication_manual_target_required');
            }
            if ($locked->manual_publication_id !== null) {
                return $this->readbackLocked($locked, ManualPublication::query()->find($locked->manual_publication_id));
            }
            if ($locked->status !== PublicationBatchItemStatus::APPROVED) {
                return $locked;
            }

            $project = ClientProject::query()->find($locked->client_project_id);
            $article = Article::query()->withTrashed()->find($locked->article_id);
            if (! $project instanceof ClientProject || (string) ($project->status?->value ?? $project->getRawOriginal('status')) !== 'active') {
                return $this->fail($locked, 'publication_project_inactive');
            }
            if (! $article instanceof Article || (int) $article->client_project_id !== (int) $project->getKey()) {
                return $this->fail($locked, 'publication_article_missing');
            }

            try {
                $this->targets->assertFresh($locked);
                $gate = PublicationGateContract::evaluate($project, (string) $article->status, (string) $article->review_status, PublicationGateContract::TARGET_MANUAL, true, (bool) $article->central_site_allowed);
                if (! $gate['allowed']) {
                    throw new DomainException('publication_gate_'.$gate['code']);
                }
                $snapshot = (array) $locked->target_snapshot;
                $creator = Admin::query()->find((int) $locked->created_by_admin_id);
                if (! $creator instanceof Admin) {
                    throw new DomainException('publication_manual_creator_missing');
                }
                $publication = $this->manualPublications->create([
                    'type' => ManualPublication::TYPE_POST,
                    'article_id' => $article->getKey(),
                    'persona_id' => (int) ($snapshot['persona_id'] ?? 0),
                    'account_id' => ! empty($snapshot['account_id']) ? (int) $snapshot['account_id'] : null,
                    'platform' => (string) ($snapshot['platform'] ?? ''),
                    'assigned_admin_id' => (int) ($snapshot['assigned_admin_id'] ?? $locked->created_by_admin_id),
                    'content' => (string) $article->content,
                    'status' => ManualPublication::STATUS_READY,
                    'client_project_id' => $project->getKey(),
                ], $creator);
            } catch (Throwable $exception) {
                return $this->fail($locked, $this->code($exception));
            }

            $locked->forceFill([
                'manual_publication_id' => $publication->getKey(),
                'status' => PublicationBatchItemStatus::MANUAL_READY,
                'started_at' => now(),
                'observation' => 'manual_publication_created',
                'result_snapshot' => ['manual_publication_id' => $publication->getKey(), 'status' => $publication->status],
            ])->save();
            PublicationBatch::query()->whereKey($locked->publication_batch_id)->whereIn('status', [PublicationBatchStatus::APPROVED->value, PublicationBatchStatus::PARTIAL->value])->update(['status' => PublicationBatchStatus::PUBLISHING->value, 'status_changed_at' => now()]);

            return $locked->fresh();
        });
    }

    public function readback(PublicationBatchItem $item, ?string $statusOverride = null, ?string $observation = null): PublicationBatchItem
    {
        return DB::transaction(function () use ($item, $statusOverride, $observation): PublicationBatchItem {
            $locked = PublicationBatchItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();

            return $this->readbackLocked($locked, ManualPublication::query()->find($locked->manual_publication_id), $statusOverride, $observation);
        });
    }

    /** Alias for callback/reconcile callers. */
    public function syncFromPublication(PublicationBatchItem $item): PublicationBatchItem
    {
        return $this->readback($item);
    }

    private function readbackLocked(PublicationBatchItem $item, ?ManualPublication $publication, ?string $statusOverride = null, ?string $observation = null): PublicationBatchItem
    {
        if (! $publication instanceof ManualPublication && $statusOverride === null) {
            return $item;
        }
        $status = $statusOverride !== null ? trim($statusOverride) : (string) $publication->status;
        $mapped = match ($status) {
            ManualPublication::STATUS_READY => PublicationBatchItemStatus::MANUAL_READY,
            ManualPublication::STATUS_IN_PROGRESS => PublicationBatchItemStatus::PUBLISHING,
            ManualPublication::STATUS_COMPLETED => PublicationBatchItemStatus::COMPLETED,
            ManualPublication::STATUS_FAILED, ManualPublication::STATUS_CANCELLED, ManualPublication::STATUS_SKIPPED => PublicationBatchItemStatus::FAILED,
            'uncertain' => PublicationBatchItemStatus::UNCERTAIN,
            default => $item->status,
        };
        $item->forceFill([
            'status' => $mapped,
            'finished_at' => in_array($mapped, [PublicationBatchItemStatus::COMPLETED, PublicationBatchItemStatus::FAILED], true) ? now() : $item->finished_at,
            'observation' => $observation ?: 'manual_publication_'.$status,
            // Operator notes can contain arbitrary sensitive text; snapshots stay metadata-only.
            'result_snapshot' => ['manual_publication_id' => $publication?->getKey(), 'status' => $status],
        ])->save();
        $this->aggregateBatch($item->publication_batch_id);

        return $item->fresh();
    }

    private function fail(PublicationBatchItem $item, string $code): PublicationBatchItem
    {
        $item->forceFill(['status' => PublicationBatchItemStatus::FAILED, 'finished_at' => now(), 'failure_code' => $code, 'observation' => $code, 'result_snapshot' => ['outcome' => 'failed', 'failure_code' => $code]])->save();
        $this->aggregateBatch($item->publication_batch_id);

        return $item;
    }

    private function aggregateBatch(int $batchId): void
    {
        $statuses = PublicationBatchItem::query()->where('publication_batch_id', $batchId)->pluck('status')->map(fn ($s) => $s instanceof PublicationBatchItemStatus ? $s->value : (string) $s);
        $terminal = [PublicationBatchItemStatus::LOCAL_PUBLISHED->value, PublicationBatchItemStatus::REMOTE_SYNCED->value, PublicationBatchItemStatus::MANUAL_READY->value, PublicationBatchItemStatus::COMPLETED->value, PublicationBatchItemStatus::FAILED->value, PublicationBatchItemStatus::UNCERTAIN->value];
        if ($statuses->isEmpty() || $statuses->contains(fn (string $s): bool => ! in_array($s, $terminal, true))) {
            return;
        }
        $status = $statuses->contains(PublicationBatchItemStatus::UNCERTAIN->value) ? PublicationBatchStatus::UNCERTAIN : ($statuses->contains(PublicationBatchItemStatus::FAILED->value) ? PublicationBatchStatus::PARTIAL : PublicationBatchStatus::COMPLETED);
        PublicationBatch::query()->whereKey($batchId)->update(['status' => $status->value, 'completed_at' => now(), 'status_changed_at' => now()]);
    }

    private function code(Throwable $exception): string
    {
        return $exception instanceof DomainException && str_starts_with($exception->getMessage(), 'publication_') ? $exception->getMessage() : 'publication_manual_execution_failed';
    }
}
