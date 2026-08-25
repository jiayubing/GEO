<?php

namespace App\Services\GeoFlow;

use App\Models\ClientProject;
use App\Models\ClientProjectOperationalAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class ProjectOperationalAlertService
{
    /** @param array<string,mixed> $payload */
    public function observe(ClientProject $project, string $kind, string $fingerprint, array $payload = [], string $severity = 'warning'): ClientProjectOperationalAlert
    {
        $now = now();
        return DB::transaction(function () use ($project, $kind, $fingerprint, $payload, $severity, $now): ClientProjectOperationalAlert {
            $alert = ClientProjectOperationalAlert::query()->where('client_project_id', $project->getKey())->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            if ($alert) {
                $alert->forceFill(['kind' => $kind, 'severity' => $severity, 'status' => 'open', 'payload' => $payload, 'last_seen_at' => $now, 'resolved_at' => null])->save();
                return $alert;
            }
            return ClientProjectOperationalAlert::query()->create(['client_project_id' => $project->getKey(), 'fingerprint' => $fingerprint, 'kind' => $kind, 'severity' => $severity, 'status' => 'open', 'payload' => $payload, 'first_seen_at' => $now, 'last_seen_at' => $now]);
        });
    }

    public function resolve(ClientProject $project, string $fingerprint): void
    {
        ClientProjectOperationalAlert::query()->where('client_project_id', $project->getKey())->where('fingerprint', $fingerprint)->where('status', 'open')->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
    }

    /** Scan business facts and upsert deduplicated project alerts. */
    public function scan(ClientProject $project, ?Carbon $now = null): array
    {
        $now ??= now();
        $alerts = [];
        $failed = DB::table('task_runs')->join('tasks', 'tasks.id', '=', 'task_runs.task_id')
            ->where('tasks.client_project_id', $project->getKey())->whereIn('task_runs.status', ['failed', 'cancelled'])
            ->orderByDesc('task_runs.id')->limit(20)->get(['task_runs.id', 'task_runs.error_message']);
        foreach ($failed as $run) {
            $alerts[] = $this->observe($project, 'task_failed', 'task-run:'.$run->id.':failed', ['task_run_id' => (int) $run->id, 'error_code' => mb_substr((string) ($run->error_message ?? ''), 0, 80)], 'error');
        }
        $uncertain = DB::table('client_project_usage_reservations')->where('client_project_id', $project->getKey())->where('state', 'uncertain')->limit(20)->get(['id', 'reservation_key']);
        foreach ($uncertain as $reservation) {
            $alerts[] = $this->observe($project, 'usage_uncertain', 'reservation:'.$reservation->id.':uncertain', ['reservation_id' => (int) $reservation->id, 'reservation_key' => (string) $reservation->reservation_key], 'error');
        }
        $staleSeconds = max(60, (int) config('geoflow.worker_stale_seconds', 120));
        $stale = DB::table('task_runs')->join('tasks', 'tasks.id', '=', 'task_runs.task_id')
            ->where('tasks.client_project_id', $project->getKey())->where('task_runs.status', 'running')->whereNotNull('task_runs.started_at')->where('task_runs.started_at', '<', $now->copy()->subSeconds($staleSeconds))->limit(20)->get(['task_runs.id']);
        foreach ($stale as $run) {
            $alerts[] = $this->observe($project, 'stale_recovery', 'task-run:'.$run->id.':stale', ['task_run_id' => (int) $run->id], 'warning');
        }
        return $alerts;
    }

    public function quotaRejected(ClientProject $project, string $kind, string $fingerprint, string $reason = 'limit_reached'): ClientProjectOperationalAlert
    {
        return $this->observe($project, 'quota_rejected', 'quota:'.$kind.':'.$fingerprint, ['kind' => $kind, 'reason' => $reason], 'warning');
    }

    /** @return list<array<string,mixed>> */
    public function open(ClientProject $project): array
    {
        if (! Schema::hasTable('client_project_operational_alerts')) {
            return [];
        }
        return ClientProjectOperationalAlert::query()->where('client_project_id', $project->getKey())->where('status', 'open')->orderByDesc('last_seen_at')->limit(100)->get()->map(static fn (ClientProjectOperationalAlert $alert): array => [
            'id' => (int) $alert->id, 'kind' => (string) $alert->kind, 'severity' => (string) $alert->severity,
            'fingerprint' => (string) $alert->fingerprint, 'payload' => $alert->payload ?? [], 'last_seen_at' => $alert->last_seen_at?->toDateTimeString(),
        ])->all();
    }
}
