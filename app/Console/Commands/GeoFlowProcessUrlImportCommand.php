<?php

namespace App\Console\Commands;

use App\Enums\ClientProjectStatus;
use App\Models\ClientProject;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Console\Command;

class GeoFlowProcessUrlImportCommand extends Command
{
    protected $signature = 'geoflow:process-url-import
        {jobId : URL import job ID}
        {--project= : Exact client project ID for a project-owned job}
        {--legacy : Explicitly process an unowned legacy job}
        {--recover-stale : Explicitly recover a stale running job before processing}';

    protected $description = 'Process a GEOFlow URL smart import job in the background';

    public function handle(UrlImportProcessingService $service): int
    {
        $job = UrlImportJob::query()->whereKey((int) $this->argument('jobId'))->first();
        if (! $job) {
            $this->error('url_import_job_not_found');

            return self::FAILURE;
        }

        if (! $this->hasExpectedOwner($job)) {
            return self::FAILURE;
        }

        if (in_array($job->status, ['completed', 'imported'], true)) {
            $this->info('url_import_job_already_completed');

            return self::SUCCESS;
        }

        if ($job->status === 'running' && ! $this->option('recover-stale')) {
            $this->error('url_import_job_already_running');

            return self::FAILURE;
        }

        try {
            if ($job->status === 'running') {
                $service->recoverStaleProcessingById((int) $job->getKey());
            }
            $result = $service->processById((int) $job->getKey());
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($result->status === 'failed') {
            $this->error('url_import_job_failed');

            return self::FAILURE;
        }

        $this->info('url_import_job_processed');

        return self::SUCCESS;
    }

    private function hasExpectedOwner(UrlImportJob $job): bool
    {
        $projectOption = $this->option('project');
        $projectId = is_numeric($projectOption) ? (int) $projectOption : 0;
        $legacy = (bool) $this->option('legacy');

        if (($projectId > 0 && $legacy) || ($projectId <= 0 && ! $legacy)) {
            $this->error('url_import_owner_scope_required');

            return false;
        }

        if ($job->client_project_id === null) {
            if ($legacy) {
                return true;
            }

            $this->error('url_import_legacy_confirmation_required');

            return false;
        }

        if ($legacy || $projectId !== (int) $job->client_project_id) {
            $this->error('url_import_project_mismatch');

            return false;
        }

        $project = ClientProject::query()->find((int) $job->client_project_id);
        if ($project === null || $project->status !== ClientProjectStatus::ACTIVE) {
            $this->error('url_import_project_inactive');

            return false;
        }

        return true;
    }
}
