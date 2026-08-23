<?php

namespace App\Services\GeoFlow;

use App\Models\AiUsageEvent;
use App\Models\ClientProject;
use Illuminate\Support\Arr;

final class AiUsageObservationWriter
{
    /** @param array<string,mixed> $observation */
    public function append(array $observation): AiUsageEvent
    {
        $scope = (string) ($observation['scope'] ?? 'project');
        $scope = in_array($scope, ['project', 'platform', 'system'], true) ? $scope : 'project';
        $data = [
            'client_project_id' => isset($observation['client_project_id']) ? (int) $observation['client_project_id'] : null,
            'scope' => $scope,
            'model' => isset($observation['model']) ? mb_substr(trim((string) $observation['model']), 0, 160) : null,
            'operation' => mb_substr(trim((string) ($observation['operation'] ?? 'unknown')), 0, 80),
            'attempt' => max(1, (int) ($observation['attempt'] ?? 1)),
            'units' => max(0, (int) ($observation['units'] ?? 0)),
            'outcome' => in_array(($observation['outcome'] ?? 'unknown'), ['reserved', 'success', 'failure', 'uncertain', 'fallback'], true) ? (string) $observation['outcome'] : 'unknown',
            'fallback' => (bool) ($observation['fallback'] ?? false),
            'reservation_key' => isset($observation['reservation_key']) ? mb_substr(trim((string) $observation['reservation_key']), 0, 160) : null,
            'metadata' => is_array($observation['metadata'] ?? null) ? Arr::only($observation['metadata'], ['provider', 'latency_ms', 'error_code', 'reason']) : null,
        ];

        if ($data['scope'] === 'project' && $data['client_project_id'] === null) {
            throw new \InvalidArgumentException('Project AI usage observations require a project.');
        }

        if ($data['scope'] === 'project' && ! ClientProject::query()->whereKey($data['client_project_id'])->exists()) {
            throw new \InvalidArgumentException('Project AI usage observations require an existing project.');
        }

        if ($data['reservation_key'] !== null) {
            return AiUsageEvent::query()->firstOrCreate(['reservation_key' => $data['reservation_key']], $data);
        }

        return AiUsageEvent::query()->create($data);
    }
}
