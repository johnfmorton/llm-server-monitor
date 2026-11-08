<?php

namespace Database\Factories;

use App\Models\DailySummary;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailySummary>
 */
class DailySummaryFactory extends Factory
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
            'payload' => [
                'schema' => 1,
                'collector_version' => '0.1.0',
                'server' => 'test',
                'auth' => ['ssh' => ['logins' => [], 'logins_from_outside_tailnet' => 0, 'failed' => ['total' => 12]]],
                'system' => ['disk' => [['mount' => '/', 'used_pct' => 40]]],
            ],
            'collector_version' => '0.1.0',
            'size_bytes' => 512,
            'received_at' => now(),
        ];
    }
}
