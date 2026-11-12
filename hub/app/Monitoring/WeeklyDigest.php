<?php

namespace App\Monitoring;

use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use App\Models\Server;
use App\Verdict;
use Illuminate\Support\Carbon;

/**
 * One week (Monday to Sunday, UTC) summed up per server: how many reports
 * arrived, the verdicts, the alerts, what the model marked "watch", and a few
 * counts against the week before. Days that haven't happened yet are left out.
 */
class WeeklyDigest
{
    /** Counts worth comparing week on week. */
    public const METRICS = ['ssh_failed', 'scanner_requests', 'nginx_errors', 'app_errors', 'journal_errors'];

    public static function weekStart(Carbon $day): Carbon
    {
        return $day->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /**
     * @return array{
     *     start: Carbon,
     *     end: Carbon,
     *     days: list<Carbon>,
     *     due: int,
     *     clear: bool,
     *     headline: string,
     *     servers: list<array{
     *         server: Server,
     *         states: array<string, string>,
     *         reported: int,
     *         verdicts: array<string, int>,
     *         alerts: list<Alert>,
     *         open_alerts: int,
     *         watch: list<array{date: Carbon, title: string, severity: string}>,
     *         metrics: array<string, array{label: string, this: int, last: int|null}>,
     *         clear: bool,
     *         line: string,
     *     }>,
     * }
     */
    public function build(Carbon $start): array
    {
        $start = self::weekStart($start);
        $end = $start->copy()->addDays(6);
        $yesterday = today()->subDay();

        $days = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $days[] = $day->copy();
        }
        $dueDays = array_values(array_filter($days, fn (Carbon $d): bool => $d->lte($yesterday)));
        $range = [$start->toDateString(), $end->toDateString()];

        $servers = Server::orderBy('name')->get(['id', 'name', 'is_client']);
        $status = $dueDays === [] ? [] : DayStatus::between($dueDays[0], end($dueDays));

        // This week and last, for the comparisons. A few KB each, at most 14 per server.
        $metrics = [];
        DailySummary::query()
            ->whereBetween('date', [$start->copy()->subWeek()->toDateString(), $end->toDateString()])
            ->get(['server_id', 'date', 'payload'])
            ->each(function (DailySummary $summary) use (&$metrics, $start): void {
                $week = $summary->date->lt($start) ? 'last' : 'this';
                $metrics[$summary->server_id][$week][] = array_intersect_key(SummaryMetrics::extract($summary->payload), array_flip(self::METRICS));
            });

        // The latest review of each server-day decides that day's verdict.
        $latestReviews = Review::query()
            ->whereBetween('date', $range)
            ->orderBy('id')
            ->get(['server_id', 'date', 'verdict', 'findings'])
            ->keyBy(fn (Review $r): string => $r->server_id.'|'.$r->date->toDateString());

        $alerts = Alert::query()
            ->whereBetween('date', $range)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->groupBy('server_id');

        $rows = [];
        foreach ($servers as $server) {
            $states = [];
            foreach ($days as $day) {
                $date = $day->toDateString();
                $states[$date] = $day->gt($yesterday) ? 'future' : ($status[$server->id][$date] ?? 'missing');
            }

            $verdicts = ['ok' => 0, 'watch' => 0, 'alert' => 0, 'failed' => 0];
            $watch = [];
            foreach ($latestReviews as $review) {
                if ($review->server_id !== $server->id) {
                    continue;
                }
                $verdicts[$review->verdict?->value ?? 'failed']++;
                if ($review->verdict === Verdict::Watch) {
                    foreach ($review->findings as $finding) {
                        $watch[] = ['date' => $review->date, 'title' => (string) $finding['title'], 'severity' => (string) $finding['severity']];
                    }
                }
            }

            $serverAlerts = $alerts->get($server->id, collect())->values()->all();
            $openAlerts = count(array_filter($serverAlerts, fn (Alert $a): bool => $a->acknowledged_at === null));
            $reported = count($metrics[$server->id]['this'] ?? []);

            $rows[] = [
                'server' => $server,
                'states' => $states,
                'reported' => $reported,
                'verdicts' => $verdicts,
                'alerts' => $serverAlerts,
                'open_alerts' => $openAlerts,
                'watch' => $watch,
                'metrics' => $this->compare($metrics[$server->id] ?? []),
                'clear' => $reported === count($dueDays) && $serverAlerts === [] && $verdicts['watch'] === 0 && $verdicts['failed'] === 0,
                'line' => $this->line($reported, count($dueDays), $verdicts, count($serverAlerts), $openAlerts),
            ];
        }

        $clear = collect($rows)->every('clear');
        $open = array_sum(array_column($rows, 'open_alerts'));
        $attention = count(array_filter($rows, fn (array $r): bool => ! $r['clear']));

        return [
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'due' => count($dueDays),
            'clear' => $clear,
            'headline' => match (true) {
                $rows === [] => 'No servers yet',
                $dueDays === [] => 'Nothing reported yet this week',
                $clear => 'All clear',
                default => "{$attention} of ".count($rows).' '.str('server')->plural(count($rows)).' need a look'
                    .($open > 0 ? " · {$open} open ".str('alert')->plural($open) : ''),
            },
            'servers' => $rows,
        ];
    }

    /**
     * @param  array{this?: list<array<string, int>>, last?: list<array<string, int>>}  $weeks
     * @return array<string, array{label: string, this: int, last: int|null}>
     */
    private function compare(array $weeks): array
    {
        $compared = [];
        foreach (self::METRICS as $key) {
            $compared[$key] = [
                'label' => SummaryMetrics::LABELS[$key],
                'this' => (int) array_sum(array_column($weeks['this'] ?? [], $key)),
                'last' => isset($weeks['last']) ? (int) array_sum(array_column($weeks['last'], $key)) : null,
            ];
        }

        return $compared;
    }

    /**
     * @param  array<string, int>  $verdicts
     */
    private function line(int $reported, int $due, array $verdicts, int $alerts, int $open): string
    {
        $parts = ["{$reported} of {$due} reports"];

        $labels = ['ok' => 'OK', 'watch' => 'watch', 'alert' => 'alert', 'failed' => 'review failed'];
        $counts = [];
        foreach ($labels as $key => $label) {
            if ($verdicts[$key] > 0) {
                $counts[] = "{$verdicts[$key]} {$label}";
            }
        }
        if ($counts !== []) {
            $parts[] = implode(', ', $counts);
        }

        $parts[] = match (true) {
            $alerts === 0 => 'no alerts',
            $open === 0 => "{$alerts} ".str('alert')->plural($alerts).', all acknowledged',
            default => "{$alerts} ".str('alert')->plural($alerts).", {$open} open",
        };

        return implode(' · ', $parts);
    }
}
