<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\RuleHit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertAcknowledgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_alert_can_be_acknowledged_from_the_day_page(): void
    {
        $user = User::factory()->create();
        $hit = RuleHit::factory()->create(['evidence' => ['added' => ['tcp/4444 all nc']]]);
        $alert = Alert::factory()->create(['server_id' => $hit->server_id, 'date' => $hit->date, 'rule_hit_id' => $hit->id, 'title' => $hit->title]);
        $dayUrl = route('server-days.show', [$hit->server, $hit->date->toDateString()]);

        $this->actingAs($user)->get($dayUrl)
            ->assertOk()
            ->assertSee('Acknowledge')
            ->assertSee('Added: tcp/4444 all nc');

        $this->actingAs($user)->post(route('alerts.acknowledge', $alert))
            ->assertRedirect($dayUrl);

        $this->assertNotNull($alert->fresh()->acknowledged_at);
        $this->actingAs($user)->get($dayUrl)->assertDontSee('>Acknowledge<', false)->assertSee('acknowledged');
    }

    public function test_guests_cannot_acknowledge_alerts(): void
    {
        $alert = Alert::factory()->create();

        $this->post(route('alerts.acknowledge', $alert))->assertRedirect(route('login'));
        $this->assertNull($alert->fresh()->acknowledged_at);
    }
}
