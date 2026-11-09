<?php

namespace Tests\Feature;

use App\AlertSource;
use App\Jobs\ReviewSummary;
use App\Jobs\SendAlertEmail;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\Review;
use App\Models\RuleHit;
use App\Models\Server;
use App\Monitoring\HardRules;
use App\Monitoring\Review\InvalidReview;
use App\Monitoring\Review\Reviewer;
use App\Monitoring\Review\ReviewResult;
use App\Verdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeReviewer;
use Tests\TestCase;

/**
 * From a summary arriving to an alert email: hard rules on ingest, the queued
 * review (run synchronously in tests), and one email per batch of new alerts.
 */
class AlertPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 00:20:00');
        Mail::fake();

        $this->server = Server::factory()->create(['name' => 'jfm', 'known_failed_units' => null]);
        $this->token = $this->server->issueToken();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name, string $date = '2026-11-08'): array
    {
        return ['date' => $date] + HardRulesTest::fixture($name);
    }

    private function send(array $payload): void
    {
        $this->withToken($this->token)->postJson(route('api.summaries.store'), $payload)->assertStatus(202);
    }

    public function test_hard_rule_hits_raise_alerts_and_send_one_email(): void
    {
        $this->send($this->fixture('day-with-changes'));

        $this->assertSame(8, RuleHit::count());
        $this->assertSame(8, Alert::where('source', AlertSource::Rule)->count());
        $this->assertSame(0, Alert::whereNull('emailed_at')->count());

        Mail::assertSent(AlertMail::class, 1);
        Mail::assertSent(AlertMail::class, function (AlertMail $mail): bool {
            return $mail->hasTo('alerts@example.test')
                && $mail->alerts->count() === 8
                && str_starts_with($mail->envelope()->subject, '[LogWatch] ALERT — jfm — ')
                && str_ends_with($mail->envelope()->subject, '(+7 more)');
        });
    }

    public function test_the_alert_email_shows_the_evidence_and_links_to_the_day(): void
    {
        $this->send($this->fixture('day-with-changes'));

        Mail::assertSent(AlertMail::class, function (AlertMail $mail): bool {
            $mail->assertSeeInText('New listening port: tcp/4444 all nc');
            $mail->assertSeeInText('Raised by: a hard rule (new listening port)');
            $mail->assertSeeInText('intruder@laptop');
            $mail->assertSeeInHtml(route('server-days.show', ['jfm', '2026-11-08']), false);

            return true;
        });
    }

    public function test_sending_the_same_day_again_does_not_raise_or_email_the_same_alerts_twice(): void
    {
        $this->send($this->fixture('day-with-changes'));
        $this->send($this->fixture('day-with-changes'));

        $this->assertSame(8, RuleHit::count());
        $this->assertSame(8, Alert::count());
        Mail::assertSent(AlertMail::class, 1);
    }

    public function test_in_silent_mode_alerts_are_recorded_but_not_emailed(): void
    {
        config(['logwatch.alerts.silent' => true]);

        $this->send($this->fixture('day-with-changes'));

        $this->assertSame(8, Alert::count());
        $this->assertSame(8, Alert::whereNull('emailed_at')->count());
        Mail::assertNothingSent();
    }

    public function test_acknowledged_alerts_are_not_emailed(): void
    {
        config(['logwatch.alerts.silent' => true]);
        $this->send($this->fixture('day-with-changes'));
        Alert::query()->update(['acknowledged_at' => now()]);
        config(['logwatch.alerts.silent' => false]);

        SendAlertEmail::dispatch($this->server->id, '2026-11-08');

        Mail::assertNothingSent();
    }

    public function test_an_ordinary_day_is_reviewed_without_alerts(): void
    {
        $this->send(['schema' => 1, 'collector_version' => '0.1.0', 'date' => '2026-11-08', 'auth' => ['ssh' => ['failed' => ['total' => 900]]]]);

        $review = Review::sole();
        $this->assertSame(Verdict::Ok, $review->verdict);
        $this->assertSame('An ordinary day.', $review->summary);
        $this->assertSame(6400, $review->input_tokens + $review->output_tokens);
        $this->assertSame(0, Alert::count());
        Mail::assertNothingSent();
    }

    public function test_an_alert_verdict_raises_an_alert_and_emails_it(): void
    {
        $this->app->instance(Reviewer::class, new FakeReviewer(new ReviewResult(
            verdict: Verdict::Alert,
            confidence: 0.8,
            summary: 'A probe succeeded.',
            findings: [
                ['title' => 'Scanner volume up', 'severity' => 'low', 'evidence' => 'Twice the usual.'],
                ['title' => '.env returned 200', 'severity' => 'high', 'evidence' => 'A scanner got a 200 for /.env on example.com.'],
            ],
            model: 'claude-sonnet-5-5',
        )));
        $payload = ['schema' => 1, 'collector_version' => '0.1.0', 'date' => '2026-11-08'];

        $this->send($payload);

        $alert = Alert::sole();
        $this->assertSame(AlertSource::Review, $alert->source);
        $this->assertSame('.env returned 200', $alert->title);
        $this->assertNotNull($alert->emailed_at);
        Mail::assertSent(AlertMail::class, function (AlertMail $mail): bool {
            $mail->assertSeeInText('Raised by: the model review (claude-sonnet-5-5)');
            $mail->assertSeeInText('[high] .env returned 200: A scanner got a 200 for /.env on example.com.');

            return $mail->envelope()->subject === '[LogWatch] ALERT — jfm — .env returned 200';
        });
    }

    public function test_a_watch_verdict_is_stored_but_not_alerted(): void
    {
        $this->app->instance(Reviewer::class, new FakeReviewer(new ReviewResult(Verdict::Watch, 0.6, 'TLS expires soon.', [], 'claude-sonnet-5-5')));

        $this->send(['schema' => 1, 'collector_version' => '0.1.0', 'date' => '2026-11-08']);

        $this->assertSame(Verdict::Watch, Review::sole()->verdict);
        $this->assertSame(0, Alert::count());
        Mail::assertNothingSent();
    }

    public function test_a_failed_review_is_recorded_and_does_not_block_the_hard_rules(): void
    {
        Queue::fake([ReviewSummary::class]);
        $this->app->instance(Reviewer::class, new FakeReviewer(new InvalidReview('The review stopped early (max_tokens).')));

        $this->send($this->fixture('day-with-changes'));

        // What a worker does on the last attempt: the job throws, then failed() runs.
        $job = Queue::pushed(ReviewSummary::class)->sole();
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('The review should have thrown.');
        } catch (InvalidReview $e) {
            $job->failed($e);
        }

        $review = Review::sole();
        $this->assertNull($review->verdict);
        $this->assertSame('The review stopped early (max_tokens).', $review->error);
        $this->assertSame(8, Alert::count());
        Mail::assertSent(AlertMail::class, 1);
    }

    public function test_a_hard_rule_alert_stays_even_if_the_model_says_ok(): void
    {
        $this->send($this->fixture('day-with-changes'));

        $this->assertSame(Verdict::Ok, Review::sole()->verdict);
        $this->assertSame(8, Alert::where('source', AlertSource::Rule)->count());
        $this->assertNotNull(RuleHit::where('rule', HardRules::NEW_PORT)->first());
    }
}
