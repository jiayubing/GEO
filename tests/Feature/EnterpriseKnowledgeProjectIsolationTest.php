<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Enums\ClientProjectStatus;
use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Models\EnterpriseKnowledgeSource;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\ProjectAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnterpriseKnowledgeProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_operator_list_and_resource_routes_are_scoped_to_selected_project(): void
    {
        [$admin, $projectA] = $this->operatorWithProject('scope-a');
        $projectB = $this->project('scope-b');
        $visible = $this->enterpriseProject($projectA, 'Project A Enterprise');
        $hidden = $this->enterpriseProject($projectB, 'Project B Enterprise');
        $foreignRevision = EnterpriseKnowledgeRevision::query()->create([
            'enterprise_knowledge_project_id' => $hidden->id,
            'content' => $this->draft('Foreign revision'),
            'source' => 'manual',
            'content_hash' => hash('sha256', $this->draft('Foreign revision')),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->get(route('admin.enterprise-knowledge.index'))
            ->assertOk()
            ->assertSee('Project A Enterprise')
            ->assertDontSee('Project B Enterprise');

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->get(route('admin.enterprise-knowledge.show', ['projectId' => $hidden->id]))
            ->assertNotFound();
        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->getJson(route('admin.enterprise-knowledge.status', ['projectId' => $hidden->id]))
            ->assertNotFound();
        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->postJson(route('admin.enterprise-knowledge.autosave', ['projectId' => $hidden->id]), ['content' => $this->draft('Attack')])
            ->assertNotFound();
        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->post(route('admin.enterprise-knowledge.revisions.restore', [
                'projectId' => $visible->id,
                'revisionId' => $foreignRevision->id,
            ]))
            ->assertNotFound();
        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->post(route('admin.enterprise-knowledge.publish', ['projectId' => $hidden->id]))
            ->assertNotFound();
        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->post(route('admin.enterprise-knowledge.delete', ['projectId' => $hidden->id]))
            ->assertNotFound();

        $this->assertModelExists($hidden);
        $this->assertSame('Project B Enterprise', (string) $hidden->fresh()->name);
    }

    public function test_store_uses_selected_project_owner_and_dispatches_id_only_job(): void
    {
        Queue::fake();
        [$admin, $project] = $this->operatorWithProject('create');
        $secretContent = 'private-enterprise-body-'.uniqid();

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->post(route('admin.enterprise-knowledge.store'), [
                'name' => 'Owned Enterprise Knowledge',
                'content' => $secretContent,
            ])
            ->assertRedirect();

        $enterpriseProject = EnterpriseKnowledgeProject::query()->where('name', 'Owned Enterprise Knowledge')->firstOrFail();
        $this->assertSame((int) $project->id, (int) $enterpriseProject->client_project_id);
        Queue::assertPushed(GenerateEnterpriseKnowledgeDraftJob::class, function (GenerateEnterpriseKnowledgeDraftJob $job) use ($enterpriseProject, $secretContent): bool {
            $serialized = serialize($job);

            return $job->projectId === (int) $enterpriseProject->id
                && ! str_contains($serialized, $secretContent)
                && ! str_contains($serialized, 'client_project_id');
        });
    }

    public function test_viewer_and_inactive_project_cannot_mutate_enterprise_knowledge(): void
    {
        [$viewer, $project] = $this->memberWithProject('viewer', ClientProjectMemberRole::VIEWER);
        $enterpriseProject = $this->enterpriseProject($project, 'Viewer Project');

        $this->actingAs($viewer, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->postJson(route('admin.enterprise-knowledge.autosave', ['projectId' => $enterpriseProject->id]), [
                'content' => $this->draft('Viewer edit'),
            ])
            ->assertForbidden();

        $project->update(['status' => ClientProjectStatus::SUSPENDED]);
        $this->actingAs($viewer, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->get(route('admin.enterprise-knowledge.show', ['projectId' => $enterpriseProject->id]))
            ->assertForbidden();
    }

    public function test_stale_autosave_cannot_overwrite_a_newer_revision(): void
    {
        [$admin, $project] = $this->operatorWithProject('stale-save');
        $enterpriseProject = $this->enterpriseProject($project, 'Stale Save Project');
        $originalHash = hash('sha256', (string) $enterpriseProject->draft_content);
        $newerContent = $this->draft('Newer content');

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->postJson(route('admin.enterprise-knowledge.autosave', ['projectId' => $enterpriseProject->id]), [
                'content' => $newerContent,
                'base_hash' => $originalHash,
            ])
            ->assertOk()
            ->assertJsonPath('content_hash', hash('sha256', $newerContent));

        $this->actingAs($admin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->postJson(route('admin.enterprise-knowledge.autosave', ['projectId' => $enterpriseProject->id]), [
                'content' => $this->draft('Older delayed content'),
                'base_hash' => $originalHash,
            ])
            ->assertStatus(409);

        $this->assertSame($newerContent, (string) $enterpriseProject->fresh()->draft_content);
        $this->assertSame(1, EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', $enterpriseProject->id)
            ->where('source', 'manual')
            ->count());
    }

    public function test_draft_job_rechecks_active_owner_and_is_idempotent(): void
    {
        [$admin, $project] = $this->operatorWithProject('job');
        $enterpriseProject = $this->enterpriseProject($project, 'Job Project', 'queued');
        EnterpriseKnowledgeSource::query()->create([
            'enterprise_knowledge_project_id' => $enterpriseProject->id,
            'original_name' => 'source.md',
            'file_type' => 'markdown',
            'content' => '# 企业介绍'."\n".'企业提供项目隔离的知识服务。',
            'character_count' => 22,
            'sort_order' => 0,
        ]);
        $job = new GenerateEnterpriseKnowledgeDraftJob((int) $enterpriseProject->id, (int) $admin->id);

        $job->handle(app(EnterpriseKnowledgeDraftService::class));
        $job->handle(app(EnterpriseKnowledgeDraftService::class));

        $this->assertSame('reviewing', (string) $enterpriseProject->fresh()->status);
        $this->assertSame(1, EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', $enterpriseProject->id)
            ->count());

        $inactiveProject = $this->project('job-inactive');
        $inactiveProject->update(['status' => ClientProjectStatus::SUSPENDED]);
        $inactiveEnterprise = $this->enterpriseProject($inactiveProject, 'Inactive Job', 'queued');
        (new GenerateEnterpriseKnowledgeDraftJob((int) $inactiveEnterprise->id, (int) $admin->id))
            ->handle(app(EnterpriseKnowledgeDraftService::class));

        $this->assertSame('failed', (string) $inactiveEnterprise->fresh()->status);
        $this->assertSame('enterprise_knowledge_project_inactive', (string) $inactiveEnterprise->fresh()->error_message);
        $this->assertDatabaseMissing('enterprise_knowledge_revisions', [
            'enterprise_knowledge_project_id' => $inactiveEnterprise->id,
        ]);
    }

    public function test_draft_job_retries_when_an_execution_lock_is_already_held(): void
    {
        [$admin, $project] = $this->operatorWithProject('job-lock');
        $enterpriseProject = $this->enterpriseProject($project, 'Lock Contention', 'queued');
        $lock = Cache::lock('enterprise-knowledge-draft:'.$enterpriseProject->id, 60);
        $this->assertTrue($lock->get());

        try {
            (new GenerateEnterpriseKnowledgeDraftJob((int) $enterpriseProject->id, (int) $admin->id))
                ->handle(app(EnterpriseKnowledgeDraftService::class));
            $this->fail('A job that cannot obtain its execution lock must be retried instead of acknowledged.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('enterprise_knowledge_draft_lock_unavailable', $exception->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertSame('queued', (string) $enterpriseProject->fresh()->status);
    }

    public function test_repeated_publish_reuses_same_owned_knowledge_base_and_revision(): void
    {
        [$admin, $project] = $this->operatorWithProject('publish');
        $enterpriseProject = $this->enterpriseProject($project, 'Published Project');
        $this->mock(KnowledgeChunkSyncCoordinator::class, function ($mock): void {
            $mock->shouldReceive('request')->twice()->andReturnTrue();
        });

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($admin, 'admin')
                ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
                ->post(route('admin.enterprise-knowledge.publish', ['projectId' => $enterpriseProject->id]))
                ->assertRedirect();
        }

        $enterpriseProject->refresh();
        $knowledgeBase = KnowledgeBase::query()->findOrFail($enterpriseProject->published_knowledge_base_id);
        $this->assertSame((int) $project->id, (int) $knowledgeBase->client_project_id);
        $this->assertSame(1, KnowledgeBase::query()->where('client_project_id', $project->id)->count());
        $this->assertSame(1, EnterpriseKnowledgeRevision::query()
            ->where('enterprise_knowledge_project_id', $enterpriseProject->id)
            ->where('source', 'publish')
            ->count());
    }

    public function test_published_knowledge_base_delete_cannot_erase_enterprise_project_owner(): void
    {
        $project = $this->project('published-delete');
        $knowledgeBase = KnowledgeBase::query()->create([
            'client_project_id' => $project->id,
            'name' => 'Published Knowledge Base',
            'content' => '# Published',
            'character_count' => 11,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 11,
            'usage_count' => 0,
        ]);
        $enterpriseProject = EnterpriseKnowledgeProject::query()->create([
            'client_project_id' => $project->id,
            'name' => 'Enterprise Owner Must Survive',
            'status' => 'published',
            'draft_content' => $this->draft('Published'),
            'published_knowledge_base_id' => $knowledgeBase->id,
        ]);

        try {
            $knowledgeBase->delete();
            $this->fail('Deleting a published knowledge base should be restricted.');
        } catch (QueryException) {
            // The database owner/relationship constraint is the expected gate.
        }

        $this->assertModelExists($knowledgeBase);
        $this->assertSame((int) $project->id, (int) $enterpriseProject->fresh()->client_project_id);
        $this->assertSame((int) $knowledgeBase->id, (int) $enterpriseProject->fresh()->published_knowledge_base_id);
    }

    /** @return array{0: Admin, 1: ClientProject} */
    private function operatorWithProject(string $slug): array
    {
        return $this->memberWithProject($slug, ClientProjectMemberRole::OPERATOR);
    }

    /** @return array{0: Admin, 1: ClientProject} */
    private function memberWithProject(string $slug, ClientProjectMemberRole $role): array
    {
        $admin = Admin::query()->create([
            'username' => $slug.'-admin',
            'password' => 'secret-123',
            'email' => $slug.'-admin@example.com',
            'display_name' => 'Enterprise Project Member',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $project = $this->project($slug);
        ClientProjectMember::query()->create([
            'client_project_id' => $project->id,
            'admin_id' => $admin->id,
            'role' => $role,
            'status' => ClientProjectMemberStatus::ACTIVE,
        ]);

        return [$admin, $project];
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

    private function enterpriseProject(ClientProject $project, string $name, string $status = 'reviewing'): EnterpriseKnowledgeProject
    {
        return EnterpriseKnowledgeProject::query()->create([
            'client_project_id' => $project->id,
            'name' => $name,
            'status' => $status,
            'draft_content' => $status === 'queued' ? '' : $this->draft($name),
        ]);
    }

    private function draft(string $heading): string
    {
        return <<<MARKDOWN
# {$heading}

## 企业介绍
企业提供项目隔离的知识服务。

## 业务信息摘要
资料仅归属于当前客户项目。

## 产品能力
- 知识整理

## 应用场景
- 内容生产

## 典型案例
- 待人工确认

## FAQ
### 是否跨项目共享？
不会。

## 禁用表述
- 不作绝对承诺。

## 风险与冲突
- 需人工复核。

## 待人工确认
- 公开范围。
MARKDOWN;
    }
}
