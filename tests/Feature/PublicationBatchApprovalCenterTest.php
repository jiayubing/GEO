<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Models\PublicationBatch;
use App\Services\GeoFlow\ProjectAccessService;
use App\Services\GeoFlow\PublicationBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicationBatchApprovalCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_read_all_submitted_batches_without_project_context(): void
    {
        $superAdmin = $this->admin('super_admin', 'platform-reviewer');
        [$firstProject, $firstSubmitter] = $this->projectWithSubmitter('approval-north');
        [$secondProject, $secondSubmitter] = $this->projectWithSubmitter('approval-south');
        $firstBatch = $this->submittedBatch($firstProject, $firstSubmitter, 'approval-north');
        $secondBatch = $this->submittedBatch($secondProject, $secondSubmitter, 'approval-south');
        $draft = PublicationBatch::query()->create([
            'client_project_id' => $firstProject->id,
            'status' => PublicationBatchStatus::DRAFT,
            'idempotency_key' => 'approval-draft',
            'created_by_admin_id' => $firstSubmitter->id,
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $firstProject->id])
            ->get(route('admin.publication-batch-approvals.index'))
            ->assertOk()
            ->assertSee(__('admin.nav.publication_approvals'))
            ->assertSee('#'.$firstBatch->id)
            ->assertSee('#'.$secondBatch->id)
            ->assertSee($firstProject->client->name)
            ->assertSee($secondProject->client->name)
            ->assertSee($firstProject->name)
            ->assertSee($secondProject->name)
            ->assertSee($firstSubmitter->name)
            ->assertSee($secondSubmitter->name)
            ->assertDontSee(route('admin.publication-batch-approvals.show', ['batchId' => $draft->id]));

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.publication-batch-approvals.show', ['batchId' => $secondBatch->id]))
            ->assertOk()
            ->assertSee($secondProject->client->name)
            ->assertSee($secondProject->name)
            ->assertSee($secondSubmitter->name)
            ->assertSee(route('admin.publication-batch-approvals.approve', ['batchId' => $secondBatch->id]))
            ->assertDontSee('新建批次')
            ->assertDontSee('提交审核');
    }

    public function test_legacy_platform_approver_cannot_access_or_process_batches(): void
    {
        $approver = $this->admin('platform_approver', 'global-approver');
        [$project, $submitter] = $this->projectWithSubmitter('approval-global-role');
        $batch = $this->submittedBatch($project, $submitter, 'approval-global-role');

        $this->actingAs($approver, 'admin')
            ->get(route('admin.publication-batch-approvals.index'))
            ->assertForbidden();

        $this->actingAs($approver, 'admin')
            ->post(route('admin.publication-batch-approvals.return', ['batchId' => $batch->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('publication_batches', [
            'id' => $batch->id,
            'status' => PublicationBatchStatus::SUBMITTED->value,
        ]);
    }

    public function test_super_admin_is_redirected_from_the_legacy_project_batch_list_to_the_global_approval_center(): void
    {
        $superAdmin = $this->admin('super_admin', 'legacy-batch-list-super-admin');
        $platformApprover = $this->admin('platform_approver', 'legacy-batch-list-platform-approver');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.publication-batches.index'))
            ->assertRedirect(route('admin.publication-batch-approvals.index'));

        $this->actingAs($platformApprover, 'admin')
            ->get(route('admin.publication-batches.index'))
            ->assertForbidden();
    }

    public function test_operator_cannot_access_or_act_through_the_platform_approval_center(): void
    {
        [$project, $operator] = $this->projectWithSubmitter('approval-operator-denied');
        $batch = $this->submittedBatch($project, $operator, 'approval-operator-denied');

        $this->actingAs($operator, 'admin')
            ->get(route('admin.publication-batch-approvals.index'))
            ->assertForbidden();

        $this->actingAs($operator, 'admin')
            ->get(route('admin.publication-batch-approvals.show', ['batchId' => $batch->id]))
            ->assertForbidden();

        $this->actingAs($operator, 'admin')
            ->post(route('admin.publication-batch-approvals.approve', ['batchId' => $batch->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('publication_batches', [
            'id' => $batch->id,
            'status' => PublicationBatchStatus::SUBMITTED->value,
        ]);
    }

    public function test_only_super_admin_can_start_batch_level_local_execution(): void
    {
        $superAdmin = $this->admin('super_admin', 'batch-local-executor');
        $nonSuperAdmin = $this->admin('platform_approver', 'batch-local-approver');
        [$project, $submitter] = $this->projectWithSubmitter('batch-local-execution');
        $article = Article::query()->create([
            'title' => 'Batch local execution article',
            'slug' => 'batch-local-execution-'.Str::random(8),
            'content' => 'Batch local execution content',
            'excerpt' => 'excerpt',
            'category_id' => Category::query()->create(['name' => 'Batch local category', 'slug' => 'batch-local-'.Str::random(8)])->id,
            'author_id' => Author::query()->create(['name' => 'Batch local author'])->id,
            'client_project_id' => $project->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'central_site_allowed' => true,
        ]);
        $service = app(PublicationBatchService::class);
        $batch = $service->createDraft($submitter, $project, [[
            'article_id' => $article->id,
            'targets' => [['target_type' => 'local']],
        ]], 'batch-local-execution');
        $approved = $service->approve($superAdmin, $service->submit($submitter, $batch));

        $this->actingAs($nonSuperAdmin, 'admin')
            ->post(route('admin.publication-batch-approvals.execute-local', ['batchId' => $approved->id]))
            ->assertForbidden();

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.publication-batch-approvals.show', ['batchId' => $approved->id]))
            ->assertOk()
            ->assertSee('一键发布本地文章（1 篇）')
            ->assertDontSee('执行本地发布');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.publication-batch-approvals.execute-local', ['batchId' => $approved->id]))
            ->assertRedirect(route('admin.publication-batch-approvals.show', ['batchId' => $approved->id]))
            ->assertSessionHas('message', 'publication_batch_local_batch_executed');

        $this->assertDatabaseHas('publication_batch_items', [
            'publication_batch_id' => $approved->id,
            'status' => PublicationBatchItemStatus::LOCAL_PUBLISHED->value,
        ]);
        $this->assertDatabaseHas('articles', ['id' => $article->id, 'status' => 'published']);
    }

    /** @return array{0: ClientProject, 1: Admin} */
    private function projectWithSubmitter(string $suffix): array
    {
        $submitter = $this->admin('operator', $suffix.'-operator');
        $client = Client::query()->create([
            'name' => 'Client '.$suffix,
            'slug' => 'client-'.Str::slug($suffix),
        ]);
        $project = ClientProject::query()->create([
            'client_id' => $client->id,
            'name' => 'Project '.$suffix,
            'slug' => 'project-'.Str::slug($suffix),
        ]);
        ClientProjectMember::query()->create([
            'client_project_id' => $project->id,
            'admin_id' => $submitter->id,
            'role' => ClientProjectMemberRole::OPERATOR,
            'status' => ClientProjectMemberStatus::ACTIVE,
        ]);

        return [$project->load('client'), $submitter];
    }

    private function submittedBatch(ClientProject $project, Admin $submitter, string $key): PublicationBatch
    {
        return PublicationBatch::query()->create([
            'client_project_id' => $project->id,
            'status' => PublicationBatchStatus::SUBMITTED,
            'idempotency_key' => $key,
            'created_by_admin_id' => $submitter->id,
            'submitted_by_admin_id' => $submitter->id,
            'submitted_at' => now(),
            'status_changed_at' => now(),
        ]);
    }

    private function admin(string $role, string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => Str::headline(str_replace('-', ' ', $username)),
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
