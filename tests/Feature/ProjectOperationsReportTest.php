<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientProject;
use App\Services\GeoFlow\ProjectOperationalAlertService;
use App\Services\GeoFlow\ProjectOperationsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectOperationsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_summary_and_pagination_are_database_scoped(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        \DB::table('tasks')->insert(['name' => 'one task', 'client_project_id' => $one->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('ai_usage_events')->insert(['client_project_id' => $one->id, 'scope' => 'project', 'operation' => 'generate', 'units' => 4, 'outcome' => 'success', 'attempt' => 1, 'fallback' => false, 'created_at' => now(), 'updated_at' => now()]);

        $report = app(ProjectOperationsReportService::class);
        $summary = $report->projectSummary($one);
        $this->assertSame(1, $summary['tasks']['total']);
        $this->assertSame(4, $summary['ai_usage_units']);
        $page = $report->paginate([$one->id], 1, 1);
        $this->assertSame(1, $page['total']);
        $this->assertSame($one->id, $page['items'][0]['project_id']);
        $this->assertNotSame($two->id, $page['items'][0]['project_id']);
    }

    public function test_alert_observation_is_deduplicated_and_resolvable(): void
    {
        $project = $this->project('alerts');
        $service = app(ProjectOperationalAlertService::class);
        $first = $service->observe($project, 'task_failed', 'task:1:failed', ['task_id' => 1]);
        $second = $service->observe($project, 'task_failed', 'task:1:failed', ['task_id' => 1]);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $project->operationalAlerts()->count());
        $service->resolve($project, 'task:1:failed');
        $this->assertSame('resolved', $first->fresh()->status);
        $quota = $service->quotaRejected($project, 'ai', 'request-1');
        $this->assertSame('quota_rejected', $quota->kind);
        $this->assertCount(0, $service->scan($project));
    }

    private function project(string $suffix): ClientProject
    {
        $client = Client::query()->create(['name' => 'Client '.$suffix, 'slug' => 'report-client-'.$suffix.'-'.uniqid()]);
        return ClientProject::query()->create(['client_id' => $client->id, 'name' => 'Project '.$suffix, 'slug' => 'report-project-'.$suffix.'-'.uniqid()]);
    }
}
