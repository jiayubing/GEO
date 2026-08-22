<?php

namespace Tests\Feature;

use App\Events\Admin\TasksOverviewUpdated;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Client;
use App\Models\ClientProject;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskMonitoringMemoryBoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_hydrates_only_the_latest_run_for_each_task(): void
    {
        $task = Task::query()->create([
            'name' => '长历史任务',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        foreach (range(1, 80) as $index) {
            TaskRun::query()->create([
                'task_id' => $task->id,
                'status' => $index === 80 ? 'failed' : 'completed',
                'error_message' => $index === 80 ? '最后一次失败' : '',
                'meta' => [],
                'finished_at' => now(),
            ]);
        }

        $retrievedRuns = 0;
        Event::listen('eloquent.retrieved: '.TaskRun::class, function () use (&$retrievedRuns): void {
            $retrievedRuns++;
        });

        $snapshot = app(TaskMonitoringQueryService::class)->buildTaskSnapshot();

        $this->assertCount(1, $snapshot);
        $this->assertSame('failed', $snapshot[0]['latest_job_status']);
        $this->assertSame('最后一次失败', $snapshot[0]['batch_error_message']);
        $this->assertLessThanOrEqual(2, $retrievedRuns);
    }

    public function test_worker_overview_marks_old_heartbeats_as_stale_and_exposes_memory(): void
    {
        if (! Schema::hasTable('worker_heartbeats')) {
            Schema::create('worker_heartbeats', function (Blueprint $table): void {
                $table->string('worker_id')->primary();
                $table->string('status', 20);
                $table->timestamp('last_seen_at')->nullable();
                $table->text('meta')->nullable();
                $table->timestamps();
            });
        }

        DB::table('worker_heartbeats')->insert([
            'worker_id' => 'worker-stale',
            'status' => 'running',
            'last_seen_at' => now()->subMinutes(5),
            'meta' => json_encode([
                'task_run_id' => 77,
                'memory_mb' => 96.5,
                'peak_memory_mb' => 112.25,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $worker = app(TaskMonitoringQueryService::class)
            ->buildAdminOverview()['worker_overview'][0];

        $this->assertSame('stale', $worker['status']);
        $this->assertTrue($worker['is_stale']);
        $this->assertSame(77, $worker['current_job_id']);
        $this->assertSame(96.5, $worker['memory_mb']);
        $this->assertSame(112.25, $worker['peak_memory_mb']);
    }

    public function test_admin_overview_and_snapshot_keep_task_hydration_bounded(): void
    {
        foreach (range(1, 130) as $index) {
            Task::query()->create([
                'name' => '有界任务 '.$index,
                'status' => $index % 2 === 0 ? 'active' : 'paused',
                'schedule_enabled' => 1,
            ]);
        }

        $service = app(TaskMonitoringQueryService::class);
        $overview = $service->buildAdminOverview(2, 50);
        $snapshot = $service->buildTaskSnapshot();

        $this->assertCount(50, $overview['tasks']);
        $this->assertSame(2, $overview['pagination']['page']);
        $this->assertSame(130, $overview['pagination']['total']);
        $this->assertSame(3, $overview['pagination']['total_pages']);
        $this->assertSame(130, $overview['task_summary']['total_tasks']);
        $this->assertSame(65, $overview['task_summary']['enabled_tasks']);
        $this->assertCount(100, $snapshot);
    }

    public function test_realtime_event_contains_only_a_lightweight_refresh_signal(): void
    {
        $payload = (new TasksOverviewUpdated('2026-07-28T12:00:00+08:00'))->broadcastWith();

        $this->assertSame([
            'refresh_required' => true,
            'changed_at' => '2026-07-28T12:00:00+08:00',
        ], $payload);
        $this->assertArrayNotHasKey('tasks', $payload);
    }

    public function test_project_overview_filters_tasks_runs_articles_and_queue_counts_in_sql(): void
    {
        $client = Client::query()->create(['name' => '监控客户', 'slug' => 'monitoring-client']);
        $first = ClientProject::query()->create(['client_id' => $client->id, 'name' => '项目一', 'slug' => 'project-one', 'status' => 'active']);
        $second = ClientProject::query()->create(['client_id' => $client->id, 'name' => '项目二', 'slug' => 'project-two', 'status' => 'active']);
        $taskOne = Task::query()->create(['name' => '项目一任务', 'status' => 'active', 'schedule_enabled' => 1, 'client_project_id' => $first->id]);
        $taskTwo = Task::query()->create(['name' => '项目二任务', 'status' => 'active', 'schedule_enabled' => 1, 'client_project_id' => $second->id]);
        TaskRun::query()->create(['task_id' => $taskOne->id, 'status' => 'pending', 'meta' => []]);
        TaskRun::query()->create(['task_id' => $taskTwo->id, 'status' => 'running', 'meta' => []]);

        $overview = app(TaskMonitoringQueryService::class)->buildAdminOverview(1, 50, $first);

        $this->assertSame(['项目一任务'], array_column($overview['tasks'], 'name'));
        $this->assertSame(1, $overview['pagination']['total']);
        $this->assertSame(1, $overview['task_summary']['total_tasks']);
        $this->assertSame(1, $overview['queue_overview']['pending']);
        $this->assertSame(0, $overview['queue_overview']['running']);
        $this->assertCount(0, array_filter($overview['recent_runs'], fn (array $run): bool => $run['task_id'] === (int) $taskTwo->id));

        $snapshot = app(TaskMonitoringQueryService::class)->buildTaskSnapshot($first);
        $this->assertSame(['项目一任务'], array_column($snapshot, 'name'));

        $category = DB::table('categories')->insertGetId(['name' => '监控分类', 'slug' => 'monitoring-category', 'created_at' => now()]);
        $author = DB::table('authors')->insertGetId(['name' => '监控作者', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->insert([
            'title' => '错配文章', 'slug' => 'mismatch-article', 'content' => 'x', 'status' => 'draft',
            'review_status' => 'pending', 'category_id' => $category, 'author_id' => $author,
            'task_id' => $taskOne->id, 'client_project_id' => $second->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $filtered = app(TaskMonitoringQueryService::class)->buildAdminOverview(1, 50, $first);
        $this->assertSame(0, $filtered['tasks'][0]['total_articles']);
        $this->assertSame(0, $filtered['task_summary']['total_articles']);
    }

    public function test_scheduler_processes_large_task_sets_in_bounded_batches(): void
    {
        Queue::fake();
        foreach (range(1, 205) as $index) {
            Task::query()->create([
                'name' => '批量调度任务 '.$index,
                'status' => 'active',
                'schedule_enabled' => 1,
                'next_run_at' => null,
            ]);
        }

        $this->artisan('geoflow:schedule-tasks')
            ->expectsOutputToContain('skipped=205')
            ->assertSuccessful();

        $this->assertSame(
            205,
            Task::query()->whereNotNull('next_run_at')->count()
        );
    }
}
