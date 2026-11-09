<?php

namespace App\Jobs;

use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Emails every alert for one server-day that hasn't been emailed yet, in a
 * single message. The hard rules and the review each dispatch this, so a day
 * with several problems sends at most one email per source, not one per alert.
 *
 * If sending fails, nothing is marked as emailed and the job retries.
 */
class SendAlertEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $serverId, public string $date) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("{$this->serverId}:{$this->date}"))->releaseAfter(30)];
    }

    public function handle(): void
    {
        $recipient = config('logwatch.alerts.to');
        if (config('logwatch.alerts.silent') || blank($recipient)) {
            return;
        }

        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }

        DB::transaction(function () use ($server, $recipient): void {
            $alerts = Alert::query()
                ->where('server_id', $server->id)
                ->where('date', $this->date)
                ->whereNull('emailed_at')
                ->whereNull('acknowledged_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->with(['ruleHit', 'review'])
                ->get();

            if ($alerts->isEmpty()) {
                return;
            }

            Mail::to($recipient)->send(new AlertMail($server, Carbon::parse($this->date), $alerts));

            Alert::whereKey($alerts->modelKeys())->update(['emailed_at' => now()]);
        });
    }
}
