<?php

namespace App\Services\GeoFlow;

use App\Models\Client;
use App\Models\ClientProject;
use App\Models\ClientProjectDistributionChannel;
use App\Models\SystemState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LegacyProjectBackfillService
{
    public const REPORT_KEY = 'geoflow.client_project.legacy_backfill';

    /** @var list<string> */
    private const OWNER_TABLES = [
        'knowledge_bases',
        'keyword_libraries',
        'title_libraries',
        'image_libraries',
        'tasks',
        'articles',
        'authors',
        'categories',
        'enterprise_knowledge_projects',
        'url_import_jobs',
        'manual_publications',
    ];

    /** @return array<string,mixed> */
    public function run(bool $apply, int $batchSize = 500): array
    {
        $batchSize = max(1, min(5000, $batchSize));
        $report = [
            'status' => 'preflight',
            'apply' => $apply,
            'batch_size' => $batchSize,
            'owner_counts_before' => [],
            'owner_counts_assigned' => [],
            'owner_counts_after' => [],
            'channel_memberships_created' => 0,
            'anomalies' => [],
            'legacy_client_id' => null,
            'legacy_project_id' => null,
        ];

        $this->assertSchema($report['anomalies']);
        $this->collectAnomalies($report['anomalies']);
        $report['owner_counts_before'] = $this->ownerCounts();

        if ($report['anomalies'] !== []) {
            $report['status'] = 'blocked';
            $this->saveReport($report);

            return $report;
        }

        if (! $apply) {
            $report['status'] = 'ready';
            $this->saveReport($report);

            return $report;
        }

        try {
            DB::transaction(function () use (&$report, $batchSize): void {
                [$client, $project] = $this->lockOrCreateLegacyCarrier();
                $report['legacy_client_id'] = $client->id;
                $report['legacy_project_id'] = $project->id;

                foreach (self::OWNER_TABLES as $tableName) {
                    $assigned = $this->assignOwnerRows($tableName, (int) $project->id, $batchSize);
                    $report['owner_counts_assigned'][$tableName] = $assigned;
                }

                $report['channel_memberships_created'] = $this->createChannelMemberships((int) $project->id);
            });
        } catch (RuntimeException $e) {
            $report['status'] = 'failed';
            $report['error_code'] = 'legacy_backfill_failed';
            $report['error_message'] = $e->getMessage();
            $this->saveReport($report);

            throw $e;
        }

        $report['owner_counts_after'] = $this->ownerCounts();
        $report['status'] = 'completed';
        $report['completed_at'] = now()->toIso8601String();
        $this->saveReport($report);

        return $report;
    }

    /** @param array<string,mixed> $anomalies */
    private function assertSchema(array &$anomalies): void
    {
        foreach (['clients', 'client_projects', 'client_project_distribution_channels', 'system_states'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                $anomalies['missing_table_'.$tableName] = true;
            }
        }

        foreach (self::OWNER_TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'client_project_id')) {
                $anomalies['missing_owner_column_'.$tableName] = true;
            }
        }
    }

    /** @param array<string,mixed> $anomalies */
    private function collectAnomalies(array &$anomalies): void
    {
        foreach (self::OWNER_TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'client_project_id')) {
                continue;
            }

            $orphans = DB::table($tableName.' as owner')
                ->whereNotNull('owner.client_project_id')
                ->whereNotExists(function (Builder $query): void {
                    $query->selectRaw('1')
                        ->from('client_projects')
                        ->whereColumn('client_projects.id', 'owner.client_project_id');
                })
                ->count();
            if ($orphans > 0) {
                $anomalies['orphan_'.$tableName] = $orphans;
            }
        }

        $membershipOrphans = DB::table('client_project_distribution_channels as membership')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('client_projects')->whereColumn('client_projects.id', 'membership.client_project_id');
            })
            ->orWhereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('distribution_channels')->whereColumn('distribution_channels.id', 'membership.distribution_channel_id');
            })
            ->count();
        if ($membershipOrphans > 0) {
            $anomalies['orphan_channel_memberships'] = $membershipOrphans;
        }

        $articleTaskMismatch = DB::table('articles as article')
            ->join('tasks as task', 'task.id', '=', 'article.task_id')
            ->whereNotNull('article.client_project_id')
            ->whereNotNull('task.client_project_id')
            ->whereColumn('article.client_project_id', '!=', 'task.client_project_id')
            ->count();
        if ($articleTaskMismatch > 0) {
            $anomalies['article_task_project_mismatch'] = $articleTaskMismatch;
        }

        // A single-column FK cannot encode the project owner of the
        // published knowledge-base relation. Surface this before any legacy
        // assignment so operators can repair it explicitly.
        $publishedProjectMismatch = DB::table('enterprise_knowledge_projects as enterprise')
            ->join('knowledge_bases as knowledge', 'knowledge.id', '=', 'enterprise.published_knowledge_base_id')
            ->whereNotNull('enterprise.client_project_id')
            ->whereNotNull('knowledge.client_project_id')
            ->whereColumn('enterprise.client_project_id', '!=', 'knowledge.client_project_id')
            ->count();
        if ($publishedProjectMismatch > 0) {
            $anomalies['enterprise_published_knowledge_project_mismatch'] = $publishedProjectMismatch;
        }
    }

    /** @return array<string,int> */
    private function ownerCounts(): array
    {
        $counts = [];
        foreach (self::OWNER_TABLES as $tableName) {
            $counts[$tableName] = Schema::hasTable($tableName) ? DB::table($tableName)->count() : 0;
        }

        return $counts;
    }

    /** @return array{0:Client,1:ClientProject} */
    private function lockOrCreateLegacyCarrier(): array
    {
        $client = Client::query()->where('slug', 'legacy')->lockForUpdate()->first();
        if (! $client instanceof Client) {
            $client = Client::query()->create([
                'name' => 'Legacy',
                'slug' => 'legacy',
                'is_legacy' => true,
                'publication_gate' => 'legacy_auto',
            ]);
        } elseif (! $client->is_legacy) {
            throw new RuntimeException('The reserved legacy client slug is already used by a non-legacy client.');
        }

        $project = ClientProject::query()
            ->where('client_id', $client->id)
            ->where('slug', 'legacy')
            ->lockForUpdate()
            ->first();
        if (! $project instanceof ClientProject) {
            $project = ClientProject::query()->create([
                'client_id' => $client->id,
                'name' => 'Legacy',
                'slug' => 'legacy',
                'is_legacy' => true,
                'publication_gate' => 'legacy_auto',
            ]);
        } elseif (! $project->is_legacy) {
            throw new RuntimeException('The reserved legacy project slug is already used by a non-legacy project.');
        } elseif ($project->publication_gate?->value !== 'legacy_auto') {
            $project->forceFill(['publication_gate' => 'legacy_auto'])->save();
        }

        return [$client, $project];
    }

    private function assignOwnerRows(string $tableName, int $projectId, int $batchSize): int
    {
        $assigned = 0;
        do {
            $ids = DB::table($tableName)
                ->whereNull('client_project_id')
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            $values = ['client_project_id' => $projectId];
            if ($tableName === 'articles' && Schema::hasColumn('articles', 'central_site_allowed')) {
                $values['central_site_allowed'] = true;
            }
            if (Schema::hasColumn($tableName, 'updated_at')) {
                $values['updated_at'] = now();
            }

            $assigned += DB::table($tableName)
                ->whereIn('id', $ids->all())
                ->whereNull('client_project_id')
                ->update($values);
        } while (true);

        return $assigned;
    }

    private function createChannelMemberships(int $projectId): int
    {
        $channelIds = DB::table('task_distribution_channels as binding')
            ->join('tasks as task', 'task.id', '=', 'binding.task_id')
            ->where('task.client_project_id', $projectId)
            ->pluck('binding.distribution_channel_id')
            ->merge(DB::table('article_distributions as distribution')
                ->join('articles as article', 'article.id', '=', 'distribution.article_id')
                ->where('article.client_project_id', $projectId)
                ->pluck('distribution.distribution_channel_id'))
            ->unique()
            ->values();

        $created = 0;
        foreach ($channelIds as $channelId) {
            $membership = ClientProjectDistributionChannel::query()->firstOrCreate(
                ['client_project_id' => $projectId, 'distribution_channel_id' => (int) $channelId],
                ['status' => 'active'],
            );
            $created += $membership->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @param array<string,mixed> $report */
    private function saveReport(array $report): void
    {
        SystemState::query()->updateOrCreate(['key' => self::REPORT_KEY], ['value' => $report]);
    }
}
