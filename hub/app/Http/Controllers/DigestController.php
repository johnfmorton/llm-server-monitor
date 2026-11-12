<?php

namespace App\Http\Controllers;

use App\Monitoring\WeeklyDigest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DigestController extends Controller
{
    /**
     * One week per page, last complete week by default. `?week=` takes any day of the week wanted.
     */
    public function show(Request $request, WeeklyDigest $digest): View
    {
        $thisWeek = WeeklyDigest::weekStart(today());
        $start = $thisWeek->copy()->subWeek();

        if ($request->filled('week')) {
            $week = (string) $request->query('week');
            try {
                $day = Carbon::createFromFormat('!Y-m-d', $week);
            } catch (\Throwable) {
                abort(404);
            }
            abort_if($day->toDateString() !== $week || $day->gt(today()), 404);
            $start = WeeklyDigest::weekStart($day);
        }

        return view('digest', [
            'digest' => $digest->build($start),
            'previousWeek' => $start->copy()->subWeek(),
            'nextWeek' => $start->lt($thisWeek) ? $start->copy()->addWeek() : null,
        ]);
    }
}
