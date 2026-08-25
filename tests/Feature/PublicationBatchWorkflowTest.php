<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Models\PublicationBatchItem;
use App\Services\GeoFlow\PublicationBatchRecoveryService;
use App\Services\GeoFlow\PublicationBatchService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicationBatchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_submits_project_batch_but_cannot_approve_it(): void
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

    public function test_super_admin_rejects_a_stale_operator_batch(): void
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

    public function test_super_admin_can_approve_operator_batch_and_item_readback_is_scoped(): void
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

    public function test_legacy_platform_approver_cannot_approve_a_batch_through_the_service(): void
    {
        [$project, $operator, , $article] = $this->projectFixture();
        $legacyApprover = $this->admin('platform_approver');
        $service = app(PublicationBatchService::class);
        $batch = $service->createDraft($operator, $project, [[
            'article_id' => $article->id,
            'targets' => [['target_type' => 'local']],
        ]], 'phase-6a2-legacy-approver-denied');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('publication_approver_required');
        $service->approve($legacyApprover, $service->submit($operator, $batch));
    }

    public function test_approved_batch_executes_all_of_its_local_items_in_one_action(): void
    {
        [$project, $operator, $approver, $firstArticle] = $this->projectFixture();
        $secondArticle = Article::query()->create([
            'title' => 'Phase 6A2 Second Article',
            'slug' => 'phase-6a2-'.Str::random(8),
            'content' => 'phase 6a2 second content',
            'excerpt' => 'excerpt',
            'category_id' => $firstArticle->category_id,
            'author_id' => $firstArticle->author_id,
            'client_project_id' => $project->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'central_site_allowed' => true,
        ]);
        $service = app(PublicationBatchService::class);
        $batch = $service->createDraft($operator, $project, [
            ['article_id' => $firstArticle->id, 'targets' => [['target_type' => 'local']]],
            ['article_id' => $secondArticle->id, 'targets' => [['target_type' => 'local']]],
        ], 'phase-6a2-batch-local-execution');
        $approved = $service->approve($approver, $service->submit($operator, $batch));

        $executed = app(PublicationBatchRecoveryService::class)->executeApprovedLocalItems($approved);

        $this->assertSame(PublicationBatchStatus::COMPLETED, $executed->status);
        $this->assertSame([
            PublicationBatchItemStatus::LOCAL_PUBLISHED->value,
            PublicationBatchItemStatus::LOCAL_PUBLISHED->value,
        ], $executed->items->sortBy('id')->map(fn ($item): string => $item->status->value)->values()->all());
        $this->assertDatabaseHas('articles', ['id' => $firstArticle->id, 'status' => 'published']);
        $this->assertDatabaseHas('articles', ['id' => $secondArticle->id, 'status' => 'published']);

        $retried = app(PublicationBatchRecoveryService::class)->executeApprovedLocalItems($executed);

        $this->assertSame(PublicationBatchStatus::COMPLETED, $retried->status);
        $this->assertSame(2, $retried->items->where('status', PublicationBatchItemStatus::LOCAL_PUBLISHED)->count());
    }

    public function test_batch_level_local_execution_leaves_approved_channel_items_untouched(): void
    {
        [$project, $operator, $approver, $article] = $this->projectFixture();
        $service = app(PublicationBatchService::class);
        $batch = $service->createDraft($operator, $project, [[
            'article_id' => $article->id,
            'targets' => [['target_type' => 'local']],
        ]], 'phase-6a2-local-only-execution');
        $approved = $service->approve($approver, $service->submit($operator, $batch));
        $localItem = $approved->items->sole();
        $channelItem = PublicationBatchItem::query()->create([
            'publication_batch_id' => $approved->id,
            'client_project_id' => $project->id,
            'article_id' => $article->id,
            'target_type' => 'channel',
            'target_identity' => 'channel:must-not-execute',
            'action' => 'publish',
            'article_revision' => $localItem->article_revision,
            'article_content_hash' => $localItem->article_content_hash,
            'target_snapshot' => ['target_type' => 'channel'],
            'status' => PublicationBatchItemStatus::APPROVED,
            'idempotency_key' => 'phase-6a2-untouched-channel',
            'created_by_admin_id' => $operator->id,
            'updated_by_admin_id' => $operator->id,
        ]);

        $executed = app(PublicationBatchRecoveryService::class)->executeApprovedLocalItems($approved);

        $this->assertSame(PublicationBatchStatus::PUBLISHING, $executed->status);
        $this->assertDatabaseHas('publication_batch_items', [
            'id' => $localItem->id,
            'status' => PublicationBatchItemStatus::LOCAL_PUBLISHED->value,
        ]);
        $this->assertDatabaseHas('publication_batch_items', [
            'id' => $channelItem->id,
            'status' => PublicationBatchItemStatus::APPROVED->value,
        ]);
        $this->assertDatabaseCount('article_distributions', 0);
    }

    public function test_batch_level_local_execution_preserves_successes_when_an_item_is_stale(): void
    {
        [$project, $operator, $approver, $firstArticle] = $this->projectFixture();
        $secondArticle = Article::query()->create([
            'title' => 'Phase 6A2 stale local article',
            'slug' => 'phase-6a2-'.Str::random(8),
            'content' => 'phase 6a2 stale original content',
            'excerpt' => 'excerpt',
            'category_id' => $firstArticle->category_id,
            'author_id' => $firstArticle->author_id,
            'client_project_id' => $project->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'central_site_allowed' => true,
        ]);
        $service = app(PublicationBatchService::class);
        $batch = $service->createDraft($operator, $project, [
            ['article_id' => $firstArticle->id, 'targets' => [['target_type' => 'local']]],
            ['article_id' => $secondArticle->id, 'targets' => [['target_type' => 'local']]],
        ], 'phase-6a2-local-partial-execution');
        $approved = $service->approve($approver, $service->submit($operator, $batch));
        $secondArticle->update(['content' => 'phase 6a2 stale changed content']);

        $executed = app(PublicationBatchRecoveryService::class)->executeApprovedLocalItems($approved);

        $this->assertSame(PublicationBatchStatus::PARTIAL, $executed->status);
        $this->assertDatabaseHas('publication_batch_items', [
            'publication_batch_id' => $approved->id,
            'article_id' => $firstArticle->id,
            'status' => PublicationBatchItemStatus::LOCAL_PUBLISHED->value,
        ]);
        $this->assertDatabaseHas('publication_batch_items', [
            'publication_batch_id' => $approved->id,
            'article_id' => $secondArticle->id,
            'status' => PublicationBatchItemStatus::FAILED->value,
            'failure_code' => 'publication_item_stale',
        ]);
        $this->assertDatabaseHas('articles', ['id' => $firstArticle->id, 'status' => 'published']);
        $this->assertDatabaseHas('articles', ['id' => $secondArticle->id, 'status' => 'draft']);
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
        $approver = $this->admin('super_admin');
        $client = Client::create(['name' => 'Phase 6A2 Client', 'slug' => 'phase-6a2-'.Str::random(5)]);
        $project = ClientProject::create(['client_id' => $client->id, 'name' => 'Phase 6A2 Project', 'slug' => 'phase-6a2-'.Str::random(5)]);
        ClientProjectMember::create(['client_project_id' => $project->id, 'admin_id' => $operator->id, 'role' => ClientProjectMemberRole::OPERATOR]);
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
