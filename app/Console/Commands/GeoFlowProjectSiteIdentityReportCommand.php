<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ProjectChannelSiteIdentityService;
use Illuminate\Console\Command;

class GeoFlowProjectSiteIdentityReportCommand extends Command
{
    protected $signature = 'geoflow:project-site-identity-report {--json : Emit machine-readable output only}';

    protected $description = 'Read-only preflight for project/channel canonical identity collisions';

    public function handle(ProjectChannelSiteIdentityService $identities): int
    {
        $report = $identities->conflictReport();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['invalid'] === [] && $report['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['channels', 'eligible', 'ready', 'legacy_unbound', 'bound', 'disabled', 'invalid', 'conflicts'],
            [[
                $report['channels'],
                $report['eligible_channels'],
                $report['ready'],
                $report['legacy_unbound'],
                $report['bound'],
                $report['disabled'],
                count($report['invalid']),
                count($report['conflicts']),
            ]],
        );
        foreach ($report['invalid'] as $row) {
            $this->warn('channel #'.$row['channel_id'].': '.$row['code']);
        }
        foreach ($report['unsupported'] as $row) {
            $this->line('channel #'.$row['channel_id'].': '.$row['code']);
        }
        foreach ($report['conflicts'] as $row) {
            $this->warn($row['code'].': '.$row['canonical_identity']);
        }

        return $report['invalid'] === [] && $report['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
