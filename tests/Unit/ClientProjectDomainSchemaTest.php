<?php

namespace Tests\Unit;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Enums\ClientProjectStatus;
use App\Enums\ClientStatus;
use App\Enums\PublicationGate;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectDistributionChannel;
use App\Models\ClientProjectMember;
use App\Models\DistributionChannel;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\LegacyProjectBackfillService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class ClientProjectDomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_tables_and_contract_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('clients'));
        $this->assertTrue(Schema::hasTable('client_projects'));
        $this->assertTrue(Schema::hasTable('client_project_members'));
        $this->assertTrue(Schema::hasColumns('clients', ['slug', 'status', 'created_by_admin_id', 'updated_by_admin_id']));
        $this->assertTrue(Schema::hasColumns('client_projects', ['client_id', 'slug', 'status', 'publication_gate', 'created_by_admin_id', 'updated_by_admin_id']));
        $this->assertTrue(Schema::hasColumns('client_project_members', ['client_project_id', 'admin_id', 'role', 'status', 'revoked_at']));
    }

    public function test_models_cast_states_and_expose_relationships(): void
    {
        $admin = $this->createAdmin();
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'created_by_admin_id' => $admin->id]);
        $project = ClientProject::create(['client_id' => $client->id, 'name' => 'Acme Web', 'slug' => 'web']);
        $member = ClientProjectMember::create(['client_project_id' => $project->id, 'admin_id' => $admin->id, 'role' => ClientProjectMemberRole::OPERATOR]);

        $this->assertSame(ClientStatus::ACTIVE, $client->fresh()->status);
        $this->assertSame(ClientProjectStatus::ACTIVE, $project->fresh()->status);
        $this->assertSame(PublicationGate::PLATFORM_APPROVAL, $project->fresh()->publication_gate);
        $this->assertSame(ClientProjectMemberRole::OPERATOR, $member->fresh()->role);
        $this->assertSame(ClientProjectMemberStatus::ACTIVE, $member->fresh()->status);
        $this->assertTrue($member->project->is($project));
        $this->assertTrue($member->admin->is($admin));
        $this->assertTrue($client->projects->first()->is($project));
    }

    public function test_same_admin_cannot_have_duplicate_membership_in_project(): void
    {
        $admin = $this->createAdmin();
        $project = ClientProject::create(['client_id' => Client::create(['name' => 'Acme', 'slug' => 'acme'])->id, 'name' => 'Web', 'slug' => 'web']);
        ClientProjectMember::create(['client_project_id' => $project->id, 'admin_id' => $admin->id]);

        $this->expectException(QueryException::class);
        ClientProjectMember::create(['client_project_id' => $project->id, 'admin_id' => $admin->id, 'role' => ClientProjectMemberRole::VIEWER]);
    }

    public function test_owner_columns_and_channel_membership_are_nullable_and_related(): void
    {
        foreach ([
            'knowledge_bases', 'keyword_libraries', 'title_libraries', 'image_libraries',
            'tasks', 'articles', 'authors', 'categories', 'enterprise_knowledge_projects',
            'url_import_jobs', 'manual_publications',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'client_project_id'), $table);
        }

        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $project = ClientProject::create(['client_id' => $client->id, 'name' => 'Web', 'slug' => 'web']);
        $channel = DistributionChannel::create(['name' => 'Target', 'domain' => 'target.test', 'endpoint_url' => 'https://target.test']);
        $membership = ClientProjectDistributionChannel::create([
            'client_project_id' => $project->id,
            'distribution_channel_id' => $channel->id,
        ]);

        $this->assertTrue($membership->project->is($project));
        $this->assertTrue($membership->channel->is($channel));
        $this->assertTrue($project->distributionChannels->first()->is($channel));
        $this->assertTrue($channel->clientProjects->first()->is($project));
    }

    public function test_legacy_backfill_is_explicit_and_idempotent(): void
    {
        Category::create(['name' => 'Legacy category', 'slug' => 'legacy-category']);
        $service = app(LegacyProjectBackfillService::class);

        $preview = $service->run(false, 1);
        $this->assertSame('ready', $preview['status']);
        $this->assertSame(0, Client::query()->count());

        $first = $service->run(true, 1);
        $this->assertSame('completed', $first['status']);
        $this->assertSame(1, Client::query()->where('is_legacy', true)->count());
        $this->assertSame(1, ClientProject::query()->where('is_legacy', true)->count());
        $this->assertSame(1, Category::query()->whereNotNull('client_project_id')->count());

        $second = $service->run(true, 1);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(1, Client::query()->where('is_legacy', true)->count());
        $this->assertSame(1, ClientProject::query()->where('is_legacy', true)->count());
        $this->assertSame(0, $second['owner_counts_assigned']['categories']);
        $this->assertSame(PublicationGate::LEGACY_AUTO, ClientProject::query()->where('is_legacy', true)->firstOrFail()->publication_gate);
    }

    public function test_enterprise_published_knowledge_base_cannot_cross_project_owner(): void
    {
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $projectA = ClientProject::create(['client_id' => $client->id, 'name' => 'A', 'slug' => 'a']);
        $projectB = ClientProject::create(['client_id' => $client->id, 'name' => 'B', 'slug' => 'b']);
        $knowledgeBase = KnowledgeBase::create([
            'name' => 'Owned by B',
            'content' => 'content',
            'client_project_id' => $projectB->id,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('enterprise_knowledge_published_knowledge_base_project_mismatch');
        EnterpriseKnowledgeProject::create([
            'name' => 'A knowledge',
            'client_project_id' => $projectA->id,
            'published_knowledge_base_id' => $knowledgeBase->id,
        ]);
    }

    public function test_enterprise_ownership_contract_indexes_are_present(): void
    {
        $this->assertTrue(Schema::hasColumn('enterprise_knowledge_projects', 'client_project_id'));
        $this->assertTrue(Schema::hasColumn('knowledge_bases', 'client_project_id'));
        $indexes = collect(Schema::getIndexes('knowledge_bases'))->pluck('name')->all();
        $this->assertContains('knowledge_bases_id_project_unique', $indexes);
    }

    private function createAdmin(): Admin
    {
        return Admin::create([
            'username' => 'admin-'.uniqid(),
            'password' => 'password',
            'email' => '',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
