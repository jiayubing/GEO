<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_project_id')->constrained('client_projects')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('idempotency_key', 160)->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('submitted_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['client_project_id', 'idempotency_key'], 'publication_batches_project_idempotency_unique');
            // The composite key is used by the item FK to make project ownership
            // a database invariant, not only an Eloquent lifecycle check.
            $table->unique(['id', 'client_project_id'], 'publication_batches_id_project_unique');
            $table->index(['client_project_id', 'status']);
        });

        Schema::create('publication_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('publication_batch_id');
            $table->foreignId('client_project_id')->constrained('client_projects')->restrictOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('target_type', 20);
            $table->string('target_identity', 500);
            $table->string('action', 30)->default('publish');
            $table->unsignedInteger('article_revision')->nullable();
            $table->char('article_content_hash', 64)->nullable();
            $table->json('target_snapshot')->nullable();
            $table->json('result_snapshot')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->string('idempotency_key', 220);
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('article_distribution_id')->nullable()->constrained('article_distributions')->nullOnDelete();
            $table->foreignId('manual_publication_id')->nullable()->constrained('manual_publications')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();
            $table->unique('idempotency_key', 'publication_batch_items_idempotency_unique');
            $table->unique(['publication_batch_id', 'article_id', 'target_type', 'target_identity', 'action'], 'publication_batch_items_fact_unique');
            $table->index(['client_project_id', 'status']);
            $table->index(['client_project_id', 'target_type', 'target_identity']);
            $table->index(['article_id', 'article_revision']);
            $table->foreign(['publication_batch_id', 'client_project_id'], 'publication_batch_items_batch_project_fk')
                ->references(['id', 'client_project_id'])
                ->on('publication_batches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_batch_items');
        Schema::dropIfExists('publication_batches');
    }
};
