<?php

namespace App\Services\GeoFlow;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Enums\PublicationTargetType;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\PublicationBatch;
use App\Models\PublicationBatchItem;
use Illuminate\Support\Facades\DB;

/** Batch-level execution, recovery, readback and deterministic aggregation. */
final class PublicationBatchRecoveryService
{
    public function __construct(
        private readonly PublicationBatchLocalItemExecutor $local,
        private readonly PublicationBatchChannelItemExecutor $channel,
        private readonly PublicationBatchManualItemExecutor $manual,
    ) {}

    /** Execute every approved item once; uncertain items are deliberately skipped. */
    public function execute(PublicationBatch $batch): PublicationBatch
    {
        $batch->loadMissing('items');
        foreach ($batch->items as $item) {
            if ($item->status !== PublicationBatchItemStatus::APPROVED) {
                continue;
            }
            match ($item->target_type) {
                PublicationTargetType::LOCAL => $this->local->execute($item),
                PublicationTargetType::CHANNEL => $this->channel->execute($item),
                PublicationTargetType::MANUAL => $this->manual->execute($item),
                default => null,
            };
        }

        return $this->aggregate($batch);
    }

    public function executeBatch(PublicationBatch $batch): PublicationBatch
    {
        return $this->execute($batch);
    }

    /** Read back observations owned by channel/manual systems and reconcile local restarts. */
    public function readback(PublicationBatch $batch): PublicationBatch
    {
        $batch->loadMissing('items');
        foreach ($batch->items as $item) {
            if ($item->target_type === PublicationTargetType::MANUAL && $item->manual_publication_id !== null) {
                $this->manual->readback($item);

                continue;
            }
            if ($item->target_type === PublicationTargetType::CHANNEL && $item->article_distribution_id !== null) {
                $this->readbackChannel($item);

                continue;
            }
            if ($item->target_type === PublicationTargetType::LOCAL && $item->status === PublicationBatchItemStatus::PUBLISHING) {
                $article = Article::query()->withTrashed()->find($item->article_id);
                if ($article?->status === 'published') {
                    $this->mark($item, PublicationBatchItemStatus::LOCAL_PUBLISHED, 'local_readback_confirmed');
                }
            }
        }

        return $this->aggregate($batch);
    }

    /** Alias used by scheduled recovery/reconcile callers. */
    public function reconcile(PublicationBatch $batch): PublicationBatch
    {
        return $this->readback($batch);
    }

    public function recover(PublicationBatch $batch): PublicationBatch
    {
        return $this->readback($batch);
    }

    public function readbackBatch(PublicationBatch $batch): PublicationBatch
    {
        return $this->readback($batch);
    }

    public function reconcileBatch(PublicationBatch $batch): PublicationBatch
    {
        return $this->reconcile($batch);
    }

    /** Return a stable, secret-free project × batch × target outcome matrix. */
    public function outcomeMatrix(PublicationBatch $batch): array
    {
        return $batch->items()->get()->map(static fn (PublicationBatchItem $item): array => [
            'project_id' => (int) $item->client_project_id,
            'batch_id' => (int) $item->publication_batch_id,
            'item_id' => (int) $item->getKey(),
            'article_id' => (int) $item->article_id,
            'target' => (string) $item->target_type->value,
            'target_identity' => (string) $item->target_identity,
            'status' => (string) $item->status->value,
            'outcome' => (string) (($item->result_snapshot['outcome'] ?? null) ?: $item->status->value),
            'failure_code' => $item->failure_code,
            'observation' => $item->observation,
        ])->all();
    }

    public function aggregate(PublicationBatch $batch): PublicationBatch
    {
        return DB::transaction(function () use ($batch): PublicationBatch {
            $locked = PublicationBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $statuses = $locked->items()->pluck('status')->map(fn ($s) => $s instanceof PublicationBatchItemStatus ? $s->value : (string) $s);
            if ($statuses->isEmpty()) {
                return $locked->fresh(['items']);
            }
            $active = [PublicationBatchItemStatus::PENDING->value, PublicationBatchItemStatus::APPROVED->value, PublicationBatchItemStatus::PUBLISHING->value];
            if ($statuses->contains(fn (string $s): bool => in_array($s, $active, true))) {
                return $locked->fresh(['items']);
            }
            $uncertain = $statuses->contains(PublicationBatchItemStatus::UNCERTAIN->value);
            $failed = $statuses->contains(PublicationBatchItemStatus::FAILED->value);
            $rejected = $statuses->contains(PublicationBatchItemStatus::REJECTED->value);
            $success = $statuses->contains(fn (string $s): bool => in_array($s, [PublicationBatchItemStatus::LOCAL_PUBLISHED->value, PublicationBatchItemStatus::REMOTE_SYNCED->value, PublicationBatchItemStatus::MANUAL_READY->value, PublicationBatchItemStatus::COMPLETED->value], true));
            $status = $uncertain ? PublicationBatchStatus::UNCERTAIN : (($failed || $rejected) && $success ? PublicationBatchStatus::PARTIAL : (($failed || $rejected) ? PublicationBatchStatus::FAILED : PublicationBatchStatus::COMPLETED));
            $locked->forceFill(['status' => $status, 'completed_at' => now(), 'status_changed_at' => now()])->save();

            return $locked->fresh(['items']);
        });
    }

    public function aggregateBatch(PublicationBatch $batch): PublicationBatch
    {
        return $this->aggregate($batch);
    }

    private function readbackChannel(PublicationBatchItem $item): void
    {
        $distribution = ArticleDistribution::query()->find($item->article_distribution_id);
        if (! $distribution) {
            return;
        }
        $status = (string) $distribution->status;
        if ($status === 'synced') {
            $this->mark($item, PublicationBatchItemStatus::REMOTE_SYNCED, 'channel_readback_confirmed');
        } elseif (in_array($status, ['failed', 'cancelled'], true)) {
            $this->mark($item, PublicationBatchItemStatus::FAILED, 'channel_readback_failed');
        }
    }

    private function mark(PublicationBatchItem $item, PublicationBatchItemStatus $status, string $observation): void
    {
        DB::transaction(function () use ($item, $status, $observation): void {
            $locked = PublicationBatchItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === $status) {
                return;
            }
            if ($locked->status !== PublicationBatchItemStatus::PUBLISHING && $locked->status !== PublicationBatchItemStatus::UNCERTAIN) {
                return;
            }
            $locked->forceFill(['status' => $status, 'finished_at' => now(), 'observation' => $observation, 'result_snapshot' => ['outcome' => $status === PublicationBatchItemStatus::FAILED ? 'failed' : 'success']])->save();
        });
    }
}
