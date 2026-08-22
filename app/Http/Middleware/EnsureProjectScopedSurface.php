<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Services\GeoFlow\ProjectAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureProjectScopedSurface
{
    public function __construct(private ProjectAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        if (! $admin instanceof Admin) {
            abort(401);
        }

        // 阶段 2 完成资源过滤前，普通 operator 不得进入现有全局页面。
        if (! $admin->isSuperAdmin()) {
            abort(403, 'project_scoped_surface_unavailable');
        }

        $this->access->resolveContext($request, $admin);

        return $next($request);
    }
}
