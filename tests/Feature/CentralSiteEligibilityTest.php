<?php

namespace Tests\Feature;

use App\Enums\ClientProjectStatus;
use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Enums\PublicationGate;
use App\Enums\PublicationTargetType;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\PublicationBatch;
use App\Models\PublicationBatchItem;
use App\Models\SiteSetting;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralSiteEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_central_site_surface_uses_the_same_database_eligibility_projection(): void
    {
        SiteSetting::query()->updateOrCreate(['setting_key' => 'per_page'], ['setting_value' => '2']);
        SiteSettingsBag::forget();

        $platformProject = $this->project('platform', PublicationGate::PLATFORM_APPROVAL);
        $legacyProject = $this->project('legacy', PublicationGate::LEGACY_AUTO, true);
        $inactiveProject = $this->project('inactive', PublicationGate::PLATFORM_APPROVAL, false, ClientProjectStatus::SUSPENDED);
        $category = Category::query()->create(['name' => 'Central Category', 'slug' => 'central-category']);
        $author = Author::query()->create(['name' => 'Central Author']);

        $platformVisible = $this->article($platformProject, $category, $author, 'Platform eligible', [
            'is_featured' => true,
            'is_hot' => true,
            'published_at' => Carbon::parse('2026-08-15 09:00:00'),
        ]);
        $this->localPublished($platformVisible);

        $legacyVisible = $this->article($legacyProject, $category, $author, 'Legacy eligible', [
            'published_at' => Carbon::parse('2026-08-14 09:00:00'),
        ]);
        $relatedVisible = $this->article($platformProject, $category, $author, 'Eligible related', [
            'published_at' => Carbon::parse('2026-08-13 09:00:00'),
        ]);
        $this->localPublished($relatedVisible);

        $centralRevoked = $this->article($platformProject, $category, $author, 'Central permission revoked', [
            'central_site_allowed' => false,
            'is_featured' => true,
            'is_hot' => true,
        ]);
        $this->localPublished($centralRevoked);

        $missingLocalResult = $this->article($platformProject, $category, $author, 'Missing local result');
        $channelOnly = $this->article($platformProject, $category, $author, 'Channel result only');
        $this->targetResult($channelOnly, PublicationTargetType::CHANNEL, PublicationBatchItemStatus::REMOTE_SYNCED);
        $privateArticle = $this->article($platformProject, $category, $author, 'Private article', ['status' => 'private']);
        $this->localPublished($privateArticle);
        $pendingReview = $this->article($platformProject, $category, $author, 'Pending review', ['review_status' => 'pending']);
        $this->localPublished($pendingReview);
        $inactiveArticle = $this->article($inactiveProject, $category, $author, 'Inactive project article');
        $this->localPublished($inactiveArticle);
        $softDeleted = $this->article($platformProject, $category, $author, 'Soft deleted article');
        $this->localPublished($softDeleted);
        $softDeleted->delete();

        $home = $this->get(route('site.home'));
        $home->assertOk()
            ->assertSee('Platform eligible')
            ->assertSee('Legacy eligible')
            ->assertDontSee('Central permission revoked')
            ->assertDontSee('Missing local result')
            ->assertDontSee('Channel result only')
            ->assertDontSee('Private article')
            ->assertDontSee('Pending review')
            ->assertDontSee('Inactive project article')
            ->assertDontSee('Soft deleted article')
            ->assertViewHas('articles', function (LengthAwarePaginator $articles): bool {
                return $articles->total() === 3;
            });

        $home->assertViewHas('featuredArticles', function ($articles): bool {
            return $articles->pluck('id')->all() === [$this->articleIdByTitle('Platform eligible')];
        });
        $home->assertViewHas('hotArticles', function ($articles): bool {
            return $articles->pluck('id')->all() === [$this->articleIdByTitle('Platform eligible')];
        });

        $this->get(route('site.home', ['search' => 'eligible']))
            ->assertOk()
            ->assertSee('Platform eligible')
            ->assertSee('Legacy eligible')
            ->assertDontSee('Central permission revoked');

        $this->get(route('site.category', $category->slug))
            ->assertOk()
            ->assertSee('Platform eligible')
            ->assertDontSee('Channel result only');

        $articlePage = $this->get(route('site.article', $platformVisible->slug));
        $articlePage->assertOk()
            ->assertSee('Eligible related')
            ->assertDontSee('Central permission revoked')
            ->assertSee('application/ld+json', false)
            ->assertSee(route('site.article', $platformVisible->slug), false);
        $this->get(route('site.article', $centralRevoked->slug))->assertNotFound();

        $archive = $this->get(route('site.archive'));
        $archive->assertOk()->assertViewHas('archives', function (array $archives): bool {
            return $archives === [['year' => '2026', 'month' => '08', 'count' => 3]];
        });
        $this->get(route('site.archive.month', ['year' => '2026', 'month' => '08']))
            ->assertOk()
            ->assertSee('Platform eligible')
            ->assertDontSee('Missing local result');

        $secondPage = $this->get(route('site.home', ['page' => 2]));
        $secondPage->assertOk()
            ->assertSee('Eligible related')
            ->assertViewHas('articles', function (LengthAwarePaginator $articles): bool {
                return $articles->total() === 3 && $articles->currentPage() === 2 && $articles->count() === 1;
            });

        $platformVisible->update(['central_site_allowed' => false]);
        $this->get(route('site.article', $platformVisible->slug))->assertNotFound();
        $this->get(route('site.home'))->assertDontSee('Platform eligible');

        $legacyProject->update(['status' => ClientProjectStatus::SUSPENDED]);
        $this->get(route('site.article', $legacyVisible->slug))->assertNotFound();
        $this->get(route('site.home'))->assertDontSee('Legacy eligible');

        $relatedVisible->delete();
        $this->get(route('site.article', $relatedVisible->slug))->assertNotFound();
        $this->get(route('site.home'))->assertDontSee('Eligible related');
    }

    public function test_central_site_schema_has_the_public_filter_and_target_result_indexes(): void
    {
        $this->assertTrue(Schema::hasColumn('articles', 'central_site_allowed'));
        $this->assertTrue(Schema::hasIndex('articles', 'articles_central_public_order_index'));
        $this->assertTrue(Schema::hasIndex('articles', 'articles_central_public_category_order_index'));
        $this->assertTrue(Schema::hasIndex('publication_batch_items', 'publication_items_central_result_index'));
    }

    private function project(
        string $slug,
        PublicationGate $gate,
        bool $legacy = false,
        ClientProjectStatus $status = ClientProjectStatus::ACTIVE,
    ): ClientProject {
        $client = Client::query()->create([
            'name' => 'Client '.$slug,
            'slug' => 'client-'.$slug,
            'is_legacy' => $legacy,
        ]);

        return ClientProject::query()->create([
            'client_id' => $client->id,
            'name' => 'Project '.$slug,
            'slug' => 'project-'.$slug,
            'status' => $status,
            'is_legacy' => $legacy,
            'publication_gate' => $gate,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function article(ClientProject $project, Category $category, Author $author, string $title, array $overrides = []): Article
    {
        $slug = Str::slug($title).'-'.Str::random(6);

        return Article::query()->create($overrides + [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $title.' excerpt',
            'content' => $title.' content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'client_project_id' => $project->id,
            'status' => 'published',
            'review_status' => 'approved',
            'central_site_allowed' => true,
            'published_at' => Carbon::parse('2026-08-12 09:00:00'),
        ]);
    }

    private function localPublished(Article $article): void
    {
        $this->targetResult($article, PublicationTargetType::LOCAL, PublicationBatchItemStatus::LOCAL_PUBLISHED);
    }

    private function targetResult(Article $article, PublicationTargetType $target, PublicationBatchItemStatus $status): void
    {
        $batch = PublicationBatch::query()->create([
            'client_project_id' => $article->client_project_id,
            'status' => PublicationBatchStatus::COMPLETED,
            'idempotency_key' => 'central-batch-'.$article->id.'-'.$target->value,
        ]);

        PublicationBatchItem::query()->create([
            'publication_batch_id' => $batch->id,
            'client_project_id' => $article->client_project_id,
            'article_id' => $article->id,
            'target_type' => $target,
            'target_identity' => $target->value.':'.$article->client_project_id,
            'action' => 'publish',
            'status' => $status,
            'idempotency_key' => 'central-item-'.$article->id.'-'.$target->value,
            'finished_at' => now(),
        ]);
    }

    private function articleIdByTitle(string $title): int
    {
        return (int) Article::query()->where('title', $title)->value('id');
    }
}
