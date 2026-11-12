<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pings the external check-in URL. It runs on the queue rather than in the
 * scheduler so that a ping proves both the scheduler and the queue worker are
 * alive: if either stops, the pings stop and the check-in service emails.
 *
 * A failed ping is logged, not retried; the next one is five minutes away.
 */
class PingHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        $url = config('logwatch.heartbeat.url');
        if (blank($url)) {
            return;
        }

        try {
            $response = Http::timeout(10)->retry(2, 2000, throw: false)->get($url);
        } catch (ConnectionException $e) {
            Log::warning('Heartbeat ping failed: '.$e->getMessage());

            return;
        }

        if ($response->failed()) {
            Log::warning("Heartbeat ping failed with HTTP {$response->status()}.");
        }
    }
}
