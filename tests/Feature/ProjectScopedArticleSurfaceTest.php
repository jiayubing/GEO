<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Models\Task;
use App\Services\GeoFlow\ProjectAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectScopedArticleSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_article_list_is_scoped_to_selected_project(): void
    {
        $admin = Admin::query()->create([
            'username' => 'project-operator',
            'password' => 'secret-123',
            'email' => 'project-operator@example.com',
            'display_name' => 'Project Operator',
            'role' => 'admin',
            'status' => 'active',
        ]);
        [$projectA, $projectB] = [$this->project('a'), $this->project('b')];
        ClientProjectMember::query()->create([
            'client_project_id' => $projectA->id,
            'admin_id' => $admin->id,
            'role' => ClientProjectMemberRole::OPERATOR,
            'status' => ClientProjectMemberStatus::ACTIVE,
        ]);

        $this->article($projectA, 'Project A Article');
        $this->article($projectB, 'Project B Article');

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('Project A Article')
            ->assertDontSee('Project B Article');
    }

    public function test_operator_is_rejected_without_project_context(): void
    {
        $admin = Admin::query()->create([
            'username' => 'contextless-operator',
            'password' => 'secret-123',
            'email' => 'contextless-operator@example.com',
            'display_name' => 'Contextless Operator',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertForbidden();
    }

    public function test_operator_task_monitoring_is_scoped_to_selected_project(): void
    {
        [$admin, $projectA] = $this->operatorWithProject('task-monitor');
        $projectB = $this->project('task-monitor-b');
        Task::query()->create(['name' => 'Project A Task', 'status' => 'active', 'client_project_id' => $projectA->id]);
        Task::query()->create(['name' => 'Project B Task', 'status' => 'active', 'client_project_id' => $projectB->id]);

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->get(route('admin.tasks.index'))
            ->assertOk()
            ->assertSee('Project A Task')
            ->assertDontSee('Project B Task');

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->get(route('admin.tasks.health'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissing(['name' => 'Project B Task']);
    }

    public function test_operator_can_open_task_create_form_in_project_context(): void
    {
        [$admin, $project] = $this->operatorWithProject('task-form');

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->get(route('admin.tasks.create'))
            ->assertOk()
            ->assertSee(__('admin.task_create.page_heading'));
    }

    private function project(string $slug): ClientProject
    {
        $client = Client::query()->create(['name' => 'Client '.$slug, 'slug' => 'client-'.$slug]);

        return ClientProject::query()->create([
            'client_id' => $client->id,
            'name' => 'Project '.$slug,
            'slug' => 'project-'.$slug,
        ]);
    }

    /** @return array{0: Admin, 1: ClientProject} */
    private function operatorWithProject(string $slug): array
    {
        $admin = Admin::query()->create([
            'username' => $slug.'-operator',
            'password' => 'secret-123',
            'email' => $slug.'-operator@example.com',
            'display_name' => 'Project Operator',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $project = $this->project($slug);
        ClientProjectMember::query()->create([
            'client_project_id' => $project->id,
            'admin_id' => $admin->id,
            'role' => ClientProjectMemberRole::OPERATOR,
            'status' => ClientProjectMemberStatus::ACTIVE,
        ]);

        return [$admin, $project];
    }

    private function article(ClientProject $project, string $title): Article
    {
        $category = Category::query()->create([
            'name' => $title.' Category',
            'slug' => strtolower(str_replace(' ', '-', $title)).'-category',
            'client_project_id' => $project->id,
        ]);
        $author = Author::query()->create([
            'name' => $title.' Author',
            'client_project_id' => $project->id,
        ]);
        $task = Task::query()->create([
            'name' => $title.' Task',
            'status' => 'active',
            'client_project_id' => $project->id,
        ]);

        return Article::query()->create([
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'content' => 'Content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'client_project_id' => $project->id,
        ]);
    }
}
