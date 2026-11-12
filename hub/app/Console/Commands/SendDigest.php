<?php

namespace App\Console\Commands;

use App\Mail\DigestMail;
use App\Monitoring\WeeklyDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

#[Signature('logwatch:digest {--week= : Any day in the week, YYYY-MM-DD (default: last week)} {--email : Also email it, unless in silent mode or no recipient is set}')]
#[Description('Show the weekly digest, one line per server, and optionally email it')]
class SendDigest extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(WeeklyDigest $builder): int
    {
        $week = $this->option('week');
        if ($week !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $week)) {
            $this->components->error('Use --week=YYYY-MM-DD.');

            return self::FAILURE;
        }
        $digest = $builder->build($week !== null ? Carbon::parse((string) $week) : today()->subWeek());

        $this->components->info('Week of '.$digest['start']->format('j M Y').': '.$digest['headline']);
        foreach ($digest['servers'] as $row) {
            $this->components->twoColumnDetail($row['server']->name, $row['line']);
        }

        if (! $this->option('email')) {
            return self::SUCCESS;
        }

        $recipient = config('logwatch.alerts.to');
        if (config('logwatch.alerts.silent') || blank($recipient)) {
            $this->components->warn('Not emailed: silent mode is on or no recipient is set.');

            return self::SUCCESS;
        }

        Mail::to($recipient)->send(new DigestMail($digest));
        $this->components->info("Emailed to {$recipient}.");

        return self::SUCCESS;
    }
}
