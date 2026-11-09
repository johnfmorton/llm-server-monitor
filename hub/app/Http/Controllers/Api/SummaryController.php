<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSummaryRequest;
use App\Http\Resources\DailySummaryResource;
use App\Jobs\ReviewSummary;
use App\Jobs\SendAlertEmail;
use App\Models\DailySummary;
use App\Monitoring\RuleRecorder;
use Illuminate\Http\JsonResponse;

class SummaryController extends Controller
{
    /**
     * Store one server's summary for one day. Sending the same day again replaces it.
     *
     * The hard rules run here, so a critical event is alerted on even if the
     * model review is slow or fails. The review itself is queued.
     */
    public function store(StoreSummaryRequest $request, RuleRecorder $rules): JsonResponse
    {
        $server = $request->sendingServer();
        $date = $request->validated('date');

        $summary = DailySummary::updateOrCreate(
            ['server_id' => $server->id, 'date' => $date],
            [
                'payload' => $request->json()->all(),
                'collector_version' => $request->validated('collector_version'),
                'size_bytes' => strlen($request->getContent()),
                'received_at' => now(),
            ],
        );

        $server->forceFill(['last_report_at' => now()])->save();
        $summary->setRelation('server', $server);

        if ($rules->record($summary) > 0) {
            SendAlertEmail::dispatch($server->id, $summary->date->toDateString());
        }
        ReviewSummary::dispatch($summary);

        return (new DailySummaryResource($summary))
            ->response()
            ->setStatusCode(202);
    }
}
