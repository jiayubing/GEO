<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Author;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\ClientProject;
use App\Services\GeoFlow\ProjectAccessService;
use App\Support\AdminWeb;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Illuminate\Http\Request;

/**
 * 素材管理首页控制器。
 */
class MaterialsController extends Controller
{
    public function __construct(private readonly ProjectAccessService $projectAccess) {}

    /**
     * 展示素材管理总览页。
     */
    public function index(Request $request): View
    {
        $project = $this->currentProject($request);

        return view('admin.materials.index', [
            'pageTitle' => __('admin.materials.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'canManageProtectedWorkflows' => auth('admin')->user()?->canManageProtectedWorkflows() === true,
            'stats' => $this->loadStats($project),
        ]);
    }

    /**
     * 加载素材管理统计数据。
     *
     * @return array{
     *     keyword_libraries:int,
     *     total_keywords:int,
     *     title_libraries:int,
     *     total_titles:int,
     *     image_libraries:int,
     *     total_images:int,
     *     knowledge_bases:int,
     *     knowledge_chunks:int,
     *     vectorized_chunks:int,
     *     unvectorized_chunks:int,
     *     knowledge_usage_count:int,
     *     active_embedding_models:int,
     *     default_embedding_model:string,
     *     chunk_strategy:string,
     *     latest_knowledge_updated_at:string,
     *     metadata_ready_count:int,
     *     reviewed_knowledge_bases:int,
     *     high_risk_pending_count:int,
     *     authors:int
     * }
     */
    private function loadStats(?ClientProject $project = null): array
    {
        $knowledgeBases = KnowledgeBase::query()->when($project, fn ($query) => $query->where('client_project_id', $project->id));
        $keywordLibraries = KeywordLibrary::query()->when($project, fn ($query) => $query->where('client_project_id', $project->id));
        $titleLibraries = TitleLibrary::query()->when($project, fn ($query) => $query->where('client_project_id', $project->id));
        $imageLibraries = ImageLibrary::query()->when($project, fn ($query) => $query->where('client_project_id', $project->id));
        $authors = Author::query()->when($project, fn ($query) => $query->where('client_project_id', $project->id));
        $knowledgeChunksQuery = KnowledgeChunk::query()->when($project, fn ($query) => $query->whereHas('knowledgeBase', fn ($base) => $base->where('client_project_id', $project->id)));
        $knowledgeChunks = (int) (clone $knowledgeChunksQuery)->count();
        $vectorizedChunks = (int) (clone $knowledgeChunksQuery)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_dimensions', '>', 0)
            ->count();
        $defaultEmbeddingModelId = (int) (SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->value('setting_value') ?? 0);
        $defaultEmbeddingModel = $defaultEmbeddingModelId > 0
            ? (string) (AiModel::query()
                ->whereKey($defaultEmbeddingModelId)
                ->where('status', 'active')
                ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
                ->value('name') ?? '')
            : '';
        $chunkStrategy = (string) (SiteSetting::query()
            ->where('setting_key', 'knowledge_chunk_strategy')
            ->value('setting_value') ?? 'rule');
        $latestKnowledgeUpdatedAt = $this->latestKnowledgeUpdatedAt();

        return [
            'keyword_libraries' => (clone $keywordLibraries)->count(),
            'total_keywords' => Keyword::query()->whereHas('library', fn ($query) => $query->when($project, fn ($library) => $library->where('client_project_id', $project->id)))->count(),
            'title_libraries' => (clone $titleLibraries)->count(),
            'total_titles' => Title::query()->whereHas('library', fn ($query) => $query->when($project, fn ($library) => $library->where('client_project_id', $project->id)))->count(),
            'image_libraries' => (clone $imageLibraries)->count(),
            'total_images' => Image::query()->whereHas('library', fn ($query) => $query->when($project, fn ($library) => $library->where('client_project_id', $project->id)))->count(),
            'knowledge_bases' => (clone $knowledgeBases)->count(),
            'knowledge_chunks' => $knowledgeChunks,
            'vectorized_chunks' => $vectorizedChunks,
            'unvectorized_chunks' => max(0, $knowledgeChunks - $vectorizedChunks),
            'knowledge_usage_count' => $this->knowledgeUsageTaskCount($project),
            'active_embedding_models' => AiModel::query()
                ->where('status', 'active')
                ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
                ->count(),
            'default_embedding_model' => $defaultEmbeddingModel,
            'chunk_strategy' => in_array($chunkStrategy, ['rule', 'auto', 'semantic_llm'], true) ? $chunkStrategy : 'rule',
            'latest_knowledge_updated_at' => $this->latestKnowledgeUpdatedAt($project),
            'metadata_ready_count' => $this->knowledgeMetadataReadyCount($project),
            'reviewed_knowledge_bases' => $this->reviewedKnowledgeBaseCount($project),
            'high_risk_pending_count' => $this->highRiskPendingKnowledgeBaseCount($project),
            'authors' => (clone $authors)->count(),
        ];
    }

    private function knowledgeMetadataReadyCount(?ClientProject $project = null): int
    {
        $columns = array_values(array_filter(
            ['source_name', 'source_url', 'business_line'],
            static fn (string $column): bool => Schema::hasColumn('knowledge_bases', $column)
        ));
        if ($columns === []) {
            return 0;
        }

        return KnowledgeBase::query()
            ->when($project, fn ($query) => $query->where('client_project_id', $project->id))
            ->where(function ($query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhere(function ($inner) use ($column): void {
                        $inner->whereNotNull($column)->where($column, '<>', '');
                    });
                }
            })
            ->count();
    }

