<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_projects') || Schema::hasColumn('client_projects', 'publication_gate')) {
            return;
        }

        Schema::table('client_projects', function (Blueprint $table): void {
            $table->string('publication_gate', 30)->default('platform_approval')->index();
        });

        DB::table('client_projects')
            ->where('is_legacy', true)
            ->update(['publication_gate' => 'legacy_auto']);
    }

    public function down(): void
    {
        if (Schema::hasTable('client_projects') && Schema::hasColumn('client_projects', 'publication_gate')) {
            Schema::table('client_projects', function (Blueprint $table): void {
                $table->dropIndex(['publication_gate']);
                $table->dropColumn('publication_gate');
            });
        }
    }
};
