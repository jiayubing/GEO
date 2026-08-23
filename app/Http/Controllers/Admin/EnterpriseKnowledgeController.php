<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\Admin;
use App\Models\ClientProject;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\EnterpriseKnowledgeSource;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Services\GeoFlow\KnowledgeSourceParser;
use App\Services\GeoFlow\ProjectAccessService;
use App\Support\AdminWeb;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class EnterpriseKnowledgeController extends Controller
{
    private const MAX_KNOWLEDGE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly KnowledgeSourceParser $sourceParser,
        private readonly EnterpriseKnowledgeDraftService $draftService,
        private readonly ProjectAccessService $projectAccess,
    ) {}

    public function index(Request $request): View
    {
        $clientProject = $this->projectContext($request);
        $projects = $this->projectsFor($clientProject)
            ->with(['publishedKnowledgeBase'])
            ->withCount(['sources', 'revisions'])
            ->latest()
            ->paginate(20);

        return view('admin.enterprise-knowledge.index', [
            'pageTitle' => __('admin.enterprise_knowledge.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'projects' => $projects,
        ]);
    }

    public function create(Request $request): View
    {
        $this->projectContext($request, true);

        return view('admin.enterprise-knowledge.create', [
            'pageTitle' => __('admin.enterprise_knowledge.create_heading'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $clientProject = $this->projectContext($request, true);
        $payload = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string'],
            'enterprise_files' => ['nullable', 'array', 'max:10'],
            'enterprise_files.*' => ['file', File::types(['txt', 'md', 'markdown', 'docx'])->max(8 * 1024)],
        ], [
            'enterprise_files.max' => __('admin.enterprise_knowledge.error.files_limit'),
        ]);

        $manualContent = $this->sourceParser->normalizeKnowledgeText((string) ($payload['content'] ?? ''));
        $uploadedFiles = $this->sourceParser->uploadedFilesFromFields($request, ['enterprise_files']);
        if (strlen($manualContent) > self::MAX_KNOWLEDGE_BYTES) {
            throw ValidationException::withMessages([
                'content' => __('admin.knowledge_bases.error.content_too_large'),
            ]);
        }

        if ($manualContent === '' && $uploadedFiles === []) {
            throw ValidationException::withMessages([
                'content' => __('admin.enterprise_knowledge.error.content_required'),
            ]);
        }

        $storedPaths = [];
        $project = null;
        try {
            $sourceDirectory = $clientProject instanceof ClientProject
                ? 'uploads/enterprise-knowledge/projects/'.(int) $clientProject->getKey().'/sources'
                : 'uploads/enterprise-knowledge';
            $parsedFiles = $this->sourceParser->parseUploadedKnowledgeFiles($uploadedFiles, $storedPaths, $sourceDirectory);
            $mergedContent = $this->sourceParser->mergeKnowledgeSources($manualContent, $parsedFiles);
            $name = trim((string) ($payload['name'] ?? ''));
            $name = $name !== ''
                ? $name
                : ($this->sourceParser->inferKnowledgeName($uploadedFiles) ?: $this->sourceParser->inferKnowledgeNameFromContent($mergedContent));

            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => __('admin.enterprise_knowledge.error.name_required'),
                ]);
            }

            $project = DB::transaction(function () use ($name, $payload, $manualContent, $parsedFiles, $clientProject): EnterpriseKnowledgeProject {
                $project = EnterpriseKnowledgeProject::query()->create([
                    'name' => $name,
                    'description' => trim((string) ($payload['description'] ?? '')),
                    'status' => 'queued',
                    'structured_json' => $this->initialDraftProgressJson(),
                    'created_by_admin_id' => auth('admin')->id(),
                    'client_project_id' => $clientProject?->getKey(),
                ]);

                $sortOrder = 0;
                if ($manualContent !== '') {
                    EnterpriseKnowledgeSource::query()->create([
                        'enterprise_knowledge_project_id' => (int) $project->id,
                        'original_name' => __('admin.enterprise_knowledge.manual_source_name'),
                        'file_type' => 'markdown',
                        'content' => $manualContent,
                        'character_count' => mb_strlen($manualContent, 'UTF-8'),
                        'sort_order' => $sortOrder++,
                    ]);
                }

                foreach ($parsedFiles as $file) {
                    $content = (string) ($file['content'] ?? '');
                    EnterpriseKnowledgeSource::query()->create([
                        'enterprise_knowledge_project_id' => (int) $project->id,
                        'original_name' => (string) ($file['original_name'] ?? ''),
                        'file_path' => (string) ($file['file_path'] ?? $file['stored_path'] ?? ''),
                        'file_type' => (string) ($file['file_type'] ?? 'text'),
                        'content' => $content,
                        'character_count' => mb_strlen($content, 'UTF-8'),
                        'sort_order' => $sortOrder++,
                    ]);
                }

                return $project;
            });

            try {
                GenerateEnterpriseKnowledgeDraftJob::dispatch((int) $project->id, auth('admin')->id())->onQueue('geoflow');
            } catch (Throwable $exception) {
                $project->forceFill([
                    'status' => 'failed',
                    'error_message' => 'enterprise_knowledge_draft_dispatch_failed',
                ])->save();
                logger()->error('Enterprise knowledge draft dispatch failed.', [
                    'exception_class' => $exception::class,
                    'enterprise_knowledge_project_id' => $project->getKey(),
                    'client_project_id' => $clientProject?->getKey(),
                ]);

                return redirect()
                    ->route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id])
                    ->withErrors('enterprise_knowledge_draft_dispatch_failed');
            }

            return redirect()
                ->route('admin.enterprise-knowledge.show', ['projectId' => (int) $project->id])
                ->with('message', __('admin.enterprise_knowledge.message.queued'));
        } catch (ValidationException $exception) {
            $this->sourceParser->cleanupKnowledgeFiles($storedPaths);
            throw $exception;
        } catch (Throwable $exception) {
            if (! $project instanceof EnterpriseKnowledgeProject) {
                $this->sourceParser->cleanupKnowledgeFiles($storedPaths);
            }
            logger()->error('Enterprise knowledge creation failed.', [
                'exception_class' => $exception::class,
                'client_project_id' => $clientProject?->getKey(),
            ]);

            return back()
                ->withInput()
                ->withErrors(__('admin.enterprise_knowledge.error.create_failed', ['message' => 'enterprise_knowledge_create_failed']));
        }
    }

    public function show(Request $request, int $projectId): View
    {
        $project = $this->projectsFor($this->projectContext($request))
            ->with(['sources', 'revisions.creator', 'publishedKnowledgeBase'])
            ->whereKey($projectId)
            ->firstOrFail();

        return view('admin.enterprise-knowledge.show', [
            'pageTitle' => (string) $project->name,
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'project' => $project,
            'validationItems' => $project->validationItems(),
        ]);
    }

    public function status(Request $request, int $projectId): JsonResponse
    {
        $project = $this->projectsFor($this->projectContext($request))
            ->withCount(['sources', 'revisions'])
            ->whereKey($projectId)
            ->firstOrFail();

        $progress = $project->draftGenerationProgress();
        $status = (string) ($project->status ?? 'draft');
        $draftReady = trim((string) ($project->draft_content ?? '')) !== '';
        $fallbackProgress = match ($status) {
            'queued' => 8,
            'processing' => 45,
            'reviewing', 'published' => 100,
            'failed' => 100,
            default => 0,
        };
        $fallbackMessage = $status === 'failed'
            ? __('admin.enterprise_knowledge.progress_message.failed', [
                'message' => (string) ($project->error_message ?: __('admin.enterprise_knowledge.status_failed')),
            ])
            : $this->enterpriseKnowledgeTranslation(
                'admin.enterprise_knowledge.progress_message.'.$status,
                __('admin.enterprise_knowledge.progress_message.queued')
            );
        $statusLabel = $this->enterpriseKnowledgeTranslation(
            'admin.enterprise_knowledge.status_'.$status,
            $status
        );

        return response()->json([
            'status' => $status,
            'status_label' => $statusLabel,
            'draft_ready' => $draftReady,
            'reload' => in_array($status, ['reviewing', 'published', 'failed'], true),
            'error_message' => (string) ($project->error_message ?? ''),
            'sources_count' => (int) $project->sources_count,
            'revisions_count' => (int) $project->revisions_count,
            'progress' => [
                'step' => (string) ($progress['step'] ?? $status),
                'progress' => (int) ($progress['progress'] ?? $fallbackProgress),
                'message' => (string) ($progress['message'] ?? $fallbackMessage),
                'updated_at' => (string) ($progress['updated_at'] ?? optional($project->updated_at)->toIso8601String()),
            ],
        ]);
    }

    public function autosave(Request $request, int $projectId): JsonResponse
    {
        $clientProject = $this->projectContext($request, true);
        $payload = $request->validate([
            'content' => ['required', 'string'],
            'base_hash' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);

        $content = trim((string) $payload['content']);
        if (strlen($content) > self::MAX_KNOWLEDGE_BYTES) {
            throw ValidationException::withMessages([
                'content' => __('admin.knowledge_bases.error.content_too_large'),
            ]);
        }
        $validationItems = $this->draftService->validateDraft($content);
        $contentHash = hash('sha256', $content);
        DB::transaction(function () use ($clientProject, $projectId, $content, $validationItems, $payload): void {
            $project = $this->projectsFor($clientProject)->lockForUpdate()->whereKey($projectId)->firstOrFail();
            $this->assertCurrentDraftHash($project, $payload['base_hash'] ?? null);
            $project->update([
                'draft_content' => $content,
                'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
                'status' => $project->status === 'published' ? 'reviewing' : (string) $project->status,
            ]);
            $this->recordRevisionIfChanged($project, $content, 'manual', __('admin.enterprise_knowledge.revision_manual'));
        });

        return response()->json([
            'saved_at' => now()->format('Y-m-d H:i:s'),
            'validation_count' => count($validationItems),
            'validation_items' => $validationItems,
            'content_hash' => $contentHash,
        ]);
    }

    public function validateDraft(Request $request, int $projectId): RedirectResponse|JsonResponse
    {
        $clientProject = $this->projectContext($request, true);
        $payload = $request->validate([
            'content' => ['nullable', 'string'],
            'base_hash' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $submittedContent = array_key_exists('content', $payload) ? trim((string) $payload['content']) : null;
        if ($submittedContent !== null && strlen($submittedContent) > self::MAX_KNOWLEDGE_BYTES) {
            throw ValidationException::withMessages([
                'content' => __('admin.knowledge_bases.error.content_too_large'),
            ]);
        }
        $result = DB::transaction(function () use ($clientProject, $projectId, $submittedContent, $payload): array {
            $project = $this->projectsFor($clientProject)->lockForUpdate()->whereKey($projectId)->firstOrFail();
            $this->assertCurrentDraftHash($project, $payload['base_hash'] ?? null);
            $content = $submittedContent ?? trim((string) $project->draft_content);
            $validationItems = $this->draftService->validateDraft($content);
            $project->update([
                'draft_content' => $content,
                'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
            ]);

            return ['items' => $validationItems, 'content_hash' => hash('sha256', $content)];
        });
        $validationItems = $result['items'];

        if ($request->expectsJson()) {
            return response()->json([
                'validation_count' => count($validationItems),
                'validation_items' => $validationItems,
                'content_hash' => $result['content_hash'],
            ]);
        }

        return back()->with('message', __('admin.enterprise_knowledge.message.validated'));
    }

    public function uploadImage(Request $request, int $projectId): JsonResponse
    {
        $clientProject = $this->projectContext($request, true);
        $this->projectsFor($clientProject)->whereKey($projectId)->firstOrFail();

        try {
            $payload = $request->validate([
                'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:10240'],
                'alt' => ['nullable', 'string', 'max:120'],
            ], [
                'image.required' => __('admin.enterprise_knowledge.error.image_required'),
                'image.image' => __('admin.enterprise_knowledge.error.image_invalid'),
                'image.mimes' => __('admin.enterprise_knowledge.error.image_invalid'),
                'image.max' => __('admin.enterprise_knowledge.error.image_too_large'),
            ]);

            $file = $request->file('image');
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'image' => __('admin.enterprise_knowledge.error.upload_missing'),
                ]);
            }

            $stored = $this->storeEditorImageFile($file, (int) $projectId, $clientProject?->getKey());
            $alt = $this->normalizeImageAlt((string) ($payload['alt'] ?? ''));
            if ($alt === '') {
                $alt = $this->readableImageAlt($file->getClientOriginalName());
            }

            $url = Storage::disk('public')->url((string) $stored['path']);

            return response()->json([
                'message' => __('admin.enterprise_knowledge.editor_upload_success'),
                'image' => [
                    'url' => $url,
                    'storage_path' => (string) $stored['path'],
                    'file_path' => 'storage/'.(string) $stored['path'],
                    'original_name' => $file->getClientOriginalName(),
                    'alt' => $alt,
                    'markdown' => '!['.$this->escapeMarkdownAlt($alt).']('.$url.')',
                    'width' => (int) $stored['width'],
                    'height' => (int) $stored['height'],
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            logger()->error('Enterprise knowledge editor image upload failed.', [
                'exception_class' => $exception::class,
                'enterprise_knowledge_project_id' => $projectId,
                'client_project_id' => $clientProject?->getKey(),
            ]);

            return response()->json([
                'message' => __('admin.enterprise_knowledge.editor_upload_failed'),
            ], 422);
        }
    }

    public function restoreRevision(Request $request, int $projectId, int $revisionId): RedirectResponse
    {
        $clientProject = $this->projectContext($request, true);
        DB::transaction(function () use ($clientProject, $projectId, $revisionId): void {
            $project = $this->projectsFor($clientProject)->lockForUpdate()->whereKey($projectId)->firstOrFail();
            $revision = EnterpriseKnowledgeRevision::query()
                ->where('enterprise_knowledge_project_id', (int) $project->id)
                ->whereKey($revisionId)
                ->firstOrFail();

            $content = (string) $revision->content;
            $project->update([
                'draft_content' => $content,
                'validation_json' => json_encode($this->draftService->validateDraft($content), JSON_UNESCAPED_UNICODE),
                'status' => 'reviewing',
            ]);
            $this->recordRevisionIfChanged($project, $content, 'restore', __('admin.enterprise_knowledge.revision_restore'));
        });

        return back()->with('message', __('admin.enterprise_knowledge.message.restored'));
    }

    public function publish(Request $request, int $projectId): RedirectResponse
    {
        $clientProject = $this->projectContext($request, true);
        $project = $this->projectsFor($clientProject)->whereKey($projectId)->firstOrFail();
        $content = trim((string) ($project->draft_content ?? ''));
        if ($content === '') {
            return back()->withErrors(__('admin.enterprise_knowledge.error.content_required'));
        }

        try {
            $result = $this->draftService->publishToKnowledgeBase($project, $content, $request->user('admin')?->getKey());
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }
        $knowledgeBase = $result['knowledge_base'];

        if ((string) $result['chunk_error'] !== '') {
            return back()->withErrors(__('admin.enterprise_knowledge.message.published_with_chunk_error', [
                'message' => (string) $result['chunk_error'],
            ]));
        }

        return redirect()
            ->route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id])
            ->with('message', __('admin.enterprise_knowledge.message.published_queued'));
    }

    public function destroy(Request $request, int $projectId): RedirectResponse
    {
        $clientProject = $this->projectContext($request, true);
        $project = $this->projectsFor($clientProject)->with('sources')->whereKey($projectId)->firstOrFail();
        $sourcePaths = $project->sources->pluck('file_path')->filter()->map(static fn ($path): string => (string) $path)->values()->all();
        $imageDirectory = $this->editorImageDirectory((int) $project->id, $clientProject?->getKey());

        DB::transaction(function () use ($project): void {
            $project->delete();
        });

        try {
            $this->sourceParser->cleanupKnowledgeFiles($sourcePaths);
            Storage::disk('public')->deleteDirectory($imageDirectory);
        } catch (Throwable $exception) {
            logger()->warning('Enterprise knowledge files could not be fully cleaned after deletion.', [
                'exception_class' => $exception::class,
                'enterprise_knowledge_project_id' => $projectId,
                'client_project_id' => $clientProject?->getKey(),
            ]);
        }

        return redirect()
            ->route('admin.enterprise-knowledge.index')
            ->with('message', __('admin.enterprise_knowledge.message.deleted'));
    }

    private function recordRevisionIfChanged(EnterpriseKnowledgeProject $project, string $content, string $source, string $summary): void
    {
        $hash = hash('sha256', $content);
        $latestHash = (string) EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', (int) $project->id)
            ->latest('id')
            ->value('content_hash');

        if ($latestHash === $hash) {
            return;
        }

        $this->recordRevision($project, $content, $source, $summary, $hash);
    }

    private function recordRevision(EnterpriseKnowledgeProject $project, string $content, string $source, string $summary, ?string $hash = null): void
    {
        EnterpriseKnowledgeRevision::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'content' => $content,
            'summary' => $summary,
            'source' => $source,
            'created_by_admin_id' => auth('admin')->id(),
            'content_hash' => $hash ?? hash('sha256', $content),
        ]);
    }

    private function storeEditorImageFile(UploadedFile $file, int $projectId, ?int $clientProjectId): array
    {
        if (! $file->isValid()) {
            throw new RuntimeException(__('admin.enterprise_knowledge.error.upload_missing'));
        }

        $directory = $this->editorImageDirectory($projectId, $clientProjectId).'/'.now()->format('Y/m');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        if (! in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            $extension = 'jpg';
        }

        $path = $file->storeAs($directory, bin2hex(random_bytes(16)).'.'.$extension, 'public');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException(__('admin.enterprise_knowledge.error.upload_store_failed'));
        }

        $size = @getimagesize($file->getRealPath());

        return [
            'path' => $path,
            'width' => is_array($size) ? (int) ($size[0] ?? 0) : 0,
            'height' => is_array($size) ? (int) ($size[1] ?? 0) : 0,
        ];
    }

    private function readableImageAlt(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);

        return $this->normalizeImageAlt(str_replace(['-', '_'], ' ', $name));
    }

    private function normalizeImageAlt(string $alt): string
    {
        $alt = trim(preg_replace('/\s+/u', ' ', $alt) ?: '');

        return mb_substr($alt, 0, 120, 'UTF-8');
    }

    private function escapeMarkdownAlt(string $alt): string
    {
        return str_replace([']', "\n", "\r"], ['\\]', ' ', ' '], $alt);
    }

    private function initialDraftProgressJson(): string
    {
        return json_encode([
            'draft_generation' => [
                'step' => 'queued',
                'progress' => 8,
                'message' => __('admin.enterprise_knowledge.progress_message.queued'),
                'started_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ],
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function editorImageDirectory(int $projectId, ?int $clientProjectId): string
    {
        return $clientProjectId === null
            ? 'uploads/enterprise-knowledge/'.$projectId.'/images'
            : 'uploads/enterprise-knowledge/projects/'.$clientProjectId.'/'.$projectId.'/images';
    }

    private function assertCurrentDraftHash(EnterpriseKnowledgeProject $project, mixed $baseHash): void
    {
        if (is_string($baseHash)
            && ! hash_equals(hash('sha256', (string) $project->draft_content), $baseHash)) {
            abort(409, 'enterprise_knowledge_draft_stale');
        }
    }

    private function projectContext(Request $request, bool $write = false): ?ClientProject
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 401);

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

        // Fresh/legacy installations without a client-project owner retain the
        // original super-admin path until the phase-5A backfill creates projects.
        abort_unless($admin->isSuperAdmin() && ! ClientProject::query()->exists(), 409, 'project_context_required');

        return null;
    }

    /** @return Builder<EnterpriseKnowledgeProject> */
    private function projectsFor(?ClientProject $clientProject): Builder
    {
        $query = EnterpriseKnowledgeProject::query();

        return $clientProject instanceof ClientProject
            ? $query->where('client_project_id', (int) $clientProject->getKey())
            : $query->whereNull('client_project_id');
    }

    private function enterpriseKnowledgeTranslation(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) __($key) : $fallback;
    }
}
