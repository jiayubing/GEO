<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\PublicationBatchStatus;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Models\PublicationBatch;
use App\Models\Task;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskPublicationBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TaskPublicationBatchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_task_articles_share_one_batch_and_submit_only_after_all_reviews_are_final(): void
    {
        [$project, $operator, $task, $first, $second] = $this->fixture('active', 2);
        $second->update(['review_status' => 'pending']);
        $service = app(TaskPublicationBatchService::class);

        $service->recordArticleReviewOutcome((int) $first->id, (int) $operator->id);

        $batch = PublicationBatch::query()->where('task_id', $task->id)->sole();
        $this->assertSame(PublicationBatchStatus::DRAFT, $batch->status);
        $this->assertSame(1, $batch->items()->count());
        $this->assertSame('draft', $first->fresh()->status);
        $this->assertSame(0, $task->fresh()->published_count);

        $second->update(['review_status' => 'approved']);
        $service->recordArticleReviewOutcome((int) $second->id, (int) $operator->id);
        $service->recordArticleReviewOutcome((int) $second->id, (int) $operator->id);

        $batch->refresh();
        $this->assertSame(PublicationBatchStatus::SUBMITTED, $batch->status);
        $this->assertSame(1, PublicationBatch::query()->where('task_id', $task->id)->count());
        $this->assertSame((int) $batch->id, (int) $task->fresh()->publicationBatch?->id);
        $this->assertSame(2, $batch->items()->count());
        $this->assertSame(0, $task->fresh()->published_count);
        $this->assertSame(0, Article::query()->whereIn('id', [$first->id, $second->id])->where('status', 'published')->count());
    }

    public function test_pausing_a_task_submits_its_approved_subset_without_waiting_for_pending_reviews(): void
    {
        [$project, $operator, $task, $approved, $pending] = $this->fixture('active', 5);
        $task->update(['created_count' => 2]);
        $pending->update(['review_status' => 'pending']);
        $service = app(TaskPublicationBatchService::class);

        $service->recordArticleReviewOutcome((int) $approved->id, (int) $operator->id);
        app(TaskLifecycleService::class)->stopTask((int) $task->id, $project, (int) $operator->id);

        $batch = PublicationBatch::query()->where('task_id', $task->id)->sole();
        $this->assertSame(PublicationBatchStatus::SUBMITTED, $batch->status);
        $this->assertSame([$approved->id], $batch->items()->orderBy('article_id')->pluck('article_id')->all());
        $this->assertSame('draft', $approved->fresh()->status);
        $this->assertSame('draft', $pending->fresh()->status);

        try {
            app(TaskLifecycleService::class)->startTask((int) $task->id, false, $project);
            $this->fail('A submitted task batch must keep its task closed.');
        } catch (ApiException $exception) {
            $this->assertSame('publication_task_batch_finalized', $exception->getErrorCode());
            $this->assertSame(409, $exception->getHttpStatus());
        }
    }

    /** @return array{0:ClientProject,1:Admin,2:Task,3:Article,4:Article} */
    private function fixture(string $status, int $articleLimit): array
    {
        $operator = Admin::query()->create([
            'username' => 'task-batch-'.Str::random(8),
            'password' => 'password',
            'email' => '',
            'role' => 'operator',
            'status' => 'active',
        ]);
        $client = Client::query()->create(['name' => 'Task batch client', 'slug' => 'task-batch-'.Str::random(8)]);
        $project = ClientProject::query()->create([
            'client_id' => $client->id,
            'name' => 'Task batch project',
            'slug' => 'task-batch-'.Str::random(8),
        ]);
        ClientProjectMember::query()->create([
            'client_project_id' => $project->id,
            'admin_id' => $operator->id,
            'role' => ClientProjectMemberRole::OPERATOR,
        ]);
        $task = Task::query()->create([
            'name' => 'Task-owned batch',
            'status' => $status,
            'schedule_enabled' => $status === 'active' ? 1 : 0,
            'client_project_id' => $project->id,
            'created_by_admin_id' => $operator->id,
            'created_count' => $articleLimit,
            'article_limit' => $articleLimit,
            'draft_limit' => $articleLimit,
            'publish_scope' => 'local_only',
        ]);
        $category = Category::query()->create(['name' => 'Task batch category', 'slug' => 'task-batch-'.Str::random(8)]);
        $author = Author::query()->create(['name' => 'Task batch author']);

        $first = $this->article($project, $task, $category, $author, 'first');
        $second = $this->article($project, $task, $category, $author, 'second');

        return [$project, $operator, $task, $first, $second];
    }

    private function article(ClientProject $project, Task $task, Category $category, Author $author, string $suffix): Article
    {
        return Article::query()->create([
            'title' => 'Task batch '.$suffix,
            'slug' => 'task-batch-'.$suffix.'-'.Str::random(8),
            'content' => 'Task batch content '.$suffix,
            'excerpt' => 'excerpt',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'client_project_id' => $project->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'central_site_allowed' => true,
        ]);
    }
}
