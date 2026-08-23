<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_knowledge_projects')
            || ! Schema::hasTable('knowledge_bases')
            || ! Schema::hasColumn('enterprise_knowledge_projects', 'client_project_id')
            || ! Schema::hasColumn('enterprise_knowledge_projects', 'published_knowledge_base_id')
            || ! Schema::hasColumn('knowledge_bases', 'client_project_id')) {
            return;
        }

        // The composite reference makes the project owner part of the
        // published relation. Existing mismatches must be reported and
        // repaired by the legacy backfill before this migration is applied.
        $indexes = collect(Schema::getIndexes('knowledge_bases'))->pluck('name')->all();
        if (! in_array('knowledge_bases_id_project_unique', $indexes, true)) {
            Schema::table('knowledge_bases', function (Blueprint $table): void {
                $table->unique(['id', 'client_project_id'], 'knowledge_bases_id_project_unique');
            });
        }

        Schema::table('enterprise_knowledge_projects', function (Blueprint $table): void {
            $table->dropForeign(['published_knowledge_base_id']);
            $table->foreign(['published_knowledge_base_id', 'client_project_id'], 'enterprise_published_knowledge_project_fk')
                ->references(['id', 'client_project_id'])
                ->on('knowledge_bases')
                // A composite SET NULL would also erase client_project_id, which
                // is the immutable owner fact. Published knowledge bases must be
                // detached explicitly before they can be deleted.
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('enterprise_knowledge_projects')
            && Schema::hasColumn('enterprise_knowledge_projects', 'published_knowledge_base_id')) {
            Schema::table('enterprise_knowledge_projects', function (Blueprint $table): void {
                $table->dropForeign('enterprise_published_knowledge_project_fk');
                $table->foreign('published_knowledge_base_id')
                    ->references('id')
                    ->on('knowledge_bases')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('knowledge_bases')) {
            Schema::table('knowledge_bases', function (Blueprint $table): void {
                $table->dropUnique('knowledge_bases_id_project_unique');
            });
        }
    }
};
