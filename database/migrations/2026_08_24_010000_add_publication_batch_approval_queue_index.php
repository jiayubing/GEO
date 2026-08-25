<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('publication_batches') && ! Schema::hasIndex('publication_batches', 'publication_batches_approval_queue_index')) {
            Schema::table('publication_batches', function (Blueprint $table): void {
                $table->index(['status', 'submitted_at', 'id'], 'publication_batches_approval_queue_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('publication_batches') && Schema::hasIndex('publication_batches', 'publication_batches_approval_queue_index')) {
            Schema::table('publication_batches', function (Blueprint $table): void {
                $table->dropIndex('publication_batches_approval_queue_index');
            });
        }
    }
};
