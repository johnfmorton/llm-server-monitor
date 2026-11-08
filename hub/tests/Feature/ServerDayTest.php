<?php

namespace Tests\Feature;

use App\Models\DailySummary;
use App\Models\Review;
use App\Models\Server;
use App\Models\User;
use App\Verdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServerDayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 09:00:00');
        $this->user = User::factory()->create();
        $this->server = Server::factory()->create(['name' => 'jfm']);
    }

    public function test_a_day_shows_its_key_numbers_and_findings(): void
    {
        $summary = DailySummary::factory()->for($this->server)->create([
            'date' => '2026-11-07',
            'payload' => [
                'schema' => 1,
                'auth' => ['ssh' => ['logins' => [['user' => 'root']], 'logins_from_outside_tailnet' => 1, 'failed' => ['total' => 895]]],
                'system' => ['disk' => [['mount' => '/', 'used_pct' => 23]], 'units' => ['failed_now' => ['logrotate.service']]],
            ],
        ]);
        Review::factory()->for($summary)->verdict(Verdict::Watch)->create([
            'findings' => [['title' => 'logrotate failing every minute', 'severity' => 'medium', 'evidence' => '1440 failures']],
        ]);

        $this->actingAs($this->user)->get(route('server-days.show', [$this->server, '2026-11-07']))
            ->assertOk()
            ->assertSeeInOrder(['Failed SSH attempts', '895'])
            ->assertSeeInOrder(['Logins from outside the tailnet', '1'])
            ->assertSeeInOrder(['Failed services now', '1'])
            ->assertSee('logrotate failing every minute')
            ->assertSee(route('server-days.raw', [$this->server, '2026-11-07']));
    }

    public function test_a_day_without_a_report_says_so(): void
    {
        $this->actingAs($this->user)->get(route('server-days.show', [$this->server, '2026-11-01']))
            ->assertOk()
            ->assertSee('No report for this day.');
    }

    public function test_the_raw_summary_is_served_as_json(): void
    {
        DailySummary::factory()->for($this->server)->create(['date' => '2026-11-07', 'payload' => ['schema' => 1, 'marker' => 'abc']]);

        $this->actingAs($this->user)->get(route('server-days.raw', [$this->server, '2026-11-07']))
            ->assertOk()
            ->assertJsonPath('marker', 'abc');
        $this->actingAs($this->user)->get(route('server-days.raw', [$this->server, '2026-11-06']))
            ->assertNotFound();
    }

    public function test_impossible_dates_and_unknown_servers_are_not_found(): void
    {
        $this->actingAs($this->user)->get('/servers/jfm/days/2026-02-30')->assertNotFound();
        $this->actingAs($this->user)->get('/servers/nope/days/2026-11-07')->assertNotFound();
    }

    public function test_guests_cannot_see_a_day_or_its_raw_summary(): void
    {
        DailySummary::factory()->for($this->server)->create(['date' => '2026-11-07']);

        $this->get(route('server-days.show', [$this->server, '2026-11-07']))->assertRedirect(route('login'));
        $this->get(route('server-days.raw', [$this->server, '2026-11-07']))->assertRedirect(route('login'));
    }
}
