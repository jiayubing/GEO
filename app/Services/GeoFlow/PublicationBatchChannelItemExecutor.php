<?php

namespace App\Services\GeoFlow;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Enums\PublicationTargetType;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\ClientProject;
use App\Models\PublicationBatch;
use App\Models\PublicationBatchItem;
use App\Support\GeoFlow\PublicationGateContract;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Executes one approved channel item through the existing distribution owner. */
final class PublicationBatchChannelItemExecutor
{
    public function __construct(
        private readonly PublicationBatchTargetResolver $targets,
        private readonly DistributionOrchestrator $orchestrator,
    ) {}

    public function execute(PublicationBatchItem $item): PublicationBatchItem
    {
        $claimed = DB::transaction(function () use ($item): PublicationBatchItem {
            $locked = PublicationBatchItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PublicationBatchItemStatus::APPROVED) {
                return $locked;
            }
            if ($locked->target_type !== PublicationTargetType::CHANNEL) {
                return $this->fail($locked, 'publication_channel_target_required');
            }

            $project = ClientProject::query()->find($locked->client_project_id);
            $article = Article::query()->withTrashed()->find($locked->article_id);
            if (! $project instanceof ClientProject || (string) ($project->status?->value ?? $project->getRawOriginal('status')) !== 'active') {
                return $this->fail($locked, 'publication_project_inactive');
            }
            if (! $article instanceof Article) {
                return $this->fail($locked, 'publication_article_missing');
            }

            try {
                $this->targets->assertFresh($locked);
                $gate = PublicationGateContract::evaluate($project, (string) $article->status, (string) $article->review_status, PublicationGateContract::TARGET_CHANNEL, true);
                if (! $gate['allowed']) {
                    throw new DomainException('publication_gate_'.$gate['code']);
                }
            } catch (Throwable $exception) {
                return $this->fail($locked, $this->code($exception));
            }

            $channelId = (int) ($locked->target_snapshot['channel_id'] ?? 0);
            $distribution = ArticleDistribution::query()->where('idempotency_key', (string) $locked->idempotency_key)->first();
            if (! $distribution) {
                $conflict = ArticleDistribution::query()->where([
                    'article_id' => (int) $locked->article_id,
                    'distribution_channel_id' => $channelId,
                    'action' => (string) $locked->action,
                ])->first();
                if ($conflict && (string) $conflict->idempotency_key !== (string) $locked->idempotency_key) {
                    return $this->fail($locked, 'publication_distribution_identity_conflict');
                }
                $distribution = $conflict ?: ArticleDistribution::query()->create([
                    'article_id' => (int) $locked->article_id,
                    'distribution_channel_id' => $channelId,
                    'action' => (string) $locked->action,
                    'status' => 'queued',
                    'idempotency_key' => (string) $locked->idempotency_key,
                    'attempt_count' => 0,
                ]);
            }
            $locked->forceFill([
                'status' => PublicationBatchItemStatus::PUBLISHING,
                'article_distribution_id' => $distribution->getKey(),
                'started_at' => now(),
                'failure_code' => null,
                'observation' => 'remote_request_pending',
            ])->save();
            PublicationBatch::query()->whereKey($locked->publication_batch_id)->whereIn('status', [PublicationBatchStatus::APPROVED->value, PublicationBatchStatus::PARTIAL->value])->update(['status' => PublicationBatchStatus::PUBLISHING->value, 'status_changed_at' => now()]);

            return $locked;
        });

        if ($claimed->status !== PublicationBatchItemStatus::PUBLISHING) {
            return $claimed->fresh();
        }

        try {
            $distribution = ArticleDistribution::query()->findOrFail($claimed->article_distribution_id);
            if ((string) $distribution->status === 'synced') {
                return $this->finish($claimed, PublicationBatchItemStatus::REMOTE_SYNCED, null, [
                    'outcome' => 'success', 'article_distribution_id' => $distribution->getKey(), 'observation' => 'already_synced',
                ]);
            }
            $ok = $this->orchestrator->process($distribution);
            if (! $ok) {
                return $this->finish($claimed, PublicationBatchItemStatus::FAILED, 'publication_distribution_not_processed');
            }

            return $this->finish($claimed, PublicationBatchItemStatus::REMOTE_SYNCED, null, [
                'outcome' => 'success', 'article_distribution_id' => $distribution->getKey(),
            ]);
        } catch (Throwable $exception) {
            return $this->finish($claimed, $this->isUncertain($exception) ? PublicationBatchItemStatus::UNCERTAIN : PublicationBatchItemStatus::FAILED, $this->code($exception));
        }
    }

    private function finish(PublicationBatchItem $item, PublicationBatchItemStatus $status, ?string $code, array $result = []): PublicationBatchItem
    {
        return DB::transaction(function () use ($item, $status, $code, $result): PublicationBatchItem {
            $locked = PublicationBatchItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PublicationBatchItemStatus::PUBLISHING) {
                return $locked;
            }
            $locked->forceFill(['status' => $status, 'finished_at' => now(), 'failure_code' => $code, 'observation' => $status === PublicationBatchItemStatus::UNCERTAIN ? 'remote_result_uncertain' : 'remote_request_finished', 'result_snapshot' => $result + ['outcome' => $status === PublicationBatchItemStatus::REMOTE_SYNCED ? 'success' : ($status === PublicationBatchItemStatus::UNCERTAIN ? 'uncertain' : 'failed')]])->save();
            $this->aggregateBatch($locked->publication_batch_id);

            return $locked;
        });
    }

    private function fail(PublicationBatchItem $item, string $code): PublicationBatchItem
    {
        return $this->finish($item, PublicationBatchItemStatus::FAILED, $code);
    }

    private function aggregateBatch(int $batchId): void
    {
        $statuses = PublicationBatchItem::query()->where('publication_batch_id', $batchId)->pluck('status')->map(fn ($s) => $s instanceof PublicationBatchItemStatus ? $s->value : (string) $s);
        $terminal = [PublicationBatchItemStatus::LOCAL_PUBLISHED->value, PublicationBatchItemStatus::REMOTE_SYNCED->value, PublicationBatchItemStatus::MANUAL_READY->value, PublicationBatchItemStatus::COMPLETED->value, PublicationBatchItemStatus::FAILED->value, PublicationBatchItemStatus::UNCERTAIN->value];
        if ($statuses->isEmpty() || $statuses->contains(fn (string $s) => ! in_array($s, $terminal, true))) {
            return;
        }
        $status = $statuses->contains(PublicationBatchItemStatus::UNCERTAIN->value) ? PublicationBatchStatus::UNCERTAIN : ($statuses->contains(PublicationBatchItemStatus::FAILED->value) ? PublicationBatchStatus::PARTIAL : PublicationBatchStatus::COMPLETED);
        PublicationBatch::query()->whereKey($batchId)->update(['status' => $status->value, 'completed_at' => now(), 'status_changed_at' => now()]);
    }

    private function code(Throwable $exception): string
    {
        return $exception instanceof DomainException && str_starts_with($exception->getMessage(), 'publication_') ? $exception->getMessage() : 'publication_channel_execution_failed';
    }

    private function isUncertain(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }
        if ($exception instanceof RequestException) {
            return $exception->response?->status() === null;
        }

        return ! preg_match('/HTTP\s+(4\d\d|5\d\d)/i', $exception->getMessage());
    }
}
