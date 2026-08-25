<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks') && ! Schema::hasColumn('tasks', 'created_by_admin_id')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            });
        }

        if (Schema::hasTable('publication_batches') && ! Schema::hasColumn('publication_batches', 'task_id')) {
            Schema::table('publication_batches', function (Blueprint $table): void {
                $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->unique(['client_project_id', 'task_id'], 'publication_batches_project_task_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('publication_batches') && Schema::hasColumn('publication_batches', 'task_id')) {
            Schema::table('publication_batches', function (Blueprint $table): void {
                $table->dropUnique('publication_batches_project_task_unique');
                $table->dropConstrainedForeignId('task_id');
            });
        }

        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'created_by_admin_id')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('created_by_admin_id');
            });
        }
    }
};