    private function knowledgeUsageTaskCount(?ClientProject $project = null): int
    {
        $taskIds = Task::query()
            ->whereNotNull('knowledge_base_id')
            ->when($project, fn ($query) => $query->where('client_project_id', $project->id))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (Schema::hasTable('task_knowledge_bases')) {
            $taskIds = array_merge(
                $taskIds,
                DB::table('task_knowledge_bases')
                    ->when($project, fn ($query) => $query->whereIn('task_id', Task::query()->where('client_project_id', $project->id)->select('id')))
                    ->pluck('task_id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all()
            );
        }

        return count(array_unique(array_filter($taskIds, static fn (int $id): bool => $id > 0)));
    }

    private function reviewedKnowledgeBaseCount(?ClientProject $project = null): int
    {
        if (! Schema::hasColumn('knowledge_bases', 'review_status')) {
            return 0;
        }

        return KnowledgeBase::query()
            ->when($project, fn ($query) => $query->where('client_project_id', $project->id))
            ->where('review_status', 'reviewed')
            ->count();
    }

    private function highRiskPendingKnowledgeBaseCount(?ClientProject $project = null): int
    {
        if (! Schema::hasColumn('knowledge_bases', 'risk_level') || ! Schema::hasColumn('knowledge_bases', 'review_status')) {
            return 0;
        }

        return KnowledgeBase::query()
            ->when($project, fn ($query) => $query->where('client_project_id', $project->id))
            ->where('risk_level', 'high')
            ->where(function ($query): void {
                $query
                    ->whereNull('review_status')
                    ->orWhere('review_status', '<>', 'reviewed');
            })
            ->count();
    }

    private function latestKnowledgeUpdatedAt(?ClientProject $project = null): string
    {
        $timestamps = array_filter([
            KnowledgeBase::query()->when($project, fn ($query) => $query->where('client_project_id', $project->id))->max('updated_at'),
            KnowledgeChunk::query()->when($project, fn ($query) => $query->whereHas('knowledgeBase', fn ($base) => $base->where('client_project_id', $project->id)))->max('updated_at'),
        ]);

        if ($timestamps === []) {
            return '';
        }

        return Carbon::parse(max($timestamps))->format('Y-m-d H:i');
    }

    private function currentProject(Request $request): ?ClientProject
    {
        $admin = $request->user('admin');
        if (! $admin) {
            return null;
        }

        return $request->attributes->get('project_context')
            ?: $this->projectAccess->resolveContext($request, $admin);
    }
}
