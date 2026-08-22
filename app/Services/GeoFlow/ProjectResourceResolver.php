<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ClientProject;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Models\Title;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Resolves project-owned resources and validates cross-resource references.
 *
 * The project owner column is the only source of truth; callers must not rely
 * on an in-memory collection or a request supplied project id for authorization.
 */
final class ProjectResourceResolver
{
    /** @var array<string, string> Table name to immutable owner column. */
    private const OWNER_COLUMNS = [
        'knowledge_bases' => 'client_project_id',
        'keyword_libraries' => 'client_project_id',
        'title_libraries' => 'client_project_id',
        'image_libraries' => 'client_project_id',
        'authors' => 'client_project_id',
        'categories' => 'client_project_id',
        'tasks' => 'client_project_id',
        'articles' => 'client_project_id',
        'enterprise_knowledge_projects' => 'client_project_id',
        'url_import_jobs' => 'client_project_id',
        'manual_publications' => 'client_project_id',
    ];

    public function resolveOwned(string $modelClass, int $id, ClientProject $project): ?Model
    {
        $model = new $modelClass();
        if ($model instanceof Title) {
            return Title::query()
                ->whereKey($id)
                ->whereHas('library', fn ($query) => $query->where('client_project_id', (int) $project->getKey()))
                ->first();
        }
        $column = self::OWNER_COLUMNS[$model->getTable()] ?? null;
        if ($column === null || $id <= 0) {
            return null;
        }

        return $modelClass::query()
            ->whereKey($id)
            ->where($column, (int) $project->getKey())
            ->first();
    }

    public function requireOwned(string $modelClass, int $id, ClientProject $project): Model
    {
        $resource = $this->resolveOwned($modelClass, $id, $project);
        if ($resource === null) {
            throw new AccessDeniedHttpException('资源不存在或不属于当前项目');
        }

        return $resource;
    }

    public function requireSameProject(ClientProject $project, ?Model ...$resources): void
    {
        $projectId = (int) $project->getKey();
        foreach ($resources as $resource) {
            if ($resource === null || (int) $resource->getAttribute('client_project_id') !== $projectId) {
                throw new AccessDeniedHttpException('资源不存在或不属于当前项目');
            }
        }
    }

    /** @param array<string, int|null> $references */
    public function requireTaskReferences(Task $task, ClientProject $project, array $references = []): void
    {
        $this->requireSameProject($project, $task);
        $references += [
            \App\Models\TitleLibrary::class => $task->title_library_id,
            \App\Models\ImageLibrary::class => $task->image_library_id,
            \App\Models\Author::class => $task->author_id,
            \App\Models\Author::class.'#custom' => $task->custom_author_id,
            \App\Models\Category::class => $task->fixed_category_id,
            \App\Models\KnowledgeBase::class => $task->knowledge_base_id,
        ];
        foreach ($references as $modelClass => $id) {
            if ($id === null || (int) $id <= 0) {
                continue;
            }
            $this->requireOwned(str_contains($modelClass, '#') ? (string) strstr($modelClass, '#', true) : $modelClass, (int) $id, $project);
        }
        foreach ($task->knowledgeBases()->get() as $knowledgeBase) {
            $this->requireSameProject($project, $knowledgeBase);
        }
        if ($task->title_library_id) {
            $titleLibrary = $this->requireOwned(\App\Models\TitleLibrary::class, (int) $task->title_library_id, $project);
            if ($titleLibrary->keyword_library_id) {
                $this->requireOwned(\App\Models\KeywordLibrary::class, (int) $titleLibrary->keyword_library_id, $project);
            }
        }
        foreach ($task->distributionChannels()->get() as $channel) {
            $this->requireChannelMembership($project, $channel);
        }
    }

    public function requireArticleReferences(Article $article, ClientProject $project): void
    {
        $this->requireSameProject($project, $article);
        if ($article->task_id) {
            $task = Task::query()->find((int) $article->task_id);
            $this->requireSameProject($project, $task);
        }
        foreach ([
            ['class' => \App\Models\Title::class, 'id' => $article->source_title_id],
            ['class' => \App\Models\Author::class, 'id' => $article->author_id],
            ['class' => \App\Models\Category::class, 'id' => $article->category_id],
        ] as $reference) {
            if ($reference['id']) {
                $this->requireOwned($reference['class'], (int) $reference['id'], $project);
            }
        }
    }

    public function requireChannelMembership(ClientProject $project, DistributionChannel $channel): void
    {
        $exists = DB::table('client_project_distribution_channels')
            ->where('client_project_id', (int) $project->getKey())
            ->where('distribution_channel_id', (int) $channel->getKey())
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->exists();
        if (! $exists) {
            throw new AccessDeniedHttpException('渠道未加入当前项目或已停用');
        }
    }
}
