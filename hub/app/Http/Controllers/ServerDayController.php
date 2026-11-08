<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use App\Models\RuleHit;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ServerDayController extends Controller
{
    /**
     * One server's day: key numbers, review findings, alerts. Reviews and
     * alerts outlive the summary (90 days against 30), so they're looked up by
     * server and date, not through the summary.
     */
    public function show(Server $server, string $date): View
    {
        $day = $this->parseDate($date);

        $summary = DailySummary::query()
            ->where('server_id', $server->id)
            ->where('date', $day->toDateString())
            ->first();

        $reviews = Review::query()->where('server_id', $server->id)->where('date', $day->toDateString())->latest('id')->get();
        $ruleHits = RuleHit::query()->where('server_id', $server->id)->where('date', $day->toDateString())->get();
        $alerts = Alert::query()->where('server_id', $server->id)->where('date', $day->toDateString())->latest('id')->get();

        return view('server-days.show', [
            'server' => $server,
            'day' => $day,
            'summary' => $summary,
            'highlights' => $summary?->highlights() ?? [],
            'reviews' => $reviews,
            'ruleHits' => $ruleHits,
            'alerts' => $alerts,
            'previousDay' => $day->copy()->subDay(),
            'nextDay' => $day->lt(today()->subDay()) ? $day->copy()->addDay() : null,
        ]);
    }

    /**
     * The collector's raw summary, on its own URL so the day page stays small.
     */
    public function raw(Server $server, string $date): JsonResponse
    {
        $summary = DailySummary::query()
            ->where('server_id', $server->id)
            ->where('date', $this->parseDate($date)->toDateString())
            ->firstOrFail();

        return response()->json($summary->payload, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function parseDate(string $date): Carbon
    {
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            abort(404);
        }
        abort_if($day->toDateString() !== $date, 404);

        return $day;
    }
}
