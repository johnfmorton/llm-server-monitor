<?php

namespace Database\Factories;

use App\Models\DailySummary;
use App\Models\Review;
use App\Verdict;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'daily_summary_id' => DailySummary::factory(),
            'server_id' => fn (array $attributes) => DailySummary::find($attributes['daily_summary_id'])->server_id,
            'date' => fn (array $attributes) => DailySummary::find($attributes['daily_summary_id'])->date,
            'verdict' => Verdict::Ok,
            'confidence' => 0.9,
            'findings' => [],
            'model' => 'claude-sonnet-5-5',
            'input_tokens' => 5000,
            'output_tokens' => 300,
        ];
    }

    public function verdict(Verdict $verdict): static
    {
        return $this->state(fn () => ['verdict' => $verdict]);
    }
}
