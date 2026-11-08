<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use App\Models\Server;
use App\Models\User;
use App\Verdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 09:00:00');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_each_day_is_coloured_by_its_state(): void
    {
        $server = Server::factory()->create(['name' => 'jfm']);
        $reviewed = DailySummary::factory()->for($server)->create(['date' => '2026-11-07']);
        Review::factory()->for($reviewed)->verdict(Verdict::Watch)->create();
        DailySummary::factory()->for($server)->create(['date' => '2026-11-06']);
        Alert::factory()->for($server)->create(['date' => '2026-11-05']);

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('jfm, 7 Nov: Watch')
            ->assertSee('jfm, 6 Nov: Not reviewed yet')
            ->assertSee('jfm, 5 Nov: Alert')
            ->assertSee('jfm, 4 Nov: No report')
            ->assertDontSee('jfm, 8 Nov', false)
            ->assertSee('jfm, 25 Oct: No report');
    }

    public function test_an_unacknowledged_alert_outranks_an_ok_review(): void
    {
        $server = Server::factory()->create(['name' => 'jfm']);
        $summary = DailySummary::factory()->for($server)->create(['date' => '2026-11-07']);
        Review::factory()->for($summary)->create();
        Alert::factory()->for($server)->create(['date' => '2026-11-07']);
        Alert::factory()->for($server)->create(['date' => '2026-11-06', 'acknowledged_at' => now()]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSee('jfm, 7 Nov: Alert')
            ->assertSee('jfm, 6 Nov: No report');
    }

    public function test_the_query_count_does_not_grow_with_servers(): void
    {
        $user = User::factory()->create();
        $countQueries = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get(route('dashboard'))->assertOk();

            return count(DB::getQueryLog());
        };

        Server::factory()->has(DailySummary::factory())->create();
        $withOne = $countQueries();
        Server::factory()->count(5)->has(DailySummary::factory())->create();

        $this->assertSame($withOne, $countQueries());
    }
}
