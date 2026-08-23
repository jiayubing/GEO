<?php

namespace Tests\Unit;

use App\Enums\PublicationGate;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralSiteMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_site_ddl_round_trips_on_sqlite(): void
    {
        $migration = require database_path('migrations/2026_08_23_050000_add_central_site_allowed_to_articles.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('articles', 'central_site_allowed'));
        $this->assertFalse(Schema::hasIndex('articles', 'articles_central_public_order_index'));
        $this->assertFalse(Schema::hasIndex('articles', 'articles_central_public_category_order_index'));
        $this->assertFalse(Schema::hasIndex('publication_batch_items', 'publication_items_central_result_index'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('articles', 'central_site_allowed'));
        $this->assertTrue(Schema::hasIndex('articles', 'articles_central_public_order_index'));
        $this->assertTrue(Schema::hasIndex('articles', 'articles_central_public_category_order_index'));
        $this->assertTrue(Schema::hasIndex('publication_batch_items', 'publication_items_central_result_index'));
    }

    public function test_legacy_central_site_backfill_only_uses_confirmed_legacy_project_facts(): void
    {
        $legacyClient = Client::query()->create([
            'name' => 'Legacy client',
            'slug' => 'legacy-client',
            'is_legacy' => true,
        ]);
        $legacyProject = ClientProject::query()->create([
            'client_id' => $legacyClient->id,
            'name' => 'Legacy project',
            'slug' => 'legacy-project',
            'is_legacy' => true,
            'publication_gate' => PublicationGate::LEGACY_AUTO,
        ]);
        $newClient = Client::query()->create(['name' => 'New client', 'slug' => 'new-client']);
        $newProject = ClientProject::query()->create([
            'client_id' => $newClient->id,
            'name' => 'New project',
            'slug' => 'new-project',
            'publication_gate' => PublicationGate::PLATFORM_APPROVAL,
        ]);
        $category = Category::query()->create(['name' => 'Migration category', 'slug' => 'migration-category']);
        $author = Author::query()->create(['name' => 'Migration author']);
        $legacyArticle = Article::query()->create([
            'title' => 'Legacy article',
            'slug' => 'legacy-article',
            'content' => 'Legacy content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'client_project_id' => $legacyProject->id,
        ]);
        $newArticle = Article::query()->create([
            'title' => 'New article',
            'slug' => 'new-article',
            'content' => 'New content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'client_project_id' => $newProject->id,
        ]);

        $migration = require database_path('migrations/2026_08_23_060000_backfill_legacy_central_site_allowed_articles.php');
        $migration->up();

        $this->assertTrue($legacyArticle->fresh()->central_site_allowed);
        $this->assertFalse($newArticle->fresh()->central_site_allowed);
    }
}
