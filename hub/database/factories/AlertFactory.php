<?php

namespace Database\Factories;

use App\AlertSource;
use App\Models\Alert;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'date' => today()->subDay(),
            'source' => AlertSource::Rule,
            'title' => 'New listening port',
            'review_id' => null,
            'rule_hit_id' => null,
            'emailed_at' => null,
            'acknowledged_at' => null,
        ];
    }
}
