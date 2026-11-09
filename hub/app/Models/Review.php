<?php

namespace App\Models;

use App\Verdict;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'date', 'daily_summary_id', 'verdict', 'confidence', 'summary', 'findings', 'error', 'model', 'input_tokens', 'output_tokens'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory, Prunable;

    public const RETENTION_DAYS = 90;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'verdict' => Verdict::class,
            'confidence' => 'decimal:2',
            'findings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<DailySummary, $this>
     */
    public function dailySummary(): BelongsTo
    {
        return $this->belongsTo(DailySummary::class);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('date', '<', today()->subDays(self::RETENTION_DAYS));
    }
}
