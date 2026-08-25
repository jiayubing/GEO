<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use Illuminate\Http\Request;

final class AdminLandingService
{
    public function __construct(private readonly ProjectAccessService $access) {}

    public function routeFor(Request $request, Admin $admin): string
    {
        if ($admin->isSuperAdmin()) {
            return route('admin.dashboard');
        }

        $project = $this->access->resolveContext($request, $admin);
        if ($project === null) {
            $project = $this->access->accessibleProjects($admin)->first();
            if ($project !== null) {
                $this->access->switchContext($request, $admin, (int) $project->getKey());
            }
        }

        return $project === null
            ? route('admin.client-projects.create')
            : route('admin.articles.index');
    }
}
