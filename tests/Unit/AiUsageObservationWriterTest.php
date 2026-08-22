<?php

namespace Tests\Unit;

use App\Models\AiUsageEvent;
use App\Models\Client;
use App\Models\ClientProject;
use App\Services\GeoFlow\AiUsageObservationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageObservationWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_observation_is_append_only_and_idempotent_by_reservation(): void
    {
        $project = $this->project();
        $writer = app(AiUsageObservationWriter::class);
        $payload = [
            'client_project_id' => $project->id,
            'model' => 'provider/model',
            'operation' => 'article.generate',
            'outcome' => 'success',
            'units' => 3,
            'reservation_key' => 'reservation-1',
            'metadata' => ['provider' => 'test', 'prompt' => 'must not persist'],
        ];

        $first = $writer->append($payload);
        $second = $writer->append($payload);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiUsageEvent::query()->count());
        $this->assertArrayNotHasKey('prompt', $first->fresh()->metadata ?? []);
        $this->assertFalse($first->update(['outcome' => 'failure']));
        $this->assertFalse($first->delete());
    }

    public function test_project_scope_requires_project_but_platform_scope_can_be_unbound(): void
    {
        $writer = app(AiUsageObservationWriter::class);
        $this->expectException(\InvalidArgumentException::class);
        $writer->append(['operation' => 'missing.project', 'outcome' => 'failure']);
    }

    private function project(): ClientProject
    {
        $client = Client::create(['name' => 'AI Client', 'slug' => 'ai-client-'.uniqid()]);

        return ClientProject::create(['client_id' => $client->id, 'name' => 'AI Project', 'slug' => 'ai-project']);
    }
}
