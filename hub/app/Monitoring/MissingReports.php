<?php

namespace App\Monitoring;

use App\AlertSource;
use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Server;
use Illuminate\Support\Carbon;

/**
 * Raises an alert for each recent day a server didn't report.
 *
 * Only servers that have reported at least once are checked, and only from
 * their first report on, so a server added ahead of its collector doesn't
 * alert. A day already alerted (acknowledged or not) isn't alerted again.
 */
class MissingReports
{
    /**
     * @param  Carbon  $through  the latest day that should have arrived by now
     * @return list<array{server_id: int, date: string}> the server-days newly alerted
     */
    public function check(Carbon $through, int $lookbackDays): array
    {
        $from = $through->copy()->subDays(max(1, $lookbackDays) - 1)->startOfDay();
        $range = [$from->toDateString(), $through->toDateString()];

        $servers = Server::query()->whereNotNull('last_report_at')->get(['id', 'name', 'last_report_at']);

        $firstReport = DailySummary::query()
            ->selectRaw('server_id, min(date) as first_date')
            ->groupBy('server_id')
            ->toBase()
            ->pluck('first_date', 'server_id');

        $reported = [];
        DailySummary::query()->whereBetween('date', $range)->toBase()->get(['server_id', 'date'])
            ->each(function (object $row) use (&$reported): void {
                $reported[$row->server_id][substr((string) $row->date, 0, 10)] = true;
            });

        $raised = [];
        foreach ($servers as $server) {
            // Summaries are pruned after 30 days, so a server silent for longer falls back to its last report.
            $first = substr((string) ($firstReport[$server->id] ?? $server->last_report_at->toDateString()), 0, 10);

            for ($day = $from->copy(); $day->lte($through); $day->addDay()) {
                $date = $day->toDateString();
                if ($date < $first || isset($reported[$server->id][$date])) {
                    continue;
                }

                $alert = Alert::firstOrCreate(
                    ['server_id' => $server->id, 'date' => $date, 'source' => AlertSource::MissingReport],
                    ['title' => "No report from {$server->name} for ".$day->format('D j M')],
                );
                if ($alert->wasRecentlyCreated) {
                    $raised[] = ['server_id' => $server->id, 'date' => $date];
                }
            }
        }

        return $raised;
    }
}
