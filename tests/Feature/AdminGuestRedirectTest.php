<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Services\GeoFlow\ProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_is_redirected_from_login_to_dashboard(): void
    {
        $admin = Admin::query()->create([
            'username' => 'redirect_admin',
            'password' => 'password',
            'email' => 'redirect_admin@example.com',
            'display_name' => 'Redirect Admin',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_authenticated_admin_entry_redirects_to_dashboard(): void
    {
        $admin = Admin::query()->create([
            'username' => 'entry_redirect_admin',
            'password' => 'password',
            'email' => 'entry_redirect_admin@example.com',
            'display_name' => 'Entry Redirect Admin',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.entry'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_authenticated_operator_is_redirected_from_login_to_its_project_articles(): void
    {
        $operator = $this->operator();
        $project = $this->projectFor($operator);

        $this->actingAs($operator, 'admin')
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHas(ProjectAccessService::SESSION_KEY, $project->id);
    }

    public function test_authenticated_operator_entry_redirects_to_its_project_articles(): void
    {
        $operator = $this->operator();
        $project = $this->projectFor($operator);

        $this->actingAs($operator, 'admin')
            ->get(route('admin.entry'))
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHas(ProjectAccessService::SESSION_KEY, $project->id);
    }

    public function test_operator_login_redirects_to_its_project_articles(): void
    {
        $operator = $this->operator();
        $project = $this->projectFor($operator);

        $this->post(route('admin.login.attempt'), [
            'username' => $operator->username,
            'password' => 'password',
        ])
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHas(ProjectAccessService::SESSION_KEY, $project->id);
    }

    public function test_operator_without_an_accessible_project_is_redirected_to_project_creation(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator, 'admin')
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.client-projects.create'));
    }

    public function test_operator_direct_dashboard_request_is_redirected_to_its_project_articles(): void
    {
        $operator = $this->operator();
        $project = $this->projectFor($operator);

        $this->actingAs($operator, 'admin')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.articles.index'))
            ->assertSessionHas(ProjectAccessService::SESSION_KEY, $project->id);
    }

    public function test_guest_admin_login_flow_is_unchanged(): void
    {
        $this->get(route('admin.login'))
            ->assertOk();

        $this->get(route('admin.entry'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_operator_can_log_out_without_a_project_context(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
        $this->get(route('admin.login'))->assertOk();
    }

    private function operator(): Admin
    {
        return Admin::query()->create([
            'username' => 'redirect_operator',
            'password' => 'password',
            'email' => 'redirect_operator@example.com',
            'display_name' => 'Redirect Operator',
            'role' => 'operator',
            'status' => 'active',
        ]);
    }

    private function projectFor(Admin $operator): ClientProject
    {
        $client = Client::query()->create([
            'name' => 'Redirect Client',
            'slug' => 'redirect-client',
        ]);
        $project = ClientProject::query()->create([
            'client_id' => $client->id,
            'name' => 'Redirect Project',
            'slug' => 'redirect-project',
        ]);
        ClientProjectMember::query()->create([
            'client_project_id' => $project->id,
            'admin_id' => $operator->id,
            'role' => ClientProjectMemberRole::OPERATOR,
            'status' => ClientProjectMemberStatus::ACTIVE,
        ]);

        return $project;
    }
}
