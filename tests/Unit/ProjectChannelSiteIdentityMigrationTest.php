<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectChannelSiteIdentityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_channel_site_identity_ddl_round_trips_on_sqlite(): void
    {
        $migration = require database_path('migrations/2026_08_23_070000_create_project_channel_site_identity_tables.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('project_channel_site_identities'));
        $this->assertFalse(Schema::hasTable('project_channel_site_identity_histories'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('project_channel_site_identities'));
        $this->assertTrue(Schema::hasTable('project_channel_site_identity_histories'));
        $this->assertTrue(Schema::hasColumn('project_channel_site_identities', 'canonical_identity'));
        $this->assertTrue(Schema::hasColumn('project_channel_site_identity_histories', 'retired_at'));
        $this->assertTrue(Schema::hasIndex('project_channel_site_identities', 'project_channel_site_identities_channel_unique'));
        $this->assertTrue(Schema::hasIndex('project_channel_site_identity_histories', 'project_channel_site_identity_history_identity_unique'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('project_channel_site_identities'));
        $this->assertFalse(Schema::hasTable('project_channel_site_identity_histories'));
    }
}
