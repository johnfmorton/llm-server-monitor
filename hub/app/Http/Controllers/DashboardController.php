<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Days shown in the grid, ending yesterday (the collector reports the previous UTC day). */
    public const DAYS = 14;

    /**
     * Servers by day, coloured by the latest verdict. Two small queries; the
     * summary payloads themselves are never loaded here.
     */
    public function index(): View
    {
        /** @var list<Carbon> $days oldest first */
        $days = [];
        for ($ago = self::DAYS; $ago >= 1; $ago--) {
            $days[] = today()->subDays($ago);
        }
        $from = $days[0]->toDateString();
        $to = end($days)->toDateString();

        $servers = Server::orderBy('name')->get(['id', 'name', 'is_client', 'last_report_at']);

        $summaries = DailySummary::query()
            ->select(['id', 'server_id', 'date'])
            ->addSelect(['verdict' => Review::select('verdict')
                ->whereColumn('reviews.daily_summary_id', 'daily_summaries.id')
                ->latest('id')
                ->limit(1)])
            ->whereBetween('date', [$from, $to])
            ->toBase()
            ->get();

        $openAlerts = Alert::query()
            ->whereNull('acknowledged_at')
            ->whereBetween('date', [$from, $to])
            ->toBase()
            ->get(['server_id', 'date']);

        $status = [];
        foreach ($summaries as $row) {
            $status[$row->server_id][substr((string) $row->date, 0, 10)] = $row->verdict ?? 'pending';
        }
        foreach ($openAlerts as $row) {
            $status[$row->server_id][substr((string) $row->date, 0, 10)] = 'alert';
        }

        return view('dashboard', [
            'days' => $days,
            'servers' => $servers,
            'status' => $status,
        ]);
    }
}
