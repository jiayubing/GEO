<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Services\GeoFlow\ClientProjectCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class ClientProjectCreationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_creation_is_atomic_and_sets_context(): void
    {
        $admin = $this->admin('operator');
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());

        $project = app(ClientProjectCreationService::class)->create($admin, 'Acme Client', 'Main Site', $request);

        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, ClientProject::query()->count());
        $this->assertSame(1, ClientProjectMember::query()->count());
        $this->assertSame($project->id, $request->session()->get('geoflow.project_context_id'));
        $this->assertSame('operator', $project->members()->firstOrFail()->role->value);
    }

    public function test_retry_returns_existing_project_without_duplicate_records(): void
    {
        $admin = $this->admin('operator');
        $service = app(ClientProjectCreationService::class);
        $firstRequest = Request::create('/');
        $firstRequest->setLaravelSession(app('session')->driver());
        $first = $service->create($admin, 'Acme Client', 'Main Site', $firstRequest);
        $retry = Request::create('/');
        $retry->setLaravelSession(app('session')->driver());

        $second = $service->create($admin, 'Acme Client', 'Main Site', $retry);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, ClientProjectMember::query()->count());
    }

    public function test_regular_admin_can_create_project(): void
    {
        $admin = $this->admin('admin');
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());

        $project = app(ClientProjectCreationService::class)->create($admin, '普通管理员客户', '运营试点项目', $request);

        $this->assertSame($admin->id, $project->created_by_admin_id);
        $this->assertSame('operator', $project->members()->firstOrFail()->role->value);
    }

    public function test_non_operator_cannot_create(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $admin = $this->admin('super_admin');
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        app(ClientProjectCreationService::class)->create($admin, 'Acme Client', 'Main Site', $request);
    }

    private function admin(string $role): Admin
    {
        return Admin::create(['username' => $role.'-'.uniqid(), 'password' => 'password', 'email' => '', 'role' => $role, 'status' => 'active']);
    }
}
