<?php

namespace App\Console\Commands;

use App\Jobs\SendAlertEmail;
use App\Monitoring\MissingReports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('logwatch:check-missing {--through= : The latest day that should have arrived, YYYY-MM-DD (default: yesterday)}')]
#[Description('Raise an alert for each recent day a server did not report')]
class CheckMissingReports extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MissingReports $missing): int
    {
        $option = $this->option('through');
        if ($option !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $option)) {
            $this->components->error('Use --through=YYYY-MM-DD.');

            return self::FAILURE;
        }
        $through = $option !== null ? Carbon::parse((string) $option) : today()->subDay();

        $raised = $missing->check($through, (int) config('logwatch.reports.lookback_days', 3));

        foreach ($raised as $gap) {
            SendAlertEmail::dispatch($gap['server_id'], $gap['date']);
        }

        $this->components->info(count($raised) === 0
            ? 'Every server has reported.'
            : count($raised).' missing '.str('report')->plural(count($raised)).' alerted.');

        return self::SUCCESS;
    }
}
