<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientProject;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class WorkerProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_rejects_cross_project_task_references_before_generation(): void
    {
        $one = $this->project('one');
        $two = $this->project('two');
        $foreignLibrary = TitleLibrary::create([
            'name' => 'Foreign titles',
            'client_project_id' => $two->id,
        ]);
        $task = Task::create([
            'name' => 'Project task',
            'client_project_id' => $one->id,
            'title_library_id' => $foreignLibrary->id,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        app(WorkerExecutionService::class)->executeTask((int) $task->id);
    }

    private function project(string $slug): ClientProject
    {
        $client = Client::create(['name' => 'Client '.$slug, 'slug' => 'client-'.$slug]);

        return ClientProject::create(['client_id' => $client->id, 'name' => 'Project '.$slug, 'slug' => $slug]);
    }
}
