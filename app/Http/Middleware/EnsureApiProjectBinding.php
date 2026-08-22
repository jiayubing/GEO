<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiProjectBinding
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get('api_auth');
        if (! $context instanceof ApiAuthContext) {
            throw new ApiException('unauthorized', '未认证', 401);
        }

        $boundProjectId = $context->projectId();
        try {
            $route = $request->route();
        } catch (\LogicException) {
            $route = null;
        }
        $target = null;
        if (is_object($route)) {
            try {
                $target = $route->parameter('project_id') ?? $route->parameter('project');
            } catch (\LogicException) {
                $target = null;
            }
        }
        $target ??= $request->input('project_id') ?? $request->query('project_id');
        $targetId = $target !== null && is_numeric($target) ? (int) $target : null;

        if ($boundProjectId !== null && ($targetId === null || $targetId !== $boundProjectId)) {
            throw new ApiException('project_mismatch', 'Token 未绑定当前项目', 403);
        }

        if ($boundProjectId === null) {
            $admin = Admin::query()->find($context->auditAdminId);
            if (! $admin?->isSuperAdmin()) {
                throw new ApiException('legacy_token_forbidden', 'legacy_global Token 仅允许超级管理员在兼容期使用', 403);
            }
        }

        return $next($request);
    }
}
