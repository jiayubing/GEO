<?php

namespace App\Jobs;

use App\Enums\ClientProjectStatus;
use App\Models\ClientProject;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class GenerateEnterpriseKnowledgeDraftJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15];

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $projectId,
        public readonly ?int $adminId = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function tags(): array
    {
        return ['enterprise-knowledge', 'enterprise_knowledge_project:'.$this->projectId];
    }

    public function handle(EnterpriseKnowledgeDraftService $draftService): void
    {
        $generated = Cache::lock('enterprise-knowledge-draft:'.$this->projectId, $this->uniqueFor)
            ->get(function () use ($draftService): bool {
                $this->generate($draftService);

                return true;
            });

        if ($generated !== true) {
            throw new RuntimeException('enterprise_knowledge_draft_lock_unavailable');
        }
    }

    private function generate(EnterpriseKnowledgeDraftService $draftService): void
    {
        $project = EnterpriseKnowledgeProject::query()
            ->with('sources')
            ->whereKey($this->projectId)
            ->first();

        if (! $project) {
            return;
        }

        if (! $this->hasActiveOwner($project)) {
            $this->markUnavailable($project);

            return;
        }

        if (in_array((string) $project->status, ['reviewing', 'published'], true)
            && trim((string) $project->draft_content) !== '') {
            return;
        }

        try {
            $this->updateProgress($project, 'collecting', 20, __('admin.enterprise_knowledge.progress_message.collecting'));
            $this->updateProgress($project, 'cleaning', 35, __('admin.enterprise_knowledge.progress_message.cleaning'));
            $this->updateProgress($project, 'structuring', 58, __('admin.enterprise_knowledge.progress_message.structuring'));

            $freshProject = $project->fresh(['sources']) ?? $project;
            $draft = $draftService->generateDraft($freshProject);
            $content = trim((string) $draft['content']);

            $this->updateProgress($project, 'validating', 78, __('admin.enterprise_knowledge.progress_message.validating'));
            $validationItems = $draftService->validateDraft($content);

            $this->updateProgress($project, 'writing', 92, __('admin.enterprise_knowledge.progress_message.writing'));
            DB::transaction(function () use ($project, $content, $validationItems, $draft): void {
                $lockedProject = EnterpriseKnowledgeProject::query()->lockForUpdate()->find($project->getKey());
                if (! $lockedProject || ! $this->hasActiveOwner($lockedProject)) {
                    if ($lockedProject) {
                        $this->markUnavailable($lockedProject);
                    }

                    return;
                }
                if (in_array((string) $lockedProject->status, ['reviewing', 'published'], true)
                    && trim((string) $lockedProject->draft_content) !== '') {
                    return;
                }

                $lockedProject->forceFill([
                    'status' => 'reviewing',
                    'draft_content' => $content,
                    'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
                    'ai_model_id' => $draft['model_id'],
                    'error_message' => $draft['error'],
                    'structured_json' => $this->progressJson($lockedProject, 'completed', 100, __('admin.enterprise_knowledge.progress_message.completed')),
                ])->save();

                $this->recordRevision($lockedProject, $content, (string) $draft['source'], $draft['source'] === 'ai'
                    ? __('admin.enterprise_knowledge.revision_ai')
                    : __('admin.enterprise_knowledge.revision_fallback'));
            });
        } catch (Throwable $exception) {
            $this->markFailed($project, $exception);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $project = EnterpriseKnowledgeProject::query()->whereKey($this->projectId)->first();
        if (! $project || ! $exception) {
            return;
        }

        $this->markFailed($project, $exception);
    }

    private function updateProgress(EnterpriseKnowledgeProject $project, string $step, int $progress, string $message): void
    {
        $project->forceFill([
            'status' => 'processing',
            'structured_json' => $this->progressJson($project, $step, $progress, $message),
        ])->save();
    }

    private function markFailed(EnterpriseKnowledgeProject $project, Throwable $exception): void
    {
        if (in_array((string) $project->fresh()?->status, ['reviewing', 'published'], true)) {
            return;
        }

        logger()->error('Enterprise knowledge draft generation failed.', [
            'exception_class' => $exception::class,
            'enterprise_knowledge_project_id' => $project->getKey(),
            'client_project_id' => $project->client_project_id,
        ]);
        $message = 'enterprise_knowledge_draft_generation_failed';
        $project->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'structured_json' => $this->progressJson($project, 'failed', 100, __('admin.enterprise_knowledge.progress_message.failed', ['message' => $message])),
        ])->save();
    }

    private function progressJson(EnterpriseKnowledgeProject $project, string $step, int $progress, string $message): string
    {
        $data = $project->structuredData();
        $previous = is_array($data['draft_generation'] ?? null) ? $data['draft_generation'] : [];
        $startedAt = (string) ($previous['started_at'] ?? now()->toIso8601String());

        $data['draft_generation'] = [
            'step' => $step,
            'progress' => max(0, min(100, $progress)),
            'message' => $message,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function recordRevision(EnterpriseKnowledgeProject $project, string $content, string $source, string $summary): void
    {
        EnterpriseKnowledgeRevision::query()->firstOrCreate([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'source' => $source,
            'content_hash' => hash('sha256', $content),
        ], [
            'content' => $content,
            'summary' => $summary,
            'created_by_admin_id' => $this->adminId,
        ]);
    }

    private function hasActiveOwner(EnterpriseKnowledgeProject $project): bool
    {
        if ($project->client_project_id === null) {
            return ! ClientProject::query()->exists();
        }

        return ClientProject::query()
            ->whereKey((int) $project->client_project_id)
            ->where('status', ClientProjectStatus::ACTIVE->value)
            ->exists();
    }

    private function markUnavailable(EnterpriseKnowledgeProject $project): void
    {
        $project->forceFill([
            'status' => 'failed',
            'error_message' => $project->client_project_id === null
                ? 'enterprise_knowledge_project_owner_missing'
                : 'enterprise_knowledge_project_inactive',
        ])->save();
    }
}
