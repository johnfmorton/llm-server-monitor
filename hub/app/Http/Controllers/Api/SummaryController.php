<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSummaryRequest;
use App\Http\Resources\DailySummaryResource;
use App\Models\DailySummary;
use Illuminate\Http\JsonResponse;

class SummaryController extends Controller
{
    /**
     * Store one server's summary for one day. Sending the same day again replaces it.
     */
    public function store(StoreSummaryRequest $request): JsonResponse
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

        return (new DailySummaryResource($summary))
            ->response()
            ->setStatusCode(202);
    }
}
