<?php

namespace App\Models;

use App\Monitoring\SummaryMetrics;
use Database\Factories\DailySummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['server_id', 'date', 'payload', 'collector_version', 'size_bytes', 'received_at'])]
class DailySummary extends Model
{
    /** @use HasFactory<DailySummaryFactory> */
    use HasFactory, Prunable;

    public const RETENTION_DAYS = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'payload' => 'array',
            'received_at' => 'datetime',
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
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function latestReview(): HasOne
    {
        return $this->hasOne(Review::class)->latestOfMany();
    }

    /**
     * Summaries older than the retention window. Their reviews and alerts are kept longer.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('date', '<', today()->subDays(self::RETENTION_DAYS));
    }

    /**
     * The handful of numbers worth seeing at a glance, pulled from the collector's payload.
     *
     * @return list<array{label: string, value: int|string, attention: bool}>
     */
    public function highlights(): array
    {
        $metrics = SummaryMetrics::extract($this->payload);
        $attentionWhenAboveZero = ['ssh_logins_outside_tailnet', 'app_errors', 'new_app_errors', 'failed_units', 'oom_kills', 'state_changes', 'webroot_changes'];

        $highlights = [];
        foreach (SummaryMetrics::LABELS as $key => $label) {
            $highlights[] = [
                'label' => $label,
                'value' => $metrics[$key],
                'attention' => $key === 'disk_used_pct' ? $metrics[$key] >= 80 : (in_array($key, $attentionWhenAboveZero, true) && $metrics[$key] > 0),
            ];
        }
        $highlights[] = ['label' => 'Reboot required', 'value' => data_get($this->payload, 'state.patching.reboot_required') ? 'yes' : 'no', 'attention' => false];

        return $highlights;
    }
}
