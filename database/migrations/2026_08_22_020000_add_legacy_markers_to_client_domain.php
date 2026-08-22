<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['clients', 'client_projects'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'is_legacy')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('is_legacy')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['client_projects', 'clients'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'is_legacy')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['is_legacy']);
                $table->dropColumn('is_legacy');
            });
        }
    }
};
