<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\LegacyProjectBackfillService;
use Illuminate\Console\Command;
use RuntimeException;

class GeoFlowLegacyBackfillCommand extends Command
{
    protected $signature = 'geoflow:client-project-backfill-legacy
        {--apply : Apply the idempotent legacy carrier and owner backfill after preflight}
        {--batch-size=500 : Number of rows updated per batch}';

    protected $description = 'Preflight or idempotently backfill existing GEOFlow data into the legacy client project';

    public function handle(LegacyProjectBackfillService $backfill): int
    {
        try {
            $report = $backfill->run((bool) $this->option('apply'), (int) $this->option('batch-size'));
        } catch (RuntimeException $e) {
            $this->components->error('Legacy backfill failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return in_array($report['status'], ['ready', 'completed'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
