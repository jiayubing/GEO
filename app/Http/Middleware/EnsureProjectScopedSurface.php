<?php

namespace App\Http\Middleware;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Models\Admin;
use App\Services\GeoFlow\AdminLandingService;
use App\Services\GeoFlow\ProjectAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureProjectScopedSurface
{
    public function __construct(
        private readonly ProjectAccessService $access,
        private readonly AdminLandingService $adminLanding,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        if (! $admin instanceof Admin) {
            abort(401);
        }

        if (! $admin->isSuperAdmin() && $request->routeIs('admin.dashboard')) {
            return redirect()->to($this->adminLanding->routeFor($request, $admin));
        }

        $project = $this->access->resolveContext($request, $admin);

        // The project-scoped batch list predates the platform approval center.
        // Super administrators do not hold an operating-project context, so send
        // the legacy read entry point to the global queue before enforcing that boundary.
        if ($admin->isSuperAdmin() && $request->routeIs('admin.publication-batches.index')) {
            return redirect()->route('admin.publication-batch-approvals.index');
        }

        // 文章与任务整组入口已完成项目查询/写入隔离，可在有效项目上下文下开放给 operator。
        // 素材、分析和其他后台页面仍保留超级管理员闸门，直到各自完成项目化。
        // Ordinary operators must have an active project context. Legacy admin
        // task pages retain their existing platform-wide surface; all other
        // projectized pages remain fail-closed without a selected project.
        if (! $admin->isSuperAdmin()) {
            $isProjectOperator = strtolower((string) $admin->role) === 'operator'
                || $admin->projectMemberships()
                    ->where('role', ClientProjectMemberRole::OPERATOR->value)
                    ->where('status', ClientProjectMemberStatus::ACTIVE->value)
                    ->exists();
            $projectSurface = $request->routeIs('admin.articles.*')
                || $request->routeIs('admin.tasks.*')
                || $request->routeIs('admin.publication-batches.*')
                || $request->routeIs('admin.enterprise-knowledge.*')
                || $request->routeIs('admin.keyword-libraries.*')
                || $request->routeIs('admin.title-libraries.*')
                || $request->routeIs('admin.image-libraries.*')
                || $request->routeIs('admin.knowledge-bases.*')
                || $request->routeIs('admin.authors.*')
                || $request->routeIs('admin.categories.*')
                || $request->routeIs('admin.materials.index')
                || $request->routeIs('admin.url-import')
                || $request->routeIs('admin.url-import.*');
            $requiresProject = $request->routeIs('admin.articles.*')
                || $request->routeIs('admin.publication-batches.*')
                || $request->routeIs('admin.enterprise-knowledge.*')
                || $request->routeIs('admin.url-import')
                || $request->routeIs('admin.url-import.*');
            // Operators are restricted to the explicitly projectized surfaces.
            // Legacy admins retain their existing platform-wide pages, while
            // projectized article/publication/import surfaces fail closed.
            $operatorWithoutSurface = $isProjectOperator && ! $projectSurface;
            $restrictedWithoutProject = $project === null && $requiresProject;
            if ($operatorWithoutSurface || $restrictedWithoutProject) {
                abort(403, 'project_scoped_surface_unavailable');
            }
        }

        return $next($request);
    }
}
