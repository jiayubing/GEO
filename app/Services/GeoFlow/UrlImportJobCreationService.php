<?php

namespace App\Services\GeoFlow;

use App\Models\ClientProject;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the short-lived de-duplication reservation for URL import creation.
 * The UrlImportJob remains the only business owner of client_project_id.
 */
final class UrlImportJobCreationService
{
    /**
     * @param  array{url:string,host:string}  $normalized
     * @param  array<string,mixed>  $input
     * @return array{job:UrlImportJob,created:bool}
     */
    public function create(?ClientProject $project, array $normalized, array $input, string $createdBy): array
    {
        $scope = $this->scopeFor($project);

        try {
            return DB::transaction(function () use ($project, $normalized, $input, $createdBy, $scope): array {
                $reservation = DB::table('url_import_job_idempotencies')
                    ->where('owner_scope', $scope)
                    ->where('normalized_url', $normalized['url'])
                    ->lockForUpdate()
                    ->first();

                $duplicate = $this->validReservedJob($reservation, $project);
                if ($duplicate !== null) {
                    return ['job' => $duplicate, 'created' => false];
                }

                $job = UrlImportJob::query()->create([
                    'url' => (string) $input['url'],
                    'normalized_url' => $normalized['url'],
                    'source_domain' => $normalized['host'],
                    'page_title' => (string) ($input['project_name'] ?? ''),
                    'status' => 'queued',
                    'current_step' => 'queued',
                    'progress_percent' => 0,
                    'options_json' => json_encode([
                        'project_name' => $input['project_name'] ?? '',
                        'source_label' => $input['source_label'] ?? '',
                        'content_language' => $input['content_language'] ?? '',
                        'notes' => $input['notes'] ?? '',
                        'outputs' => $input['outputs'] ?? ['knowledge', 'keywords', 'titles'],
                    ], JSON_UNESCAPED_UNICODE),
                    'result_json' => '',
                    'error_message' => '',
                    'created_by' => $createdBy,
                    'client_project_id' => $project?->getKey(),
                ]);

                $values = [
                    'url_import_job_id' => (int) $job->id,
                    'expires_at' => $this->expiresAt(),
                    'updated_at' => now(),
                ];
                if ($reservation === null) {
                    DB::table('url_import_job_idempotencies')->insert([
                        'owner_scope' => $scope,
                        'normalized_url' => $normalized['url'],
                        ...$values,
                        'created_at' => now(),
                    ]);
                } else {
                    DB::table('url_import_job_idempotencies')->where('id', $reservation->id)->update($values);
                }

                UrlImportJobLog::query()->create([
                    'job_id' => $job->id,
                    'step' => 'queued',
                    'level' => 'info',
                    'message' => __('admin.url_import.section.new_job_desc'),
                ]);

                return ['job' => $job, 'created' => true];
            }, 3);
        } catch (QueryException $exception) {
            $duplicate = $this->findDuplicate($project, $normalized['url']);
            if ($duplicate !== null) {
                return ['job' => $duplicate, 'created' => false];
            }

            throw $exception;
        }
    }

    public function findDuplicate(?ClientProject $project, string $normalizedUrl): ?UrlImportJob
    {
        $reservation = DB::table('url_import_job_idempotencies')
            ->where('owner_scope', $this->scopeFor($project))
            ->where('normalized_url', $normalizedUrl)
            ->where('expires_at', '>', now())
            ->first();

        return $this->validReservedJob($reservation, $project);
    }

    private function validReservedJob(?object $reservation, ?ClientProject $project): ?UrlImportJob
    {
        if ($reservation === null || Carbon::parse($reservation->expires_at)->lessThanOrEqualTo(now())) {
            return null;
        }

        return UrlImportJob::query()
            ->whereKey((int) $reservation->url_import_job_id)
            ->when(
                $project instanceof ClientProject,
                fn ($query) => $query->where('client_project_id', (int) $project->getKey()),
                fn ($query) => $query->whereNull('client_project_id'),
            )
            ->first();
    }

    private function scopeFor(?ClientProject $project): string
    {
        return $project instanceof ClientProject ? 'project:'.(int) $project->getKey() : 'legacy';
    }

    private function expiresAt(): Carbon
    {
        return now()->addSeconds((int) config('geoflow.url_import_idempotency_window_seconds', 900));
    }
}
