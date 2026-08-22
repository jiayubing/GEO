<?php

namespace App\Http\Controllers\Admin;

use App\Models\Admin;
use App\Services\GeoFlow\ProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectContextController
{
    public function __construct(private ProjectAccessService $access) {}

    public function show(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $current = $this->access->resolveContext($request, $admin);

        return response()->json([
            'current_project_id' => $current?->getKey(),
            'projects' => $this->access->accessibleProjects($admin)->map(fn ($project): array => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'slug' => (string) $project->slug,
                'status' => $project->status->value,
            ])->values(),
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $projectId = (int) $request->input('project_id', 0);
        abort_if($projectId <= 0, 422, 'project_id 无效');
        $project = $this->access->switchContext($request, $admin, $projectId);

        return response()->json(['current_project_id' => (int) $project->id]);
    }
}
