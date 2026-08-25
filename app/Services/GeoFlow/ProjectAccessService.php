<?php

namespace App\Services\GeoFlow;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Enums\ClientProjectStatus;
use App\Models\Admin;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ProjectAccessService
{
    public const SESSION_KEY = 'geoflow.project_context_id';

    /** @return Collection<int, ClientProject> */
    public function accessibleProjects(Admin $admin): Collection
    {
        if ($admin->isSuperAdmin()) {
            return collect();
        }

        return ClientProject::query()
            ->where('status', ClientProjectStatus::ACTIVE->value)
            ->whereHas('members', function ($members) use ($admin): void {
                $members->where('admin_id', $admin->getKey())
                    ->where('status', ClientProjectMemberStatus::ACTIVE->value);
            })
            ->orderBy('name')
            ->get();
    }

    public function resolveContext(Request $request, Admin $admin): ?ClientProject
    {
        if ($admin->isSuperAdmin()) {
            $request->session()->forget(self::SESSION_KEY);
            $request->attributes->remove('project_context');

            return null;
        }

        $projectId = (int) $request->session()->get(self::SESSION_KEY, 0);
        if ($projectId <= 0) {
            return null;
        }

        $project = ClientProject::query()->whereKey($projectId)->first();
        if (! $project || ! $this->canRead($admin, $project)) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        $request->attributes->set('project_context', $project);

        return $project;
    }

    public function switchContext(Request $request, Admin $admin, int $projectId): ClientProject
    {
        if ($admin->isSuperAdmin()) {
            $request->session()->forget(self::SESSION_KEY);
            $request->attributes->remove('project_context');

            throw new AccessDeniedHttpException('超级管理员使用平台全局上下文，不能切换运营项目');
        }

        $project = ClientProject::query()->whereKey($projectId)->first();
        if (! $project || ! $this->canRead($admin, $project)) {
            throw new AccessDeniedHttpException('项目不存在或当前管理员无权访问');
        }

        $request->session()->put(self::SESSION_KEY, (int) $project->getKey());
        $request->attributes->set('project_context', $project);

        return $project;
    }

    public function canRead(Admin $admin, ClientProject $project): bool
    {
        if ($this->projectStatus($project) !== ClientProjectStatus::ACTIVE->value) {
            return false;
        }

        return $admin->isSuperAdmin() || $this->activeMembership($admin, $project) !== null;
    }

    public function canWrite(Admin $admin, ClientProject $project, bool $explicitTarget = true): bool
    {
        if (! $explicitTarget || $this->projectStatus($project) !== ClientProjectStatus::ACTIVE->value) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        $membership = $this->activeMembership($admin, $project);

        return $membership !== null && $membership->role !== ClientProjectMemberRole::VIEWER;
    }

    public function canManageContentAdministration(Admin $admin, ?ClientProject $project): bool
    {
        if ($admin->isSuperAdmin() || ! $project instanceof ClientProject) {
            return true;
        }

        return $this->activeMembership($admin, $project)?->role !== ClientProjectMemberRole::OPERATOR;
    }

    public function requireRead(Admin $admin, ClientProject $project): void
    {
        if (! $this->canRead($admin, $project)) {
            throw new AccessDeniedHttpException('项目不存在或当前管理员无权访问');
        }
    }

    public function requireWrite(Admin $admin, ClientProject $project, bool $explicitTarget = true): void
    {
        if (! $this->canWrite($admin, $project, $explicitTarget)) {
            throw new AccessDeniedHttpException('项目写入需要明确且有效的项目目标');
        }
    }

    public function requireSingleTarget(iterable $projectIds): int
    {
        $ids = collect($projectIds)->map(static fn ($id): int => (int) $id)->filter()->unique()->values();
        if ($ids->count() !== 1) {
            throw new AccessDeniedHttpException('不允许混合项目批量操作');
        }

        return (int) $ids->first();
    }

    private function activeMembership(Admin $admin, ClientProject $project): ?ClientProjectMember
    {
        return ClientProjectMember::query()
            ->where('admin_id', $admin->getKey())
            ->where('client_project_id', $project->getKey())
            ->where('status', ClientProjectMemberStatus::ACTIVE->value)
            ->first();
    }

    private function projectStatus(ClientProject $project): string
    {
        $status = $project->status;

        return $status instanceof ClientProjectStatus ? $status->value : (string) ($status ?: '');
    }
}
