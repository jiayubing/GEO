<?php

namespace Tests\Feature;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use App\Enums\ClientProjectStatus;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectMember;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\ProjectAccessService;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class UrlImportProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_operator_creates_an_immutable_project_owned_job_and_reuses_normalized_url_reservation(): void
    {
        [$operator, $projectA] = $this->memberWithProject('create-a', ClientProjectMemberRole::OPERATOR);
        $projectB = $this->project('create-b');
        $this->readyAiModel();

        $response = $this->actingAs($operator, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'project_name' => 'Project A import',
                'project_id' => $projectB->id,
                'outputs' => ['knowledge', 'keywords'],
            ]);

        $job = UrlImportJob::query()->sole();
        $response->assertRedirect(route('admin.url-import.show', ['jobId' => $job->id]));
        $this->assertSame((int) $projectA->id, (int) $job->client_project_id);
        $this->assertSame('https://example.test/report', (string) $job->normalized_url);

        $this->actingAs($operator, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->post(route('admin.url-import.store'), [
                'url' => 'https://example.test/report',
                'project_name' => 'Retry must reuse job',
                'outputs' => ['knowledge'],
            ])
            ->assertRedirect(route('admin.url-import.show', ['jobId' => $job->id]));

        $this->assertSame(1, UrlImportJob::query()->count());
        $this->expectException(LogicException::class);
        $job->update(['client_project_id' => $projectB->id]);
    }

    public function test_schema_keeps_project_lookup_log_lookup_and_idempotency_reservation_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('url_import_job_idempotencies'));
        foreach ([
            'commit_status',
            'committed_knowledge_base_id',
            'committed_keyword_library_id',
            'committed_title_library_id',
            'commit_started_at',
            'commit_finished_at',
            'commit_error_code',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('url_import_jobs', $column));
        }
        $this->assertContains(
            'url_import_jobs_project_url_created_index',
            collect(Schema::getIndexes('url_import_jobs'))->pluck('name')->all(),
        );
        $this->assertContains(
            'url_import_job_logs_job_created_index',
            collect(Schema::getIndexes('url_import_job_logs'))->pluck('name')->all(),
        );
        $this->assertContains(
            'url_import_job_idempotencies_scope_url_unique',
            collect(Schema::getIndexes('url_import_job_idempotencies'))->pluck('name')->all(),
        );
    }

    public function test_project_routes_never_return_or_mutate_another_projects_job(): void
    {
        [$operator, $projectA] = $this->memberWithProject('route-a', ClientProjectMemberRole::OPERATOR);
        $projectB = $this->project('route-b');
        $visible = $this->job($projectA, 'https://visible.test/a');
        $hidden = $this->job($projectB, 'https://hidden.test/b');

        $this->actingAs($operator, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->get(route('admin.url-import.history'))
            ->assertOk()
            ->assertSee('visible.test')
            ->assertDontSee('hidden.test');

        foreach ([
            fn () => $this->get(route('admin.url-import.show', ['jobId' => $hidden->id])),
            fn () => $this->getJson(route('admin.url-import.status', ['jobId' => $hidden->id])),
            fn () => $this->postJson(route('admin.url-import.run', ['jobId' => $hidden->id])),
            fn () => $this->post(route('admin.url-import.commit', ['jobId' => $hidden->id])),
        ] as $request) {
            $this->actingAs($operator, 'admin')
                ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id]);
            $request()->assertNotFound();
        }

        $this->actingAs($operator, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $projectA->id])
            ->getJson(route('admin.url-import.status', ['jobId' => $visible->id]))
            ->assertOk()
            ->assertJsonPath('id', (int) $visible->id);
    }

    public function test_operator_can_open_url_import_index_in_an_explicit_project_context(): void
    {
        [$operator, $project] = $this->memberWithProject('index-access', ClientProjectMemberRole::OPERATOR);

        $this->actingAs($operator, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->get(route('admin.url-import'))
            ->assertOk();
    }

    public function test_viewers_and_stale_project_contexts_are_rejected_before_url_import_mutation(): void
    {
        [$viewer, $project] = $this->memberWithProject('viewer', ClientProjectMemberRole::VIEWER);
        $job = $this->job($project, 'https://viewer.test/report');

        $this->actingAs($viewer, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->postJson(route('admin.url-import.run', ['jobId' => $job->id]))
            ->assertForbidden();
        $this->actingAs($viewer, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->post(route('admin.url-import.commit', ['jobId' => $job->id]))
            ->assertForbidden();

        $project->update(['status' => ClientProjectStatus::SUSPENDED]);

        $this->actingAs($viewer, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->get(route('admin.url-import.show', ['jobId' => $job->id]))
            ->assertForbidden();
    }

    public function test_cli_requires_an_exact_owner_scope_and_uses_stable_exit_contracts(): void
    {
        $project = $this->project('cli');
        $job = $this->job($project, 'https://cli.test/report', 'completed');

        $this->assertSame(1, Artisan::call('geoflow:process-url-import', ['jobId' => $job->id]));
        $this->assertStringContainsString('url_import_owner_scope_required', Artisan::output());

        $this->assertSame(1, Artisan::call('geoflow:process-url-import', [
            'jobId' => $job->id,
            '--project' => $project->id + 1,
        ]));
        $this->assertStringContainsString('url_import_project_mismatch', Artisan::output());

        $this->assertSame(0, Artisan::call('geoflow:process-url-import', [
            'jobId' => $job->id,
            '--project' => $project->id,
        ]));
        $this->assertStringContainsString('url_import_job_already_completed', Artisan::output());

        $legacy = UrlImportJob::query()->create([
            'url' => 'https://legacy.test/report',
            'normalized_url' => 'https://legacy.test/report',
            'source_domain' => 'legacy.test',
            'status' => 'completed',
            'current_step' => 'preview',
            'progress_percent' => 100,
        ]);
        $this->assertSame(0, Artisan::call('geoflow:process-url-import', [
            'jobId' => $legacy->id,
            '--legacy' => true,
        ]));
        $this->assertStringContainsString('url_import_job_already_completed', Artisan::output());
    }

    public function test_legacy_jobs_require_the_explicit_super_admin_compatibility_context(): void
    {
        $project = $this->project('legacy-context');
        [$operator] = $this->memberWithProject('legacy-context-operator', ClientProjectMemberRole::OPERATOR);
        $superAdmin = Admin::query()->create([
            'username' => 'legacy-context-super',
            'password' => 'secret-123',
            'email' => 'legacy-context-super@example.com',
            'display_name' => 'Legacy Context Super Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $legacy = UrlImportJob::query()->create([
            'url' => 'https://legacy-context.test/report',
            'normalized_url' => 'https://legacy-context.test/report',
            'source_domain' => 'legacy-context.test',
            'status' => 'completed',
            'current_step' => 'preview',
            'progress_percent' => 100,
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.url-import.show', ['jobId' => $legacy->id]))
            ->assertStatus(409);
        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.url-import.show', ['jobId' => $legacy->id, 'legacy' => 1]))
            ->assertOk()
            ->assertSee('legacy-context.test');
        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.url-import.history', ['legacy' => 1]))
            ->assertOk()
            ->assertSee('legacy-context.test');
        $this->actingAs($operator, 'admin')
            ->withSession([ProjectAccessService::SESSION_KEY => $project->id])
            ->get(route('admin.url-import.show', ['jobId' => $legacy->id, 'legacy' => 1]))
            ->assertForbidden();
    }

    public function test_log_messages_strip_url_query_parameters(): void
    {
        $project = $this->project('logs');
        $job = $this->job($project, 'https://logs.test/report');
        $secret = 'not-for-logs';

        UrlImportJobLog::query()->create([
            'job_id' => $job->id,
            'step' => 'fetch',
            'level' => 'info',
            'message' => 'Fetching https://logs.test/report?token='.$secret.'&page=2',
        ]);

        $message = (string) UrlImportJobLog::query()->latest('id')->value('message');
        $this->assertSame('Fetching https://logs.test/report', $message);
        $this->assertStringNotContainsString($secret, $message);
    }

    public function test_commit_reloads_the_persisted_job_and_keeps_every_created_asset_in_its_project(): void
    {
        $projectA = $this->project('commit-a');
        $projectB = $this->project('commit-b');
        $job = $this->previewJob($projectA, 'https://commit-a.test/report');
        $stalePayload = $job->fresh();
        $stalePayload->setRawAttributes([
            ...$stalePayload->getAttributes(),
            'client_project_id' => $projectB->id,
        ], true);

        $service = app(UrlImportProcessingService::class);
        $first = $service->commit($stalePayload);
        $second = $service->commit($job->fresh());

        $this->assertSame($first, $second);
        $this->assertSame(1, KnowledgeBase::query()->where('client_project_id', $projectA->id)->count());
        $this->assertSame(1, KeywordLibrary::query()->where('client_project_id', $projectA->id)->count());
        $this->assertSame(1, TitleLibrary::query()->where('client_project_id', $projectA->id)->count());
        $this->assertSame(0, KnowledgeBase::query()->where('client_project_id', $projectB->id)->count());

        $committed = $job->fresh();
        $this->assertSame('imported', $committed->commit_status);
        $this->assertSame((int) $projectA->id, (int) KnowledgeBase::query()->findOrFail($first['knowledge_base'])->client_project_id);
        $this->assertSame((int) $projectA->id, (int) KeywordLibrary::query()->findOrFail($first['keyword_library'])->client_project_id);
        $titleLibrary = TitleLibrary::query()->findOrFail($first['title_library']);
        $this->assertSame((int) $projectA->id, (int) $titleLibrary->client_project_id);
        $this->assertSame((int) $first['keyword_library'], (int) $titleLibrary->keyword_library_id);
    }

    public function test_commit_rejects_tampered_preview_source_and_records_a_safe_failure_code(): void
    {
        $project = $this->project('tampered-preview');
        $job = $this->previewJob($project, 'https://tampered-preview.test/report');
        $result = json_decode((string) $job->result_json, true);
        $result['source']['normalized_url'] = 'https://another-project.test/report';
        $job->update(['result_json' => json_encode($result, JSON_UNESCAPED_UNICODE)]);

        try {
            app(UrlImportProcessingService::class)->commit($job);
            $this->fail('A preview whose source no longer matches its persistent job must not commit.');
        } catch (\DomainException $exception) {
            $this->assertSame('url_import_preview_source_mismatch', $exception->getMessage());
        }

        $this->assertSame('failed', (string) $job->fresh()->commit_status);
        $this->assertSame('url_import_preview_source_mismatch', (string) $job->fresh()->commit_error_code);
        $this->assertSame(0, KnowledgeBase::query()->count());
    }

    public function test_imported_job_with_missing_or_cross_project_artifacts_becomes_uncertain_without_recreating_assets(): void
    {
        $projectA = $this->project('uncertain-a');
        $projectB = $this->project('uncertain-b');
        $job = $this->previewJob($projectA, 'https://uncertain-a.test/report');
        $foreignKnowledge = KnowledgeBase::query()->create([
            'name' => 'Foreign knowledge',
            'content' => 'foreign',
            'client_project_id' => $projectB->id,
        ]);
        $foreignKeywords = KeywordLibrary::query()->create([
            'name' => 'Foreign keywords',
            'client_project_id' => $projectB->id,
        ]);
        $foreignTitles = TitleLibrary::query()->create([
            'name' => 'Foreign titles',
            'keyword_library_id' => $foreignKeywords->id,
            'client_project_id' => $projectB->id,
        ]);
        $job->forceFill([
            'commit_status' => 'imported',
            'committed_knowledge_base_id' => $foreignKnowledge->id,
            'committed_keyword_library_id' => $foreignKeywords->id,
            'committed_title_library_id' => $foreignTitles->id,
        ])->save();

        try {
            app(UrlImportProcessingService::class)->commit($job);
            $this->fail('A cross-project imported artifact must require manual investigation.');
        } catch (\DomainException $exception) {
            $this->assertSame('url_import_commit_uncertain', $exception->getMessage());
        }

        $this->assertSame('uncertain', (string) $job->fresh()->commit_status);
        $this->assertSame(1, KnowledgeBase::query()->count());
        $this->assertSame(1, KeywordLibrary::query()->count());
        $this->assertSame(1, TitleLibrary::query()->count());
    }

    public function test_interrupted_committing_job_is_uncertain_and_never_recreates_assets_on_retry(): void
    {
        $project = $this->project('commit-restart');
        $job = $this->previewJob($project, 'https://commit-restart.test/report');
        $job->forceFill([
            'commit_status' => 'committing',
            'commit_started_at' => now()->subMinutes(5),
        ])->save();

        try {
            app(UrlImportProcessingService::class)->commit($job->fresh());
            $this->fail('An interrupted commit must require manual investigation.');
        } catch (\DomainException $exception) {
            $this->assertSame('url_import_commit_uncertain', $exception->getMessage());
        }

        $this->assertSame('uncertain', (string) $job->fresh()->commit_status);
        $this->assertSame('url_import_commit_uncertain', (string) $job->fresh()->commit_error_code);
        $this->assertSame(0, KnowledgeBase::query()->count());
        $this->assertSame(0, KeywordLibrary::query()->count());
        $this->assertSame(0, TitleLibrary::query()->count());
    }

    public function test_worker_refuses_an_inactive_owner_and_only_recovers_running_jobs_after_an_explicit_stale_check(): void
    {
        $project = $this->project('worker-owner');
        $job = $this->job($project, 'https://worker-owner.test/report');
        $project->update(['status' => ClientProjectStatus::SUSPENDED]);

        $this->assertSame(1, Artisan::call('geoflow:process-url-import', [
            'jobId' => $job->id,
            '--project' => $project->id,
        ]));
        $this->assertStringContainsString('url_import_project_inactive', Artisan::output());
        $this->assertSame(0, KnowledgeBase::query()->count());

        $preview = $this->previewJob($project, 'https://worker-owner.test/commit');
        try {
            app(UrlImportProcessingService::class)->commit($preview);
            $this->fail('An inactive project must not receive committed materials.');
        } catch (\DomainException $exception) {
            $this->assertSame('url_import_project_inactive', $exception->getMessage());
        }
        $this->assertSame(0, KnowledgeBase::query()->count());

        $project->update(['status' => ClientProjectStatus::ACTIVE]);
        $job->forceFill(['status' => 'running', 'started_at' => now()->subMinutes(20)])->save();
        $recovered = app(UrlImportProcessingService::class)->recoverStaleProcessingById((int) $job->id);

        $this->assertSame('queued', (string) $recovered->status);
        $this->assertSame('url_import_processing_interrupted', (string) $recovered->error_message);
    }

    public function test_worker_rejects_unowned_jobs_in_a_projected_database_and_requires_staleness_for_recovery(): void
    {
        $project = $this->project('worker-owner-missing');
        $unowned = UrlImportJob::query()->create([
            'url' => 'https://unowned-worker.test/report',
            'normalized_url' => 'https://unowned-worker.test/report',
            'source_domain' => 'unowned-worker.test',
            'status' => 'queued',
            'current_step' => 'queued',
            'progress_percent' => 0,
        ]);

        $this->assertSame(1, Artisan::call('geoflow:process-url-import', [
            'jobId' => $unowned->id,
            '--legacy' => true,
        ]));
        $this->assertStringContainsString('url_import_job_owner_missing', Artisan::output());

        $running = $this->job($project, 'https://recover-owner.test/report');
        $running->forceFill(['status' => 'running', 'started_at' => now()])->save();
        try {
            app(UrlImportProcessingService::class)->recoverStaleProcessingById((int) $running->id);
            $this->fail('A current running job must not be recovered as stale.');
        } catch (\DomainException $exception) {
            $this->assertSame('url_import_job_not_recoverable', $exception->getMessage());
        }
        $this->assertSame('running', (string) $running->fresh()->status);
    }

    /** @return array{0:Admin,1:ClientProject} */
    private function memberWithProject(string $slug, ClientProjectMemberRole $role): array
    {
        $admin = Admin::query()->create([
            'username' => $slug.'-admin',
            'password' => 'secret-123',
            'email' => $slug.'-admin@example.com',
            'display_name' => 'URL Import Member',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $project = $this->project($slug);
        ClientProjectMember::query()->create([
            'client_project_id' => $project->id,
            'admin_id' => $admin->id,
            'role' => $role,
            'status' => ClientProjectMemberStatus::ACTIVE,
        ]);

        return [$admin, $project];
    }

    private function project(string $slug): ClientProject
    {
        $client = Client::query()->create([
            'name' => 'Client '.$slug,
            'slug' => 'client-'.$slug,
        ]);

        return ClientProject::query()->create([
            'client_id' => $client->id,
            'name' => 'Project '.$slug,
            'slug' => 'project-'.$slug,
        ]);
    }

    private function job(ClientProject $project, string $url, string $status = 'queued'): UrlImportJob
    {
        return UrlImportJob::query()->create([
            'client_project_id' => $project->id,
            'url' => $url,
            'normalized_url' => $url,
            'source_domain' => (string) parse_url($url, PHP_URL_HOST),
            'status' => $status,
            'current_step' => $status === 'completed' ? 'preview' : 'queued',
            'progress_percent' => $status === 'completed' ? 100 : 0,
        ]);
    }

    private function previewJob(ClientProject $project, string $url): UrlImportJob
    {
        $job = $this->job($project, $url, 'completed');
        $job->update([
            'result_json' => json_encode([
                'source' => [
                    'normalized_url' => $url,
                    'domain' => (string) parse_url($url, PHP_URL_HOST),
                ],
                'page' => [
                    'title' => 'Project-owned URL import',
                    'text' => 'Project-owned knowledge content.',
                ],
                'analysis' => [
                    'library_name' => 'Project-owned URL import',
                    'summary' => 'Project-owned summary.',
                    'knowledge_markdown' => "# Project-owned URL import\n\nProject-owned knowledge content.",
                    'keywords' => ['project owner', 'url import'],
                    'titles' => ['How project ownership protects URL imports'],
                ],
                'import' => ['status' => 'preview', 'summary' => null],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $job->fresh();
    }

    private function readyAiModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'URL Import AI Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }
}
