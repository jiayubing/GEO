<?php

namespace App\Services\GeoFlow;

use App\Models\ClientProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProjectOperationsReportService
{
    /** @return array<string,mixed> */
    public function projectSummary(ClientProject $project): array
    {
        $id = (int) $project->getKey();
        $tasks = DB::table('tasks')->where('client_project_id', $id);
        $articles = DB::table('articles')->where('client_project_id', $id)->whereNull('deleted_at');
        $usage = Schema::hasTable('ai_usage_events') ? DB::table('ai_usage_events')->where('client_project_id', $id) : null;
        $batches = Schema::hasTable('publication_batches') ? DB::table('publication_batches')->where('client_project_id', $id) : null;
        $reservations = Schema::hasTable('client_project_usage_reservations') ? DB::table('client_project_usage_reservations')->where('client_project_id', $id) : null;

        return [
            'project_id' => $id,
            'tasks' => ['total' => (int) $tasks->count(), 'active' => (int) (clone $tasks)->where('status', 'active')->count()],
            'articles' => ['total' => (int) $articles->count(), 'published' => (int) (clone $articles)->where('status', 'published')->count()],
            'ai_usage_units' => $usage === null ? 0 : (int) $usage->whereIn('outcome', ['success', 'fallback', 'uncertain'])->sum('units'),
            'publication_batches' => $batches === null ? 0 : (int) $batches->count(),
            'failed_runs' => Schema::hasTable('task_runs') ? (int) DB::table('task_runs')->join('tasks', 'tasks.id', '=', 'task_runs.task_id')->where('tasks.client_project_id', $id)->whereIn('task_runs.status', ['failed', 'cancelled'])->count() : 0,
            'uncertain_reservations' => $reservations === null ? 0 : (int) $reservations->where('state', 'uncertain')->count(),
        ];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function paginate(array $projectIds, int $page = 1, int $perPage = 50): array
    {
        $ids = collect($projectIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $page = max(1, $page); $perPage = max(1, min(100, $perPage));
        $projects = ClientProject::query()->whereIn('id', $ids)->orderBy('id');
        $total = (clone $projects)->count();
        $items = $projects->forPage($page, $perPage)->get()->map(fn (ClientProject $project) => $this->projectSummary($project))->all();
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
