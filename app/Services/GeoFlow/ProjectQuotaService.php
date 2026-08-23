<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ProjectQuotaExceeded;
use App\Models\AiUsageEvent;
use App\Models\ClientProject;
use App\Models\ClientProjectQuota;
use App\Models\ClientProjectUsageReservation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Project limits are a guard and reservation ledger. Domain models remain the
 * source of truth for articles/files/tasks; this service never writes them.
 */
final class ProjectQuotaService
{
    private const KINDS = ['ai', 'storage', 'articles', 'concurrency'];

    /** @param array<string,mixed> $limits */
    public function configure(ClientProject $project, array $limits, ?int $adminId = null): ClientProjectQuota
    {
        $allowed = ['ai_units_limit', 'storage_bytes_limit', 'article_count_limit', 'concurrency_limit'];
        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $limits)) {
                $value = $limits[$key];
                if ($value !== null && (! is_int($value) && ! ctype_digit((string) $value) || (int) $value < 0)) {
                    throw new InvalidArgumentException('Quota limits must be non-negative integers or null.');
                }
                $values[$key] = $value === null ? null : (int) $value;
            }
        }
        if ($adminId !== null) {
            $values['updated_by_admin_id'] = $adminId;
        }

        return ClientProjectQuota::query()->updateOrCreate(['client_project_id' => $project->getKey()], $values);
    }

    /** @param array<string,mixed> $options */
    public function reserve(ClientProject $project, string $kind, int $units, string $reservationKey, array $options = []): ClientProjectUsageReservation
    {
        $this->assertInputs($kind, $units, $reservationKey);
        if (! $project->exists || (int) $project->getKey() <= 0) {
            throw new InvalidArgumentException('Project quota operations require an existing project.');
        }

        return DB::transaction(function () use ($project, $kind, $units, $reservationKey, $options): ClientProjectUsageReservation {
            $quota = ClientProjectQuota::query()->where('client_project_id', $project->getKey())->lockForUpdate()->first();
            if (! $quota) {
                $quota = ClientProjectQuota::query()->create(['client_project_id' => $project->getKey()]);
                $quota = ClientProjectQuota::query()->whereKey($quota->getKey())->lockForUpdate()->firstOrFail();
            }
            $existing = ClientProjectUsageReservation::query()
                ->where('client_project_id', $project->getKey())->where('reservation_key', $reservationKey)
                ->lockForUpdate()->first();
            if ($existing) {
                if ($existing->kind !== $kind || (int) $existing->units !== $units) {
                    throw new InvalidArgumentException('project_reservation_conflict');
                }

                return $existing;
            }

            $current = $this->currentUsage($project, $kind, $options);
            $limit = $this->limit($quota, $kind);
            $active = (int) ClientProjectUsageReservation::query()
                ->where('client_project_id', $project->getKey())->where('kind', $kind)
                ->where('state', ClientProjectUsageReservation::RESERVED)->sum('units');
            if ($limit !== null && $current + $active + $units > $limit) {
                throw new ProjectQuotaExceeded('limit_reached', $kind);
            }

            $reservation = ClientProjectUsageReservation::query()->create([
                'client_project_id' => $project->getKey(), 'reservation_key' => $reservationKey,
                'kind' => $kind, 'units' => $units, 'state' => ClientProjectUsageReservation::RESERVED,
                'operation' => isset($options['operation']) ? mb_substr((string) $options['operation'], 0, 80) : null,
                'attempt' => max(1, (int) ($options['attempt'] ?? 1)),
                'metadata' => is_array($options['metadata'] ?? null) ? $options['metadata'] : null,
            ]);
            if ($kind === 'ai') {
                app(AiUsageObservationWriter::class)->append([
                    'client_project_id' => $project->getKey(), 'scope' => 'project',
                    'model' => $options['model'] ?? null, 'operation' => $options['operation'] ?? 'unknown',
                    'attempt' => $options['attempt'] ?? 1, 'units' => 0, 'outcome' => 'reserved',
                    'reservation_key' => $reservationKey.':reserved', 'metadata' => ['reservation_state' => 'reserved'],
                ]);
            }

            return $reservation;
        });
    }

    public function finalize(ClientProjectUsageReservation $reservation, string $outcome, ?int $units = null): ClientProjectUsageReservation
    {
        if (! in_array($outcome, [ClientProjectUsageReservation::SUCCESS, ClientProjectUsageReservation::FAILURE, ClientProjectUsageReservation::UNCERTAIN], true)) {
            throw new InvalidArgumentException('Invalid project reservation outcome.');
        }

        return DB::transaction(function () use ($reservation, $outcome, $units): ClientProjectUsageReservation {
            $row = ClientProjectUsageReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            if ($row->state !== ClientProjectUsageReservation::RESERVED) {
                return $row;
            }
            $row->forceFill(['state' => $outcome])->save();
            if ($row->kind === 'ai') {
                app(AiUsageObservationWriter::class)->append([
                    'client_project_id' => $row->client_project_id, 'scope' => 'project',
                    'operation' => $row->operation ?? 'unknown', 'attempt' => $row->attempt,
                    'units' => $outcome === ClientProjectUsageReservation::FAILURE ? 0 : ($units ?? $row->units),
                    'outcome' => $outcome, 'reservation_key' => $row->reservation_key.':'.$outcome,
                    'metadata' => ['reservation_state' => $outcome],
                ]);
            }

            return $row;
        });
    }

    public function release(ClientProjectUsageReservation $reservation): ClientProjectUsageReservation
    {
        return DB::transaction(function () use ($reservation): ClientProjectUsageReservation {
            $row = ClientProjectUsageReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            if ($row->state !== ClientProjectUsageReservation::RESERVED) {
                return $row;
            }
            $row->forceFill(['state' => ClientProjectUsageReservation::RELEASED])->save();
            if ($row->kind === 'ai') {
                app(AiUsageObservationWriter::class)->append([
                    'client_project_id' => $row->client_project_id, 'scope' => 'project',
                    'operation' => $row->operation ?? 'unknown', 'attempt' => $row->attempt, 'units' => 0,
                    'outcome' => 'failure', 'reservation_key' => $row->reservation_key.':released',
                    'metadata' => ['reservation_state' => 'released'],
                ]);
            }

            return $row;
        });
    }

    /** @param array<string,mixed> $options */
    private function currentUsage(ClientProject $project, string $kind, array $options): int
    {
        if (array_key_exists('current_usage', $options)) {
            return max(0, (int) $options['current_usage']);
        }
        if ($kind !== 'ai') {
            return 0;
        }

        return (int) AiUsageEvent::query()->where('client_project_id', $project->getKey())
            ->whereIn('outcome', ['success', 'fallback', 'uncertain'])->sum('units');
    }

    private function limit(ClientProjectQuota $quota, string $kind): ?int
    {
        return match ($kind) {
            'ai' => $quota->ai_units_limit,
            'storage' => $quota->storage_bytes_limit,
            'articles' => $quota->article_count_limit,
            'concurrency' => $quota->concurrency_limit,
        };
    }

    private function assertInputs(string $kind, int $units, string $key): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unsupported project quota kind.');
        }
        if ($units < 0 || trim($key) === '' || mb_strlen($key) > 160) {
            throw new InvalidArgumentException('Invalid project reservation.');
        }
    }
}
