<?php

namespace App\Services\GeoFlow;

use App\Enums\PublicationBatchStatus;
use App\Enums\PublicationGate;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ClientProject;
use App\Models\PublicationBatch;
use App\Models\Task;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Coordinates task review outcomes with the existing publication-batch state owner. */
final class TaskPublicationBatchService
{
    public function __construct(
        private readonly PublicationBatchService $batches,
        private readonly ProjectOperationalAlertService $alerts,
    ) {}

    public function recordArticleReviewOutcome(int $articleId, ?int $actorId = null): void
    {
        $context = $this->contextForArticle($articleId);
        if ($context === null) {
            return;
        }

        [$project, $task] = $context;
        try {
            DB::transaction(function () use ($articleId, $actorId, $project, $task): void {
                $article = Article::query()->whereKey($articleId)->whereNull('deleted_at')->lockForUpdate()->first();
                $lockedTask = Task::query()
                    ->whereKey($task->getKey())
                    ->where('client_project_id', $project->getKey())
                    ->lockForUpdate()
                    ->first();
                if (! $article instanceof Article || ! $lockedTask instanceof Task || (int) $article->task_id !== (int) $lockedTask->getKey()) {
                    return;
                }

                $actor = $this->resolveActor($lockedTask, $actorId);
                $reviewStatus = (string) $article->review_status;
                if (in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
                    $this->batches->syncTaskDraftArticle($actor, $project, $lockedTask, $article, $this->targetsFor($lockedTask));
                } else {
                    $this->batches->removeTaskDraftArticle($actor, $project, $lockedTask, $article);
                }

                $this->submitIfReady($actor, $project, $lockedTask);
            });
            $this->alerts->resolve($project, $this->fingerprint($task));
        } catch (Throwable $exception) {
            $this->alerts->observe($project, 'publication_task_batch_failed', $this->fingerprint($task), [
                'task_id' => (int) $task->getKey(),
                'article_id' => $articleId,
                'error_code' => $this->errorCode($exception),
            ], 'error');
        }
    }

    public function finalizeTask(int $taskId, ?ClientProject $project = null, ?int $actorId = null): void
    {
        $task = Task::query()
            ->whereKey($taskId)
            ->when($project instanceof ClientProject, fn ($query) => $query->where('client_project_id', $project->getKey()))
            ->first();
        $project = $task?->clientProject;
        if (! $task instanceof Task || ! $project instanceof ClientProject || $project->publication_gate !== PublicationGate::PLATFORM_APPROVAL) {
            return;
        }

        Article::query()
            ->where('task_id', $task->getKey())
            ->where('client_project_id', $project->getKey())
            ->whereNull('deleted_at')
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $articleId) => $this->recordArticleReviewOutcome($articleId, $actorId));

        try {
            DB::transaction(function () use ($task, $project, $actorId): void {
                $lockedTask = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
                $actor = $this->resolveActor($lockedTask, $actorId);
                $this->submitIfReady($actor, $project, $lockedTask);
            });
            $this->alerts->resolve($project, $this->fingerprint($task));
        } catch (Throwable $exception) {
            $this->alerts->observe($project, 'publication_task_batch_failed', $this->fingerprint($task), [
                'task_id' => (int) $task->getKey(),
                'error_code' => $this->errorCode($exception),
            ], 'error');
        }
    }

    public function assertCanResume(int $taskId, ?ClientProject $project = null): void
    {
        $batch = PublicationBatch::query()
            ->where('task_id', $taskId)
            ->when($project instanceof ClientProject, fn ($query) => $query->where('client_project_id', $project->getKey()))
            ->first();
        if ($batch instanceof PublicationBatch && $batch->status !== PublicationBatchStatus::DRAFT) {
            throw new DomainException('publication_task_batch_finalized');
        }
    }

    /** @return array{0:ClientProject,1:Task}|null */
    private function contextForArticle(int $articleId): ?array
    {
        $article = Article::query()->with('task.clientProject')->whereKey($articleId)->whereNull('deleted_at')->first();
        $task = $article?->task;
        $project = $task?->clientProject;
        if (! $article instanceof Article || ! $task instanceof Task || ! $project instanceof ClientProject || $project->publication_gate !== PublicationGate::PLATFORM_APPROVAL) {
            return null;
        }

        return [$project, $task];
    }

    /** @return array<int,array<string,mixed>> */
    private function targetsFor(Task $task): array
    {
        $targets = [];
        $scope = (string) ($task->publish_scope ?? 'local_and_distribution');
        if ($scope !== 'distribution_only') {
            $targets[] = ['target_type' => 'local'];
        }
        if ($scope !== 'local_only') {
            foreach ($task->distributionChannels()->get(['distribution_channels.id']) as $channel) {
                $targets[] = ['target_type' => 'channel', 'channel_id' => (int) $channel->getKey()];
            }
        }

        return $targets;
    }

    private function submitIfReady(Admin $actor, ClientProject $project, Task $task): void
    {
        $terminalByLimit = (int) ($task->created_count ?? 0) >= max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 1));
        if ((string) $task->status !== 'paused' && ! $terminalByLimit) {
            return;
        }
        if ((string) $task->status !== 'paused') {
            $hasNonFinalArticle = Article::query()
                ->where('task_id', $task->getKey())
                ->where('client_project_id', $project->getKey())
                ->whereNull('deleted_at')
                ->whereNotIn('review_status', ['approved', 'auto_approved', 'rejected'])
                ->exists();
            if ($hasNonFinalArticle) {
                return;
            }
        }
        $batch = PublicationBatch::query()
            ->where('client_project_id', $project->getKey())
            ->where('task_id', $task->getKey())
            ->lockForUpdate()
            ->first();
        if (! $batch instanceof PublicationBatch || $batch->items()->doesntExist()) {
            return;
        }
        if ($batch->status === PublicationBatchStatus::DRAFT) {
            $this->batches->submit($actor, $batch);
        }
    }

    private function resolveActor(Task $task, ?int $actorId): Admin
    {
        $candidateIds = array_filter([(int) ($task->created_by_admin_id ?? 0), (int) $actorId]);
        foreach ($candidateIds as $candidateId) {
            $admin = Admin::query()->whereKey($candidateId)->where('status', 'active')->first();
            if ($admin instanceof Admin) {
                return $admin;
            }
        }

        throw new DomainException('publication_task_actor_required');
    }

    private function fingerprint(Task $task): string
    {
        return 'publication-task-batch:'.(int) $task->getKey();
    }

    private function errorCode(Throwable $exception): string
    {
        if ($exception instanceof DomainException && str_starts_with($exception->getMessage(), 'publication_')) {
            return mb_substr($exception->getMessage(), 0, 80);
        }

        return 'publication_task_batch_sync_failed';
    }
}
