<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Monitoring\DayStatus;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Days shown in the grid, ending yesterday (the collector reports the previous UTC day). */
    public const DAYS = 14;

    /**
     * Servers by day, coloured by the latest verdict or an open alert.
     */
    public function index(): View
    {
        /** @var list<Carbon> $days oldest first */
        $days = [];
        for ($ago = self::DAYS; $ago >= 1; $ago--) {
            $days[] = today()->subDays($ago);
        }
        $servers = Server::orderBy('name')->get(['id', 'name', 'is_client', 'last_report_at']);

        return view('dashboard', [
            'days' => $days,
            'servers' => $servers,
            'status' => DayStatus::between($days[0], end($days)),
        ]);
    }
}
