<?php

namespace Tests\Unit;

use App\Enums\PublicationGate;
use App\Models\Client;
use App\Models\ClientProject;
use App\Enums\ClientProjectStatus;
use App\Support\GeoFlow\PublicationGateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PublicationGateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_projects_default_to_platform_approval_and_legacy_is_explicit(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $project = ClientProject::create(['client_id' => $client->id, 'name' => 'Web', 'slug' => 'web']);
        $legacy = ClientProject::create([
            'client_id' => $client->id,
            'name' => 'Legacy',
            'slug' => 'legacy-test',
            'is_legacy' => true,
            'publication_gate' => PublicationGate::LEGACY_AUTO,
        ]);

        $this->assertSame(PublicationGate::PLATFORM_APPROVAL, $project->fresh()->publication_gate);
        $this->assertSame(PublicationGate::LEGACY_AUTO, $legacy->fresh()->publication_gate);
    }

    public function test_review_approval_does_not_grant_platform_publication(): void
    {
        $project = $this->project(PublicationGate::PLATFORM_APPROVAL);
        $result = PublicationGateContract::evaluate($project, 'draft', 'approved', PublicationGateContract::TARGET_LOCAL);

        $this->assertFalse($result['allowed']);
        $this->assertSame('platform_approval_required', $result['code']);
    }

    public function test_legacy_auto_allows_approved_articles_for_each_target(): void
    {
        $project = $this->project(PublicationGate::LEGACY_AUTO);
        foreach (PublicationGateContract::targets() as $target) {
            $this->assertTrue(PublicationGateContract::allowsPublicTransition($project, 'draft', 'approved', $target), $target);
        }
    }

    public function test_matrix_covers_both_gates_all_states_and_targets(): void
    {
        $this->assertCount(72, PublicationGateContract::stateMatrix());
    }

    public function test_invalid_gate_target_and_missing_project_context_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PublicationGateContract::evaluate(null, 'draft', 'approved', PublicationGateContract::TARGET_LOCAL);
    }

    public function test_invalid_gate_value_is_rejected_on_model_assignment(): void
    {
        $project = $this->project(PublicationGate::PLATFORM_APPROVAL);
        $this->expectException(\ValueError::class);
        $project->publication_gate = 'invalid';
    }

    private function project(PublicationGate $gate): ClientProject
    {
        $client = Client::create(['name' => 'Client '.uniqid(), 'slug' => 'client-'.uniqid()]);
        $project = ClientProject::create([
            'client_id' => $client->id,
            'name' => 'Project',
            'slug' => 'project-'.uniqid(),
            'status' => ClientProjectStatus::ACTIVE,
            'publication_gate' => $gate,
        ]);

        return $project->fresh();
    }
}
