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

        $project = $this->access->resolveContext($request, $admin);

        // 文章与任务整组入口已完成项目查询/写入隔离，可在有效项目上下文下开放给 operator。
        // 素材、分析和其他后台页面仍保留超级管理员闸门，直到各自完成项目化。
        if (! $admin->isSuperAdmin()) {
            $projectSurface = $request->routeIs('admin.articles.*')
                || $request->routeIs('admin.tasks.*')
                || $request->routeIs('admin.publication-batches.*')
                || $request->routeIs('admin.enterprise-knowledge.*')
                || $request->routeIs('admin.url-import')
                || $request->routeIs('admin.url-import.*');
            if (! $projectSurface || $project === null) {
                abort(403, 'project_scoped_surface_unavailable');
            }
        }

        return $next($request);
    }
}
