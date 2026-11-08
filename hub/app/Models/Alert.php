<?php

namespace App\Models;

use App\AlertSource;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'date', 'source', 'title', 'review_id', 'rule_hit_id', 'emailed_at', 'acknowledged_at'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory, Prunable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'source' => AlertSource::class,
            'emailed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
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
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /**
     * @return BelongsTo<RuleHit, $this>
     */
    public function ruleHit(): BelongsTo
    {
        return $this->belongsTo(RuleHit::class);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('date', '<', today()->subDays(Review::RETENTION_DAYS));
    }
}
