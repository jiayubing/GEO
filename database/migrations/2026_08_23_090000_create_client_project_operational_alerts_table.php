<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_project_operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects')->cascadeOnDelete();
            $table->string('fingerprint', 160);
            $table->string('kind', 60);
            $table->string('severity', 20)->default('warning');
            $table->string('status', 20)->default('open');
            $table->json('payload')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['client_project_id', 'fingerprint'], 'project_alert_fingerprint_unique');
            $table->index(['client_project_id', 'status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_project_operational_alerts');
    }
};
