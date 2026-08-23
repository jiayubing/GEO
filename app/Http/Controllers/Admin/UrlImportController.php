<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\ClientProject;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\ProjectAccessService;
use App\Services\GeoFlow\UrlImportJobCreationService;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UrlImportController extends Controller
{
    public function __construct(
        private readonly UrlImportProcessingService $urlImportProcessingService,
        private readonly UrlImportJobCreationService $urlImportJobs,
        private readonly ProjectAccessService $projectAccess,
    ) {}

    public function index(Request $request): View
    {
        $project = $this->projectContext($request);

        return view('admin.url-import.index', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'stats' => $this->loadStats($project),
            'aiModelReady' => $this->urlImportProcessingService->hasReadyAnalysisModel(),
            'aiModelConfigUrl' => route('admin.ai-models.index'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = $this->projectContext($request, true);
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'project_name' => ['nullable', 'string', 'max:120'],
            'source_label' => ['nullable', 'string', 'max:120'],
            'content_language' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'outputs' => ['array'],
            'outputs.*' => ['string', 'in:knowledge,keywords,titles'],
        ]);

        try {
            $normalized = $this->urlImportProcessingService->normalizeInputUrl((string) $validated['url']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['url' => $exception->getMessage()]);
        }

        $duplicate = $this->urlImportJobs->findDuplicate($project, $normalized['url']);
        if ($duplicate !== null) {
            return redirect()->route('admin.url-import.show', ['jobId' => $duplicate->id]);
        }

        try {
            $this->urlImportProcessingService->assertAnalysisModelReady();
        } catch (\Throwable) {
            return redirect()
                ->route('admin.ai-models.index')
                ->withInput()
                ->withErrors(['ai_model' => __('admin.url_import.error.ai_model_required')]);
        }

        $creation = $this->urlImportJobs->create(
            $project,
            $normalized,
            $validated,
            Auth::guard('admin')->user()?->username ?? '',
        );

        return redirect()->route('admin.url-import.show', ['jobId' => $creation['job']->id]);
    }

    public function run(Request $request, int $jobId): JsonResponse
    {
        $project = $this->projectContext($request, true, true);
        $job = $this->jobForProject($jobId, $project);

        if (in_array($job->status, ['queued', 'failed'], true)) {
            try {
                $this->urlImportProcessingService->assertAnalysisModelReady();
            } catch (\Throwable $exception) {
                $errorCode = 'url_import_analysis_model_unavailable';
                $job->update([
                    'status' => 'failed',
                    'progress_percent' => max(1, (int) $job->progress_percent),
                    'error_message' => $errorCode,
                    'finished_at' => now(),
                ]);

                UrlImportJobLog::query()->create([
                    'job_id' => $job->id,
                    'step' => $job->current_step ?: 'queued',
                    'level' => 'error',
                    'message' => __('admin.url_import.log.failed', ['message' => $errorCode]),
                ]);

                return response()->json($this->statusPayload($job->refresh()), 422);
            }

            if (app()->runningUnitTests()) {
                $job = $this->urlImportProcessingService->process($job);
            } else {
                if (! $this->spawnUrlImportWorker($job)) {
                    $job = $this->urlImportProcessingService->process($job->refresh());
                }
            }
        }

        return response()->json($this->statusPayload($job->refresh()));
    }

    public function status(Request $request, int $jobId): JsonResponse
    {
        $job = $this->jobForProject($jobId, $this->projectContext($request, false, true));

        return response()->json($this->statusPayload($job));
    }

    public function commit(Request $request, int $jobId): RedirectResponse
    {
        $legacyContext = $request->boolean('legacy');
        $job = $this->jobForProject($jobId, $this->projectContext($request, true, true));

        try {
            $summary = $this->urlImportProcessingService->commit($job);
        } catch (\Throwable $exception) {
            return back()->withErrors(__('admin.url_import.error.commit_failed').': '.$exception->getMessage());
        }

        return redirect()
            ->route('admin.url-import.show', ['jobId' => $jobId, 'legacy' => $legacyContext ?: null])
            ->with('message', __('admin.url_import.commit.success').'：'.__('admin.url_import_history.import.summary', [
                'knowledge_base' => $summary['knowledge_base'],
                'keywords' => $summary['keywords'],
                'titles' => $summary['titles'],
            ]));
    }

    public function show(Request $request, int $jobId): View
    {
        $legacyContext = $request->boolean('legacy');
        $job = $this->jobForProject($jobId, $this->projectContext($request, false, true));

        $job->load(['logs' => fn ($query) => $query->oldest()->limit(120)]);

        return view('admin.url-import.show', [
            'pageTitle' => __('admin.url_import.page_title'),
            'activeMenu' => 'materials',
            'job' => $job,
            'result' => $this->decodeJson((string) $job->result_json),
            'logs' => $job->logs,
            'legacyContext' => $legacyContext,
        ]);
    }

    public function history(Request $request): View
    {
        $legacyContext = $request->boolean('legacy');
        $jobs = $this->scopedJobs($this->projectContext($request, false, true));

        return view('admin.url-import.history', [
            'pageTitle' => __('admin.url_import_history.page_title'),
            'activeMenu' => 'materials',
            'jobs' => (clone $jobs)->latest()->paginate(20)->withQueryString(),
            'legacyContext' => $legacyContext,
            'stats' => [
                'total' => (clone $jobs)->count(),
                'completed' => (clone $jobs)->where('status', 'completed')->count(),
                'running' => (clone $jobs)->whereIn('status', ['queued', 'running'])->count(),
                'failed' => (clone $jobs)->where('status', 'failed')->count(),
            ],
        ]);
    }

    private function loadStats(?ClientProject $project): array
    {
        return [
            'knowledge_bases' => $this->scopedOwnerCount(KnowledgeBase::class, $project),
            'keyword_libraries' => $this->scopedOwnerCount(KeywordLibrary::class, $project),
            'title_libraries' => $this->scopedOwnerCount(TitleLibrary::class, $project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function spawnUrlImportWorker(UrlImportJob $job): bool
    {
        try {
            $arguments = [
                'jobId' => (int) $job->getKey(),
            ];
            if ($job->client_project_id === null) {
                $arguments['--legacy'] = true;
            } else {
                $arguments['--project'] = (int) $job->client_project_id;
            }
            Artisan::queue('geoflow:process-url-import', $arguments);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function projectContext(Request $request, bool $write = false, bool $allowLegacyCompatibility = false): ?ClientProject
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 401);

        if ($request->boolean('legacy')) {
            abort_unless($allowLegacyCompatibility && $admin->isSuperAdmin(), 403, 'legacy_url_import_forbidden');

            return null;
        }

        $hadExplicitTarget = (int) $request->session()->get(ProjectAccessService::SESSION_KEY, 0) > 0;
        $project = $request->attributes->get('project_context');
        if (! $project instanceof ClientProject) {
            $project = $this->projectAccess->resolveContext($request, $admin);
        }

        if ($project instanceof ClientProject) {
            $write
                ? $this->projectAccess->requireWrite($admin, $project, true)
                : $this->projectAccess->requireRead($admin, $project);

            return $project;
        }

        if ($hadExplicitTarget) {
            abort(403, 'project_context_inactive_or_forbidden');
        }

        abort_unless($admin->isSuperAdmin() && ! ClientProject::query()->exists(), 409, 'project_context_required');

        return null;
    }

    /** @return Builder<UrlImportJob> */
    private function scopedJobs(?ClientProject $project): Builder
    {
        return UrlImportJob::query()->when(
            $project instanceof ClientProject,
            fn (Builder $query) => $query->where('client_project_id', (int) $project->getKey()),
            fn (Builder $query) => $query->whereNull('client_project_id'),
        );
    }

    private function jobForProject(int $jobId, ?ClientProject $project): UrlImportJob
    {
        return $this->scopedJobs($project)->whereKey($jobId)->firstOrFail();
    }

    /** @param class-string<KnowledgeBase|KeywordLibrary|TitleLibrary> $modelClass */
    private function scopedOwnerCount(string $modelClass, ?ClientProject $project): int
    {
        return $modelClass::query()->when(
            $project instanceof ClientProject,
            fn (Builder $query) => $query->where('client_project_id', (int) $project->getKey()),
            fn (Builder $query) => $query->whereNull('client_project_id'),
        )->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(UrlImportJob $job): array
    {
        $logs = UrlImportJobLog::query()
            ->where('job_id', (int) $job->id)
            ->oldest()
            ->limit(120)
            ->get();
        $latestLogStep = (string) ($logs->last()?->step ?: '');
        $storedStep = (string) $job->current_step;
        $currentStep = $latestLogStep !== '' && ! ($latestLogStep === 'queued' && $storedStep !== 'queued')
            ? $latestLogStep
            : $storedStep;

        return [
            'id' => (int) $job->id,
            'status' => (string) $job->status,
            'status_label' => __('admin.url_import_history.status.'.$job->status),
            'commit_status' => (string) ($job->commit_status ?: 'not_started'),
            'commit_error_code' => $job->commit_error_code,
            'current_step' => $currentStep,
            'stored_step' => $storedStep,
            'progress_percent' => (int) $job->progress_percent,
            'error_message' => (string) $job->error_message,
            'result_ready' => (string) $job->result_json !== '',
            'finished_at' => optional($job->finished_at)->format('Y-m-d H:i:s'),
            'logs' => $logs
                ->map(fn (UrlImportJobLog $log): array => [
                    'step' => (string) ($log->step ?: ''),
                    'level' => (string) $log->level,
                    'message' => (string) $log->message,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->all(),
        ];
    }
}
