<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project_quotas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects')->restrictOnDelete();
            $table->unsignedBigInteger('ai_units_limit')->nullable();
            $table->unsignedBigInteger('storage_bytes_limit')->nullable();
            $table->unsignedBigInteger('article_count_limit')->nullable();
            $table->unsignedInteger('concurrency_limit')->nullable();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->unique('client_project_id');
        });

        Schema::create('client_project_usage_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects')->restrictOnDelete();
            $table->string('reservation_key', 160);
            $table->string('kind', 32);
            $table->unsignedBigInteger('units')->default(0);
            $table->string('state', 20)->default('reserved');
            $table->string('operation', 80)->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['client_project_id', 'reservation_key'], 'project_reservation_key_unique');
            $table->index(['client_project_id', 'kind', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_usage_reservations');
        Schema::dropIfExists('client_project_quotas');
    }
};
