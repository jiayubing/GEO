<?php

namespace Tests\Feature;

use App\Enums\PublicationGate;
use App\Exceptions\ProjectSiteIdentityException;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectDistributionChannel;
use App\Models\DistributionChannel;
use App\Models\ProjectChannelSiteIdentityHistory;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use App\Services\GeoFlow\ProjectChannelSiteIdentityService;
use App\Services\GeoFlow\PublicationBatchTargetResolver;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ProjectChannelSiteIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_project_slug_is_allowed_for_different_clients_on_different_bound_channels(): void
    {
        $first = $this->project('first-client', 'shared-project');
        $second = $this->project('second-client', 'shared-project');
        $firstChannel = $this->channel('first-target', 'https://first.example.test/agent');
        $secondChannel = $this->channel('second-target', 'https://second.example.test/agent');
        $this->attach($first, $firstChannel);
        $this->attach($second, $secondChannel);

        $service = app(ProjectChannelSiteIdentityService::class);
        $firstIdentity = $service->provision($first, $firstChannel);
        $secondIdentity = $service->provision($second, $secondChannel);

        $this->assertSame('shared-project', $first->slug);
        $this->assertSame('shared-project', $second->slug);
        $this->assertNotSame($first->client_id, $second->client_id);
        $this->assertNotSame($firstIdentity->canonical_identity, $secondIdentity->canonical_identity);
        $this->assertSame($first->id, $firstIdentity->client_project_id);
        $this->assertSame($second->id, $secondIdentity->client_project_id);
    }

    public function test_bound_channel_and_canonical_url_conflicts_are_explicit_and_preflight_is_read_only(): void
    {
        $first = $this->project('first-client', 'first-project');
        $second = $this->project('second-client', 'second-project');
        $shared = $this->channel('shared-target', 'https://shared.example.test/agent');
        $sameCanonical = $this->channel('same-canonical', 'https://SHARED.example.test/agent/index.php');
        $wordpress = $this->channel('wordpress-target', 'https://shared.example.test/agent', 'wordpress_rest');
        $this->attach($first, $shared);
        $this->attach($second, $shared);
        $this->attach($second, $sameCanonical);

        $service = app(ProjectChannelSiteIdentityService::class);
        $service->provision($first, $shared);

        try {
            $service->provision($second, $shared);
            $this->fail('A platform channel cannot impersonate two project public sites.');
        } catch (ProjectSiteIdentityException $exception) {
            $this->assertSame('project_site_channel_already_bound', $exception->identityCode);
        }

        try {
            $service->provision($second, $sameCanonical);
            $this->fail('A canonical public endpoint must not be reused by another project site.');
        } catch (ProjectSiteIdentityException $exception) {
            $this->assertSame('project_site_identity_conflict', $exception->identityCode);
        }

        $report = $service->conflictReport();

        $this->assertSame(3, $report['channels']);
        $this->assertSame(2, $report['eligible_channels']);
        $this->assertCount(1, $report['conflicts']);
        $this->assertSame('project_site_identity_conflict', $report['conflicts'][0]['code']);
        $this->assertSame('project-channel-site:v1:https://shared.example.test/agent', $report['conflicts'][0]['canonical_identity']);
        $this->assertSame([[
            'channel_id' => (int) $wordpress->id,
            'code' => 'project_site_channel_type_unsupported',
        ]], $report['unsupported']);
        $this->assertDatabaseCount('project_channel_site_identities', 1);
    }

    public function test_unbound_target_key_is_stable_and_bound_target_key_requires_capability(): void
    {
        $project = $this->project('legacy-client', 'legacy-project');
        $channel = $this->channel('legacy-target', 'https://legacy.example.test');
        $this->attach($project, $channel);
        $article = $this->article($project, 'legacy-target-article');
        $resolver = app(PublicationBatchTargetResolver::class);

        $legacy = $resolver->resolve($project, $article, ['target_type' => 'channel', 'channel_id' => $channel->id]);
        $this->assertSame('channel:'.$channel->id, $legacy['target_identity']);
        $this->assertNull($legacy['target_snapshot']['project_site_identity']);

        app(ProjectChannelSiteIdentityService::class)->provision($project, $channel);
        $bound = $resolver->resolve($project, $article, ['target_type' => 'channel', 'channel_id' => $channel->id]);
        $this->assertSame('project-channel-site:v1:https://legacy.example.test', $bound['target_identity']);
        $this->assertNotSame($legacy['target_identity'], $bound['target_identity']);
        $this->assertNotSame($legacy['target_snapshot'], $bound['target_snapshot']);

        $channel->forceFill(['channel_config' => []])->save();
        try {
            $resolver->resolve($project, $article, ['target_type' => 'channel', 'channel_id' => $channel->id]);
            $this->fail('A bound site must not publish to a target package that lacks the identity capability.');
        } catch (DomainException $exception) {
            $this->assertSame('publication_project_site_identity_capability_unavailable', $exception->getMessage());
        }
    }

    public function test_bound_site_rejects_cross_project_content_and_disabling_reserves_history_and_returns_not_found(): void
    {
        $first = $this->project('first-client', 'first-project');
        $second = $this->project('second-client', 'second-project');
        $channel = $this->channel('first-target', 'https://first.example.test/site/index.php');
        $this->attach($first, $channel);
        $this->attach($second, $channel);
        $firstArticle = $this->article($first, 'first-site-article');
        $secondArticle = $this->article($second, 'second-site-article');
        $service = app(ProjectChannelSiteIdentityService::class);
        $identity = $service->provision($first, $channel);

        try {
            app(PublicationBatchTargetResolver::class)->resolve($second, $secondArticle, [
                'target_type' => 'channel',
                'channel_id' => $channel->id,
            ]);
            $this->fail('A shared distribution membership is not authorization to publish through another project public site.');
        } catch (DomainException $exception) {
            $this->assertSame('publication_project_site_identity_project_mismatch', $exception->getMessage());
        }

        $payload = app(DistributionPayloadBuilder::class)->build($firstArticle, $service->publicationScope($first, $channel));
        $this->assertSame($first->id, $payload['article']['project_id']);
        $this->assertSame($identity->canonical_identity, $payload['project_site_identity']['canonical_identity']);

        $disabled = $service->disable($first, $channel);
        $this->assertSame('disabled', $disabled->status);
        $this->assertDatabaseHas('project_channel_site_identity_histories', [
            'project_channel_site_identity_id' => $identity->id,
            'canonical_identity' => $identity->canonical_identity,
            'reason' => ProjectChannelSiteIdentityHistory::REASON_DISABLED,
        ]);

        try {
            $service->resolveActiveCanonicalUrl('https://first.example.test/site');
            $this->fail('A disabled canonical identity must not resolve to another project or legacy channel.');
        } catch (ProjectSiteIdentityException $exception) {
            $this->assertSame('project_site_identity_not_found', $exception->identityCode);
        }
        try {
            $service->publicationScope($first, $channel);
            $this->fail('Disabled project sites cannot receive publication payloads.');
        } catch (ProjectSiteIdentityException $exception) {
            $this->assertSame('project_site_identity_disabled', $exception->identityCode);
        }
    }

    public function test_target_package_uses_resolved_canonical_identity_and_disabled_package_hides_static_content(): void
    {
        $project = $this->project('package-client', 'package-project');
        $channel = $this->channel('package-target', 'https://package.example.test/portal/index.php');
        $this->attach($project, $channel);
        $service = app(ProjectChannelSiteIdentityService::class);
        $identity = $service->provision($project, $channel);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel->fresh(), 'gfk_package', 'secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        try {
            $config = (string) $zip->getFromName('config.php');
            $index = (string) $zip->getFromName('index.html');
            $sitemap = (string) $zip->getFromName('sitemap.txt');
            $frontController = (string) $zip->getFromName('public/index.php');
            $this->assertStringContainsString("'public_base_url' => 'https://package.example.test/portal'", $config);
            $this->assertStringContainsString($identity->canonical_identity, $config);
            $this->assertStringContainsString('<link rel="canonical" href="https://package.example.test/portal/">', $index);
            $this->assertSame("https://package.example.test/portal/\n", $sitemap);
            $this->assertStringContainsString('assertProjectSiteArticlePayload', $frontController);
            $this->assertStringContainsString('articleMatchesProjectSiteIdentity', $frontController);
        } finally {
            $zip->close();
            @unlink($package['path']);
        }

        $service->disable($project, $channel);
        $disabledPackage = app(DistributionTargetSitePackageBuilder::class)->build($channel->fresh(), 'gfk_package', 'secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($disabledPackage['path']));
        try {
            $this->assertStringContainsString('Not Found', (string) $zip->getFromName('index.html'));
            $this->assertSame('', (string) $zip->getFromName('sitemap.txt'));
        } finally {
            $zip->close();
            @unlink($disabledPackage['path']);
        }
    }

    private function project(string $clientSlug, string $projectSlug): ClientProject
    {
        $client = Client::query()->create([
            'name' => $clientSlug,
            'slug' => $clientSlug,
            'is_legacy' => true,
        ]);

        return ClientProject::query()->create([
            'client_id' => $client->id,
            'name' => $projectSlug,
            'slug' => $projectSlug,
            'is_legacy' => true,
            'publication_gate' => PublicationGate::LEGACY_AUTO,
        ]);
    }

    private function channel(string $name, string $endpoint, string $type = 'geoflow_agent'): DistributionChannel
    {
        return DistributionChannel::query()->create([
            'name' => $name,
            'domain' => (string) parse_url($endpoint, PHP_URL_HOST),
            'endpoint_url' => $endpoint,
            'channel_type' => $type,
            'status' => DistributionChannel::STATUS_ACTIVE,
            'channel_config' => [
                'frontend_capabilities_cache' => [
                    'status' => 'ok',
                    'reachable' => true,
                    'supports_project_site_identity' => true,
                ],
            ],
        ]);
    }

    private function attach(ClientProject $project, DistributionChannel $channel): void
    {
        ClientProjectDistributionChannel::query()->create([
            'client_project_id' => $project->id,
            'distribution_channel_id' => $channel->id,
            'status' => 'active',
        ]);
    }

    private function article(ClientProject $project, string $slug): Article
    {
        $category = Category::query()->create(['name' => 'Identity category '.$slug, 'slug' => 'identity-category-'.$slug]);
        $author = Author::query()->create(['name' => 'Identity author '.$slug, 'email' => $slug.'@example.test']);

        return Article::query()->create([
            'title' => 'Identity article '.$slug,
            'slug' => $slug,
            'content' => 'Identity content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'client_project_id' => $project->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }
}
