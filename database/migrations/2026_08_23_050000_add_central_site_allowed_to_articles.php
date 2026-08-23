<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            if (! Schema::hasColumn('articles', 'central_site_allowed')) {
                $table->boolean('central_site_allowed')->default(false);
            }
            if (! Schema::hasIndex('articles', 'articles_central_public_order_index')) {
                $table->index(
                    ['status', 'review_status', 'central_site_allowed', 'published_at', 'id'],
                    'articles_central_public_order_index',
                );
            }
            if (! Schema::hasIndex('articles', 'articles_central_public_category_order_index')) {
                $table->index(
                    ['status', 'review_status', 'central_site_allowed', 'category_id', 'published_at', 'id'],
                    'articles_central_public_category_order_index',
                );
            }
        });

        if (Schema::hasTable('publication_batch_items') && ! Schema::hasIndex('publication_batch_items', 'publication_items_central_result_index')) {
            Schema::table('publication_batch_items', function (Blueprint $table): void {
                $table->index(
                    ['article_id', 'client_project_id', 'target_type', 'action', 'status'],
                    'publication_items_central_result_index',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('publication_batch_items') && Schema::hasIndex('publication_batch_items', 'publication_items_central_result_index')) {
            Schema::table('publication_batch_items', function (Blueprint $table): void {
                $table->dropIndex('publication_items_central_result_index');
            });
        }

        if (Schema::hasTable('articles') && Schema::hasColumn('articles', 'central_site_allowed')) {
            Schema::table('articles', function (Blueprint $table): void {
                if (Schema::hasIndex('articles', 'articles_central_public_order_index')) {
                    $table->dropIndex('articles_central_public_order_index');
                }
                if (Schema::hasIndex('articles', 'articles_central_public_category_order_index')) {
                    $table->dropIndex('articles_central_public_category_order_index');
                }
                $table->dropColumn('central_site_allowed');
            });
        }
    }
};
