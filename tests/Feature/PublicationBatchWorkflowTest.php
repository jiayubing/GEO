<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\PublicationBatchStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Services\GeoFlow\PublicationBatchService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicationBatchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_submits_project_batch_and_platform_approver_can_approve_it(): void
    {
        [$project, $operator, $approver, $article] = $this->projectFixture();
        $service = app(PublicationBatchService::class);
        $selection = [['article_id' => $article->id, 'targets' => [['target_type' => 'local']]]];

        $draft = $service->createDraft($operator, $project, $selection, 'phase-6a2-e2e');
        $retry = $service->createDraft($operator, $project, $selection, 'phase-6a2-e2e');

        $this->assertSame($draft->id, $retry->id);
        $item = $draft->items->firstOrFail();
        $this->assertSame(hash('sha256', $article->content), $item->article_content_hash);
        $this->assertSame($project->id, $item->target_snapshot['project_id']);

        $submitted = $service->submit($operator, $draft);
        $this->assertSame(PublicationBatchStatus::SUBMITTED, $submitted->status);
        $this->assertSame($operator->id, $submitted->submitted_by_admin_id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('publication_approver_required');
        $service->approve($operator, $submitted);
    }

    public function test_platform_approver_reads_other_operator_batch_but_stale_article_is_rejected(): void
    {
        [$project, $operator, $approver, $article] = $this->projectFixture();
        $service = app(PublicationBatchService::class);
        $batch = $service->createDraft($operator, $project, [[
            'article_id' => $article->id,
            'targets' => [['target_type' => 'local']],
        ]], 'phase-6a2-stale');
        $submitted = $service->submit($operator, $batch);

        $article->update(['content' => 'edited after submission']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('publication_item_stale');
        $service->approve($approver, $submitted);
    }

    public function test_platform_approver_can_approve_operator_batch_and_item_readback_is_scoped(): void
    {
        [$project, $operator, $approver, $article] = $this->projectFixture();
        $service = app(PublicationBatchService::class);
        $batch = $service->createDraft($operator, $project, [[
            'article_id' => $article->id,
            'targets' => [['target_type' => 'local']],
        ]], 'phase-6a2-approval');
        $submitted = $service->submit($operator, $batch);

        $approved = $service->approve($approver, $submitted);

        $this->assertSame(PublicationBatchStatus::APPROVED, $approved->status);
        $this->assertSame($approver->id, $approved->approved_by_admin_id);
        $this->assertSame('approved', $approved->items->firstOrFail()->status->value);
        $this->assertSame($project->id, $approved->items->firstOrFail()->client_project_id);
    }

    public function test_operator_cannot_add_an_article_from_another_project(): void
    {
        [$project, $operator] = $this->projectFixture();
        [$otherProject, , , $otherArticle] = $this->projectFixture();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('publication_article_project_mismatch');

        app(PublicationBatchService::class)->createDraft($operator, $project, [[
            'article_id' => $otherArticle->id,
            'targets' => [['target_type' => 'local']],
        ]], 'phase-6a2-cross-project');
    }

    /** @return array{0:ClientProject,1:Admin,2:Admin,3:Article} */
    private function projectFixture(): array
    {
        $operator = $this->admin('operator');
        $approver = $this->admin('platform_approver');
        $client = Client::create(['name' => 'Phase 6A2 Client', 'slug' => 'phase-6a2-'.Str::random(5)]);
        $project = ClientProject::create(['client_id' => $client->id, 'name' => 'Phase 6A2 Project', 'slug' => 'phase-6a2-'.Str::random(5)]);
        ClientProjectMember::create(['client_project_id' => $project->id, 'admin_id' => $operator->id, 'role' => ClientProjectMemberRole::OPERATOR]);
        ClientProjectMember::create(['client_project_id' => $project->id, 'admin_id' => $approver->id, 'role' => ClientProjectMemberRole::OPERATOR]);
        $category = Category::create(['name' => 'Phase 6A2 Category', 'slug' => 'phase-6a2-'.Str::random(5)]);
        $author = Author::create(['name' => 'Phase 6A2 Author']);
        $article = Article::create([
            'title' => 'Phase 6A2 Article', 'slug' => 'phase-6a2-'.Str::random(8), 'content' => 'phase 6a2 content',
            'excerpt' => 'excerpt', 'category_id' => $category->id, 'author_id' => $author->id,
            'client_project_id' => $project->id, 'status' => 'draft', 'review_status' => 'approved', 'central_site_allowed' => true,
        ]);

        return [$project, $operator, $approver, $article];
    }

    private function admin(string $role): Admin
    {
        return Admin::create(['username' => $role.'-'.Str::random(8), 'password' => 'password', 'email' => '', 'role' => $role, 'status' => 'active']);
    }
}
