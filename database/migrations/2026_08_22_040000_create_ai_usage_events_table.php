<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_project_id')->nullable()->constrained('client_projects')->nullOnDelete();
            $table->string('scope', 20)->default('project')->index();
            $table->string('model', 160)->nullable();
            $table->string('operation', 80);
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('units')->default(0);
            $table->string('outcome', 20);
            $table->boolean('fallback')->default(false);
            $table->string('reservation_key', 160)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['client_project_id', 'created_at']);
            $table->index(['model', 'operation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
