<?php

namespace App\Monitoring;

use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use Illuminate\Support\Carbon;

/**
 * The colour of each server-day: the latest review's verdict, "pending" when
 * a summary has no verdict yet, and "alert" whenever an unacknowledged alert
 * exists, which covers days with no summary at all (a missing report).
 * Days with no entry have no report.
 *
 * Two small queries; summary payloads are never loaded.
 */
class DayStatus
{
    /**
     * @return array<int, array<string, string>> server id => date => state
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];

        $summaries = DailySummary::query()
            ->select(['id', 'server_id', 'date'])
            ->addSelect(['verdict' => Review::select('verdict')
                ->whereColumn('reviews.daily_summary_id', 'daily_summaries.id')
                ->latest('id')
                ->limit(1)])
            ->whereBetween('date', $range)
            ->toBase()
            ->get();

        $openAlerts = Alert::query()
            ->whereNull('acknowledged_at')
            ->whereBetween('date', $range)
            ->toBase()
            ->get(['server_id', 'date']);

        $status = [];
        foreach ($summaries as $row) {
            $status[$row->server_id][substr((string) $row->date, 0, 10)] = $row->verdict ?? 'pending';
        }
        foreach ($openAlerts as $row) {
            $status[$row->server_id][substr((string) $row->date, 0, 10)] = 'alert';
        }

        return $status;
    }
}
