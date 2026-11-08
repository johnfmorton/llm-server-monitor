<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DailySummary;
use App\Models\Review;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_summaries_go_after_30_days_but_reviews_and_alerts_stay_for_90(): void
    {
        Carbon::setTestNow('2026-11-08 03:30:00');
        $server = Server::factory()->create();
        $old = DailySummary::factory()->for($server)->create(['date' => '2026-10-01']);
        $review = Review::factory()->for($old)->create();
        $recent = DailySummary::factory()->for($server)->create(['date' => '2026-10-20']);
        $ancientAlert = Alert::factory()->for($server)->create(['date' => '2026-08-01']);
        $keptAlert = Alert::factory()->for($server)->create(['date' => '2026-09-01']);

        $this->artisan('model:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertNull($review->fresh()->daily_summary_id);
        $this->assertModelMissing($ancientAlert);
        $this->assertModelExists($keptAlert);
    }
}
