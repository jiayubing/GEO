<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('client_project_id')->nullable()->after('tokenable_id')->constrained('client_projects')->nullOnDelete();
            $table->string('binding_mode', 20)->default('legacy_global')->after('client_project_id')->index();
            $table->index(['client_project_id', 'binding_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropForeign(['client_project_id']);
            $table->dropIndex(['client_project_id', 'binding_mode']);
            $table->dropIndex(['binding_mode']);
            $table->dropColumn(['client_project_id', 'binding_mode']);
        });
    }
};
