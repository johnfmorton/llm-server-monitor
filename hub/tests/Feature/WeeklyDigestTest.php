<?php

namespace Tests\Feature;

use App\AlertSource;
use App\Mail\DigestMail;
use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use App\Models\Server;
use App\Models\User;
use App\Verdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A Monday; last week is Mon 2 Nov to Sun 8 Nov.
        Carbon::setTestNow('2026-11-09 09:00:00');
    }

    /**
     * jfm: a quiet week. demo-web: two days missing, one "watch", one open alert.
     */
    private function seedWeek(): void
    {
        $quiet = Server::factory()->create(['name' => 'jfm']);
        foreach (range(2, 8) as $d) {
            Review::factory()->for(DailySummary::factory()->for($quiet)->create(['date' => "2026-11-0{$d}"]))->create();
        }
        // The week before, three days: 36 failed SSH attempts against this week's 84.
        foreach (range(27, 29) as $d) {
            DailySummary::factory()->for($quiet)->create(['date' => "2026-10-{$d}"]);
        }

        $busy = Server::factory()->create(['name' => 'demo-web']);
        foreach ([2, 3, 4, 5, 6] as $d) {
            $summary = DailySummary::factory()->for($busy)->create(['date' => "2026-11-0{$d}"]);
            $verdict = $d === 4 ? Verdict::Watch : Verdict::Ok;
            Review::factory()->for($summary)->verdict($verdict)->create([
                'findings' => $d === 4 ? [['title' => 'Scanner volume doubled', 'severity' => 'low', 'evidence' => '…']] : [],
            ]);
        }
        Alert::factory()->for($busy)->create(['date' => '2026-11-07', 'source' => AlertSource::MissingReport, 'title' => 'No report from demo-web for Sat 7 Nov']);
        Alert::factory()->for($busy)->create(['date' => '2026-11-03', 'title' => 'New listening port', 'acknowledged_at' => now()]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('digest'))->assertRedirect(route('login'));
    }

    public function test_last_week_is_summed_up_per_server(): void
    {
        $this->seedWeek();

        $this->actingAs(User::factory()->create())->get(route('digest'))
            ->assertOk()
            ->assertSee('Week of 2 Nov–8 Nov 2026')
            ->assertSee('1 of 2 servers need a look · 1 open alert')
            ->assertSee('7 of 7 reports · 7 OK · no alerts')
            ->assertSee('5 of 7 reports · 4 OK, 1 watch · 2 alerts, 1 open')
            ->assertSee('Scanner volume doubled')
            ->assertSee('No report from demo-web for Sat 7 Nov')
            ->assertSee('84 <span class="muted">+133%</span>', false)
            ->assertSee('demo-web, 2026-11-08: No report', false)
            ->assertSee('demo-web, 2026-11-07: Alert', false);
    }

    public function test_a_quiet_week_is_all_clear(): void
    {
        $server = Server::factory()->create(['name' => 'jfm']);
        foreach (range(2, 8) as $d) {
            Review::factory()->for(DailySummary::factory()->for($server)->create(['date' => "2026-11-0{$d}"]))->create();
        }

        $this->actingAs(User::factory()->create())->get(route('digest'))->assertSee('All clear');
    }

    public function test_weeks_are_chosen_by_any_day_in_them(): void
    {
        $this->seedWeek();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('digest', ['week' => '2026-11-05']))
            ->assertSee('Week of 2 Nov–8 Nov 2026')
            ->assertSee('Week of 9 Nov →');
        $this->actingAs($user)->get(route('digest', ['week' => '2026-11-09']))
            ->assertSee('Nothing reported yet this week')
            ->assertSee('cell--future')
            ->assertDontSee('Week of 16 Nov');
        $this->actingAs($user)->get(route('digest', ['week' => '2026-11-10']))->assertNotFound();
        $this->actingAs($user)->get(route('digest', ['week' => '2026-02-30']))->assertNotFound();
    }

    public function test_the_command_prints_the_digest_and_emails_it_when_asked(): void
    {
        Mail::fake();
        $this->seedWeek();

        $this->artisan('logwatch:digest')
            ->expectsOutputToContain('1 of 2 servers need a look')
            ->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('logwatch:digest', ['--email' => true])->assertSuccessful();
        Mail::assertSent(DigestMail::class, function (DigestMail $mail): bool {
            $text = $mail->render();

            return $mail->hasTo('alerts@example.test')
                && $mail->envelope()->subject === '[LogWatch] Week of 2 Nov — 1 of 2 servers need a look · 1 open alert'
                && str_contains($text, 'Scanner volume doubled')
                && str_contains($text, route('digest', ['week' => '2026-11-02']));
        });
    }

    public function test_silent_mode_keeps_the_digest_off_email(): void
    {
        Mail::fake();
        config(['logwatch.alerts.silent' => true]);

        $this->artisan('logwatch:digest', ['--email' => true])
            ->expectsOutputToContain('Not emailed')
            ->assertSuccessful();
        Mail::assertNothingSent();
    }
}
