<?php

namespace App\Models;

use Database\Factories\RuleHitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['server_id', 'date', 'daily_summary_id', 'rule', 'evidence'])]
class RuleHit extends Model
{
    /** @use HasFactory<RuleHitFactory> */
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
            'evidence' => 'array',
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
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('date', '<', today()->subDays(Review::RETENTION_DAYS));
    }
}
