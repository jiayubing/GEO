<?php

namespace App\Services\GeoFlow;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Exceptions\PublicationGateException;
use App\Models\Article;
use App\Models\ClientProject;
use App\Models\PublicationBatch;
use App\Models\PublicationBatchItem;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\PublicationGateContract;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Executes one approved, project-local publication item. */
final class PublicationBatchLocalItemExecutor
{
    public function __construct(
        private readonly PublicationBatchTargetResolver $targets,
        private readonly ArticleWorkflowTransitionService $workflow,
    ) {}

    public function execute(PublicationBatchItem $item): PublicationBatchItem
    {
        $claimed = DB::transaction(function () use ($item): PublicationBatchItem {
            $locked = PublicationBatchItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PublicationBatchItemStatus::APPROVED) {
                return $locked;
            }
            if ($locked->target_type?->value !== PublicationGateContract::TARGET_LOCAL) {
                return $this->failLocked($locked, 'publication_local_target_required', 'Only local targets are executable by this pilot.');
            }
            $project = ClientProject::query()->find($locked->client_project_id);
            $article = Article::query()->withTrashed()->find($locked->article_id);
            if (! $project instanceof ClientProject || $project->status?->value !== 'active') {
                return $this->failLocked($locked, 'publication_project_inactive', 'Project is not active.');
            }
            if (! $article instanceof Article) {
                return $this->failLocked($locked, 'publication_article_missing', 'Article is missing.');
            }
            try {
                $this->targets->assertFresh($locked);
                $gate = PublicationGateContract::evaluate($project, (string) $article->status, (string) $article->review_status, PublicationGateContract::TARGET_LOCAL, true, (bool) $article->central_site_allowed);
                if (! $gate['allowed']) {
                    throw new DomainException('publication_gate_'.$gate['code']);
                }
            } catch (Throwable $exception) {
                return $this->failLocked($locked, $this->failureCode($exception), $exception->getMessage());
            }
            $locked->forceFill(['status' => PublicationBatchItemStatus::PUBLISHING, 'started_at' => now(), 'updated_at' => now(), 'failure_code' => null, 'observation' => 'item_started'])->save();
            PublicationBatch::query()->whereKey($locked->publication_batch_id)->whereIn('status', [PublicationBatchStatus::APPROVED->value, PublicationBatchStatus::PARTIAL->value])->update(['status' => PublicationBatchStatus::PUBLISHING->value, 'status_changed_at' => now()]);

            return $locked;
        });

        if ($claimed->status !== PublicationBatchItemStatus::PUBLISHING) {
            return $claimed->fresh();
        }

        try {
            $article = Article::query()->findOrFail($claimed->article_id);
            $published = $this->workflow->transition($article, ArticleWorkflow::normalizeState('published', (string) $article->review_status), 'publication_batch_local_item', null, null, true, null, null, PublicationGateContract::TARGET_LOCAL, true);

            return DB::transaction(function () use ($claimed, $published): PublicationBatchItem {
                $locked = PublicationBatchItem::query()->whereKey($claimed->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status === PublicationBatchItemStatus::LOCAL_PUBLISHED) {
                    return $locked;
                }
                $locked->forceFill(['status' => PublicationBatchItemStatus::LOCAL_PUBLISHED, 'finished_at' => now(), 'result_snapshot' => ['status' => 'published', 'article_id' => $published->getKey(), 'published_at' => $published->published_at?->toIso8601String()], 'observation' => 'item_finished', 'updated_at' => now()])->save();
                $this->completeBatch($locked->publication_batch_id);

                return $locked;
            });
        } catch (Throwable $exception) {
            return DB::transaction(function () use ($claimed, $exception): PublicationBatchItem {
                $locked = PublicationBatchItem::query()->whereKey($claimed->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->status === PublicationBatchItemStatus::LOCAL_PUBLISHED) {
                    return $locked;
                }

                return $this->failLocked($locked, $this->failureCode($exception), $exception->getMessage());
            });
        }
    }

    private function failLocked(PublicationBatchItem $item, string $code, string $message): PublicationBatchItem
    {
        // Exception text may include credentials or request bodies; persist only stable codes.
        $item->forceFill(['status' => PublicationBatchItemStatus::FAILED, 'finished_at' => now(), 'failure_code' => $code, 'observation' => $code, 'result_snapshot' => ['outcome' => 'failed', 'failure_code' => $code], 'updated_at' => now()])->save();

        return $item;
    }

    private function completeBatch(int $batchId): void
    {
        $statuses = PublicationBatchItem::query()->where('publication_batch_id', $batchId)->pluck('status')->map(fn ($s) => $s instanceof PublicationBatchItemStatus ? $s->value : (string) $s);
        if ($statuses->isNotEmpty() && $statuses->every(fn (string $s): bool => in_array($s, [PublicationBatchItemStatus::LOCAL_PUBLISHED->value, PublicationBatchItemStatus::FAILED->value], true))) {
            PublicationBatch::query()->whereKey($batchId)->update(['status' => $statuses->contains(PublicationBatchItemStatus::FAILED->value) ? PublicationBatchStatus::PARTIAL->value : PublicationBatchStatus::COMPLETED->value, 'completed_at' => now(), 'status_changed_at' => now()]);
        }
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof PublicationGateException) {
            return 'publication_gate_'.$exception->gateCode;
        }

        return str_starts_with($exception->getMessage(), 'publication_') ? (string) $exception->getMessage() : 'publication_local_execution_failed';
    }
}
