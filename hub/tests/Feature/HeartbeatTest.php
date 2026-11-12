<?php

namespace Tests\Feature;

use App\Jobs\PingHeartbeat;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_it_pings_the_check_in_url(): void
    {
        config(['logwatch.heartbeat.url' => 'https://hc-ping.example.test/abc']);
        Http::fake(['hc-ping.example.test/*' => Http::response('OK')]);

        PingHeartbeat::dispatchSync();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hc-ping.example.test/abc' && $request->method() === 'GET');
    }

    public function test_nothing_is_sent_without_a_url(): void
    {
        config(['logwatch.heartbeat.url' => null]);
        Http::fake();

        PingHeartbeat::dispatchSync();

        Http::assertNothingSent();
    }

    public function test_a_failed_ping_is_logged_without_failing_the_job(): void
    {
        config(['logwatch.heartbeat.url' => 'https://hc-ping.example.test/abc']);
        Http::fake(['*' => Http::response('down', 503)]);
        Log::spy();
        Sleep::fake();

        PingHeartbeat::dispatchSync();

        Http::assertSentCount(2);
        Log::shouldHaveReceived('warning')->once()->with('Heartbeat ping failed with HTTP 503.');
    }
}
