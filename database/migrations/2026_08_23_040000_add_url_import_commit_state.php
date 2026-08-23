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

        Schema::table('url_import_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('url_import_jobs', 'commit_status')) {
                $table->string('commit_status', 20)->default('not_started')->index();
            }
            if (! Schema::hasColumn('url_import_jobs', 'committed_knowledge_base_id')) {
                $table->foreignId('committed_knowledge_base_id')
                    ->nullable()
                    ->constrained('knowledge_bases')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('url_import_jobs', 'committed_keyword_library_id')) {
                $table->foreignId('committed_keyword_library_id')
                    ->nullable()
                    ->constrained('keyword_libraries')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('url_import_jobs', 'committed_title_library_id')) {
                $table->foreignId('committed_title_library_id')
                    ->nullable()
                    ->constrained('title_libraries')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('url_import_jobs', 'commit_started_at')) {
                $table->timestamp('commit_started_at')->nullable();
            }
            if (! Schema::hasColumn('url_import_jobs', 'commit_finished_at')) {
                $table->timestamp('commit_finished_at')->nullable();
            }
            if (! Schema::hasColumn('url_import_jobs', 'commit_error_code')) {
                $table->string('commit_error_code', 100)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('url_import_jobs')) {
            return;
        }

        Schema::table('url_import_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('url_import_jobs', 'committed_title_library_id')) {
                $table->dropConstrainedForeignId('committed_title_library_id');
            }
            if (Schema::hasColumn('url_import_jobs', 'committed_keyword_library_id')) {
                $table->dropConstrainedForeignId('committed_keyword_library_id');
            }
            if (Schema::hasColumn('url_import_jobs', 'committed_knowledge_base_id')) {
                $table->dropConstrainedForeignId('committed_knowledge_base_id');
            }
            if (Schema::hasColumn('url_import_jobs', 'commit_status')) {
                $table->dropIndex(['commit_status']);
                $table->dropColumn('commit_status');
            }
            if (Schema::hasColumn('url_import_jobs', 'commit_started_at')) {
                $table->dropColumn('commit_started_at');
            }
            if (Schema::hasColumn('url_import_jobs', 'commit_finished_at')) {
                $table->dropColumn('commit_finished_at');
            }
            if (Schema::hasColumn('url_import_jobs', 'commit_error_code')) {
                $table->dropColumn('commit_error_code');
            }
        });
    }
};
