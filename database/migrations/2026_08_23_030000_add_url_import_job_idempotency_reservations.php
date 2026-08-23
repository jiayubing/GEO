<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('url_import_jobs')) {
            return;
        }

        if (! $this->hasIndex('url_import_jobs', 'url_import_jobs_project_url_created_index')) {
            Schema::table('url_import_jobs', function (Blueprint $table): void {
                $table->index(['client_project_id', 'normalized_url', 'created_at'], 'url_import_jobs_project_url_created_index');
            });
        }

        if (Schema::hasTable('url_import_job_logs') && ! $this->hasIndex('url_import_job_logs', 'url_import_job_logs_job_created_index')) {
            Schema::table('url_import_job_logs', function (Blueprint $table): void {
                $table->index(['job_id', 'created_at'], 'url_import_job_logs_job_created_index');
            });
        }

        if (! Schema::hasTable('url_import_job_idempotencies')) {
            Schema::create('url_import_job_idempotencies', function (Blueprint $table): void {
                $table->id();
                $table->string('owner_scope', 64);
                $table->text('normalized_url');
                $table->foreignId('url_import_job_id')->constrained('url_import_jobs')->cascadeOnDelete();
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->unique(['owner_scope', 'normalized_url'], 'url_import_job_idempotencies_scope_url_unique');
                $table->index('expires_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('url_import_job_idempotencies');

        if (Schema::hasTable('url_import_job_logs') && $this->hasIndex('url_import_job_logs', 'url_import_job_logs_job_created_index')) {
            Schema::table('url_import_job_logs', function (Blueprint $table): void {
                $table->dropIndex('url_import_job_logs_job_created_index');
            });
        }

        if (Schema::hasTable('url_import_jobs') && $this->hasIndex('url_import_jobs', 'url_import_jobs_project_url_created_index')) {
            Schema::table('url_import_jobs', function (Blueprint $table): void {
                $table->dropIndex('url_import_jobs_project_url_created_index');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains('name', $name);
    }
};
