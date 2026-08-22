<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $ownerTables = [
        'knowledge_bases',
        'keyword_libraries',
        'title_libraries',
        'image_libraries',
        'tasks',
        'articles',
        'authors',
        'categories',
        'enterprise_knowledge_projects',
        'url_import_jobs',
        'manual_publications',
    ];

    public function up(): void
    {
        foreach ($this->ownerTables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'client_project_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('client_project_id')
                    ->nullable()
                    ->constrained('client_projects')
                    ->nullOnDelete();
                $table->index('client_project_id');
            });
        }

        if (! Schema::hasTable('client_project_distribution_channels')) {
            Schema::create('client_project_distribution_channels', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('client_project_id')->constrained('client_projects')->restrictOnDelete();
                $table->foreignId('distribution_channel_id')->constrained('distribution_channels')->restrictOnDelete();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['client_project_id', 'distribution_channel_id'], 'client_project_distribution_channels_unique');
                $table->index(['distribution_channel_id', 'status']);
                $table->index(['client_project_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_distribution_channels');

        foreach (array_reverse($this->ownerTables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'client_project_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['client_project_id']);
                $table->dropIndex($tableName.'_client_project_id_index');
                $table->dropColumn('client_project_id');
            });
        }
    }
};
