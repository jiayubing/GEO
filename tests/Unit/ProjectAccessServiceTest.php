<?php

namespace Tests\Unit;

use App\Enums\ClientProjectMemberStatus;
use App\Enums\ClientProjectStatus;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Services\GeoFlow\ProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class ProjectAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_switch_only_to_active_assigned_project(): void
    {
        $admin = $this->admin('operator');
        $assigned = $this->project('assigned');
        $other = $this->project('other');
        ClientProjectMember::create(['client_project_id' => $assigned->id, 'admin_id' => $admin->id]);
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $service = app(ProjectAccessService::class);

        $service->switchContext($request, $admin, (int) $assigned->id);
        $this->assertSame($assigned->id, $request->session()->get(ProjectAccessService::SESSION_KEY));
        $this->expectException(AccessDeniedHttpException::class);
        $service->switchContext($request, $admin, (int) $other->id);
    }

    public function test_revoked_membership_and_inactive_project_invalidate_context(): void
    {
        $admin = $this->admin('operator');
        $project = $this->project('assigned');
        $membership = ClientProjectMember::create(['client_project_id' => $project->id, 'admin_id' => $admin->id]);
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $service = app(ProjectAccessService::class);
        $service->switchContext($request, $admin, (int) $project->id);

        $membership->update(['status' => ClientProjectMemberStatus::REVOKED]);
        $this->assertNull($service->resolveContext($request, $admin));
        $this->assertNull($request->session()->get(ProjectAccessService::SESSION_KEY));

        $membership->update(['status' => ClientProjectMemberStatus::ACTIVE]);
        $project->update(['status' => ClientProjectStatus::SUSPENDED]);
        $this->assertFalse($service->canRead($admin, $project->fresh()));
    }

    public function test_super_admin_requires_explicit_target_for_writes_and_rejects_mixed_batch(): void
    {
        $admin = $this->admin('super_admin');
        $project = $this->project('one')->fresh();
        $service = app(ProjectAccessService::class);

        $this->assertTrue($service->canRead($admin, $project));
        $this->assertFalse($service->canWrite($admin, $project, false));
        $this->assertTrue($service->canWrite($admin, $project, true));
        $this->expectException(AccessDeniedHttpException::class);
        $service->requireSingleTarget([$project->id, $this->project('two')->id]);
    }

    public function test_super_admin_cannot_list_resolve_or_switch_operational_project_context(): void
    {
        $admin = $this->admin('super_admin');
        $project = $this->project('platform-context');
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(ProjectAccessService::SESSION_KEY, $project->id);
        $service = app(ProjectAccessService::class);

        $this->assertTrue($service->accessibleProjects($admin)->isEmpty());
        $this->assertNull($service->resolveContext($request, $admin));
        $this->assertFalse($request->session()->has(ProjectAccessService::SESSION_KEY));

        $this->expectException(AccessDeniedHttpException::class);
        $service->switchContext($request, $admin, (int) $project->id);
    }

    private function project(string $slug): ClientProject
    {
        $client = Client::create(['name' => 'Client '.$slug, 'slug' => 'client-'.$slug]);

        return ClientProject::create(['client_id' => $client->id, 'name' => 'Project '.$slug, 'slug' => $slug]);
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'username' => $role.'-'.uniqid(),
            'password' => 'password',
            'email' => '',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
