<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_channel_site_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects')->restrictOnDelete();
            $table->foreignId('distribution_channel_id')->constrained('distribution_channels')->restrictOnDelete();
            $table->string('project_slug_snapshot', 160);
            $table->string('canonical_url', 500);
            $table->string('canonical_identity', 600);
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            // A platform channel may be shared for distribution, but one static
            // target cannot safely impersonate more than one project site.
            $table->unique('distribution_channel_id', 'project_channel_site_identities_channel_unique');
            $table->unique('canonical_identity', 'project_channel_site_identities_identity_unique');
            $table->index(['client_project_id', 'status'], 'project_channel_site_identities_project_status_index');
        });

        Schema::create('project_channel_site_identity_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_channel_site_identity_id')
                ->constrained('project_channel_site_identities')
                ->restrictOnDelete();
            $table->string('project_slug_snapshot', 160);
            $table->string('canonical_url', 500);
            $table->string('canonical_identity', 600);
            $table->string('reason', 40);
            $table->timestamp('retired_at');
            $table->timestamps();

            // Retired URLs stay reserved. A later project must not silently take
            // over a URL that can still exist in caches, links, or static files.
            $table->unique('canonical_identity', 'project_channel_site_identity_history_identity_unique');
            $table->index('project_channel_site_identity_id', 'project_channel_site_identity_history_site_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_channel_site_identity_histories');
        Schema::dropIfExists('project_channel_site_identities');
    }
};
