<?php

namespace Tests\Feature;

use App\Exceptions\ProjectQuotaExceeded;
use App\Models\AiUsageEvent;
use App\Models\Client;
use App\Models\ClientProject;
use App\Services\GeoFlow\ProjectQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_reservation_is_project_scoped_idempotent_and_records_lifecycle(): void
    {
        $firstProject = $this->project('one');
        $secondProject = $this->project('two');
        $service = app(ProjectQuotaService::class);
        $service->configure($firstProject, ['ai_units_limit' => 5]);

        $reservation = $service->reserve($firstProject, 'ai', 3, 'run-1', ['operation' => 'article.generate']);
        $replay = $service->reserve($firstProject, 'ai', 3, 'run-1', ['operation' => 'article.generate']);
        $this->assertSame($reservation->id, $replay->id);
        $this->assertSame(1, $firstProject->usageReservations()->count());

        try {
            $service->reserve($firstProject, 'ai', 3, 'run-2');
            $this->fail('Expected the project AI quota to reject the reservation.');
        } catch (ProjectQuotaExceeded $exception) {
            $this->assertSame('limit_reached', $exception->reason);
        }

        $service->finalize($reservation, 'success');
        $service->finalize($reservation, 'success');
        $this->assertSame('success', $reservation->fresh()->state);
        $this->assertSame(2, AiUsageEvent::query()->where('client_project_id', $firstProject->id)->count());

        $other = $service->reserve($secondProject, 'ai', 3, 'run-2');
        $this->assertSame($secondProject->id, $other->client_project_id);
    }

    public function test_release_and_uncertain_are_idempotent_and_uncertain_consumes_units(): void
    {
        $project = $this->project('uncertain');
        $service = app(ProjectQuotaService::class);
        $service->configure($project, ['ai_units_limit' => 2]);
        $reservation = $service->reserve($project, 'ai', 2, 'uncertain-1');
        $service->finalize($reservation, 'uncertain');
        $service->release($reservation);
        $this->assertSame('uncertain', $reservation->fresh()->state);

        $this->expectException(ProjectQuotaExceeded::class);
        $service->reserve($project, 'ai', 1, 'uncertain-2');
    }

    public function test_storage_articles_and_concurrency_use_configured_limits_without_writing_domain_facts(): void
    {
        $project = $this->project('resources');
        $service = app(ProjectQuotaService::class);
        $service->configure($project, [
            'storage_bytes_limit' => 10,
            'article_count_limit' => 2,
            'concurrency_limit' => 1,
        ]);
        $storage = $service->reserve($project, 'storage', 5, 'file-1', ['current_usage' => 4]);
        $service->release($storage);
        $article = $service->reserve($project, 'articles', 1, 'article-1', ['current_usage' => 1]);
        $service->finalize($article, 'success');
        $worker = $service->reserve($project, 'concurrency', 1, 'worker-1');
        $this->expectException(ProjectQuotaExceeded::class);
        $service->reserve($project, 'concurrency', 1, 'worker-2');
        $this->assertSame('reserved', $worker->fresh()->state);
    }

    private function project(string $suffix): ClientProject
    {
        $client = Client::query()->create(['name' => 'Client '.$suffix, 'slug' => 'client-'.$suffix.'-'.uniqid()]);

        return ClientProject::query()->create(['client_id' => $client->id, 'name' => 'Project '.$suffix, 'slug' => 'project-'.$suffix.'-'.uniqid()]);
    }
}
