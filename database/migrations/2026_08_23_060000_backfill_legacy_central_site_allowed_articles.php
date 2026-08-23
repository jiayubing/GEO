<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles')
            || ! Schema::hasTable('client_projects')
            || ! Schema::hasColumn('articles', 'central_site_allowed')) {
            return;
        }

        // A legacy carrier is the confirmed migration fact. Do not infer a
        // central-site permission for unowned records or new projects.
        DB::table('articles')
            ->whereNotNull('client_project_id')
            ->whereExists(function (Builder $projectQuery): void {
                $projectQuery
                    ->selectRaw('1')
                    ->from('client_projects')
                    ->whereColumn('client_projects.id', 'articles.client_project_id')
                    ->where('client_projects.is_legacy', true)
                    ->where('client_projects.publication_gate', 'legacy_auto');
            })
            ->update(['central_site_allowed' => true]);
    }

    public function down(): void
    {
        // This migration intentionally preserves an explicit operator-visible
        // permission. Removing it would destroy a fact rather than undo DDL.
    }
};
