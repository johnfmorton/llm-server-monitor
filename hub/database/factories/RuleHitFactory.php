<?php

namespace Database\Factories;

use App\Models\RuleHit;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleHit>
 */
class RuleHitFactory extends Factory
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
            'daily_summary_id' => null,
            'rule' => 'new_listening_port',
            'evidence' => ['added' => ['tcp/4444 all nc']],
        ];
    }
}
