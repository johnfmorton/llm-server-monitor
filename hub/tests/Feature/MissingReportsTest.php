<?php

namespace Tests\Feature;

use App\AlertSource;
use App\Jobs\SendAlertEmail;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MissingReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-09 06:00:00');
    }

    /**
     * @param  list<string>  $dates
     */
    private function serverReporting(string $name, array $dates): Server
    {
        $server = Server::factory()->create(['name' => $name, 'last_report_at' => $dates === [] ? null : Carbon::parse(end($dates))->addDay()->setTime(0, 20)]);
        foreach ($dates as $date) {
            DailySummary::factory()->for($server)->create(['date' => $date]);
        }

        return $server;
    }

    public function test_a_day_without_a_report_raises_one_alert_and_an_email(): void
    {
        Mail::fake();
        $server = $this->serverReporting('jfm', ['2026-11-05', '2026-11-06', '2026-11-07']);

        $this->artisan('logwatch:check-missing')->expectsOutputToContain('1 missing report alerted')->assertSuccessful();

        $alert = Alert::sole();
        $this->assertSame(AlertSource::MissingReport, $alert->source);
        $this->assertSame($server->id, $alert->server_id);
        $this->assertSame('2026-11-08', $alert->date->toDateString());
        $this->assertSame('No report from jfm for Sun 8 Nov', $alert->title);
        $this->assertNotNull($alert->emailed_at);

        Mail::assertSent(AlertMail::class, function (AlertMail $mail): bool {
            $html = $mail->render();

            return str_contains($mail->envelope()->subject, 'No report from jfm')
                && str_contains($html, 'Missing report')
                && str_contains($html, 'send a summary for this day');
        });
    }

    public function test_running_again_does_not_alert_the_same_day_twice(): void
    {
        Queue::fake();
        $this->serverReporting('jfm', ['2026-11-07']);

        $this->artisan('logwatch:check-missing')->assertSuccessful();
        Alert::sole()->update(['acknowledged_at' => now()]);
        $this->artisan('logwatch:check-missing')->expectsOutputToContain('Every server has reported')->assertSuccessful();

        $this->assertSame(1, Alert::count());
        Queue::assertPushed(SendAlertEmail::class, 1);
    }

    public function test_it_looks_back_so_a_gap_during_hub_downtime_is_still_caught(): void
    {
        Queue::fake();
        $this->serverReporting('jfm', ['2026-11-04', '2026-11-05']);

        $this->artisan('logwatch:check-missing')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['2026-11-06', '2026-11-07', '2026-11-08'],
            Alert::pluck('date')->map->toDateString()->all(),
        );
    }

    public function test_servers_that_never_reported_and_days_before_the_first_report_are_skipped(): void
    {
        Queue::fake();
        $this->serverReporting('new-box', []);
        $this->serverReporting('jfm', ['2026-11-08']);

        $this->artisan('logwatch:check-missing')->assertSuccessful();

        $this->assertSame(0, Alert::count());
    }

    public function test_a_missing_day_turns_red_on_the_dashboard_and_shows_on_its_day_page(): void
    {
        Queue::fake();
        $server = $this->serverReporting('jfm', ['2026-11-07']);
        $this->artisan('logwatch:check-missing')->assertSuccessful();

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertSee('jfm, 8 Nov: Alert', false);
        $this->actingAs($user)->get(route('server-days.show', [$server, '2026-11-08']))
            ->assertSee('No report from jfm for Sun 8 Nov')
            ->assertSee('missing report');
    }

    public function test_through_must_be_a_date(): void
    {
        $this->artisan('logwatch:check-missing', ['--through' => 'yesterday'])->assertFailed();
    }

    public function test_it_is_scheduled_at_the_cutoff(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('0   6 * * *  php artisan logwatch:check-missing')->assertSuccessful();
    }
}
