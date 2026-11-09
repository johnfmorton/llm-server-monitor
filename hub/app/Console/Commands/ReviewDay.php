<?php

namespace App\Console\Commands;

use App\Jobs\ReviewSummary;
use App\Jobs\SendAlertEmail;
use App\Models\Server;
use App\Monitoring\RuleRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('logwatch:review {name : The server} {date : The day, YYYY-MM-DD} {--now : Run the review here instead of queueing it}')]
#[Description('Run the hard rules and review one server-day again, e.g. after changing its notes')]
class ReviewDay extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RuleRecorder $rules): int
    {
        $server = Server::firstWhere('name', (string) $this->argument('name'));
        $summary = $server?->dailySummaries()->where('date', (string) $this->argument('date'))->first();
        if ($summary === null) {
            $this->components->error('No summary for that server and day.');

            return self::FAILURE;
        }
        $summary->setRelation('server', $server);

        $raised = $rules->record($summary);
        $this->components->info("Hard rules: {$raised} new ".str('alert')->plural($raised).'.');
        if ($raised > 0) {
            SendAlertEmail::dispatch($server->id, $summary->date->toDateString());
        }

        if ($this->option('now')) {
            ReviewSummary::dispatchSync($summary);
            $review = $summary->reviews()->latest('id')->first();
            $this->components->info('Review: '.($review?->verdict?->value ?? 'failed: '.$review?->error));
        } else {
            ReviewSummary::dispatch($summary);
            $this->components->info('Review queued.');
        }

        return self::SUCCESS;
    }
}
