<?php

namespace App\Models;

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
        $p = $this->payload;
        $sum = fn (array $values): int => (int) array_sum(array_map('intval', $values));

        $appErrors = 0;
        foreach (data_get($p, 'apps.sites', []) as $site) {
            $levels = $site['by_level'] ?? [];
            $appErrors += $sum(array_intersect_key($levels, array_flip(['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])));
        }
        $nginxErrors = $sum(array_column(data_get($p, 'nginx.site_errors', []), 'total'));
        $journal = data_get($p, 'system.journal.by_priority', []);
        $webrootChanges = 0;
        foreach (data_get($p, 'state.webroots.findings', []) as $site) {
            $webrootChanges += count($site['new_executable'] ?? []) + count($site['changed_executable'] ?? []);
        }
        $stateChanges = count(data_get($p, 'state.ports.added', []))
            + count(data_get($p, 'state.firewall_rules.added', []))
            + count(data_get($p, 'state.firewall_rules.removed', []))
            + count(data_get($p, 'state.access_control.keys_added', []))
            + count(data_get($p, 'state.access_control.users_added', []))
            + count(data_get($p, 'state.access_control.sudoers_added', []))
            + count(data_get($p, 'state.scheduled.cron_added', []))
            + count(data_get($p, 'state.scheduled.cron_changed', []))
            + count(data_get($p, 'state.scheduled.timers_added', []));
        $disk = max([0, ...array_column(data_get($p, 'system.disk', []), 'used_pct')]);
        $outside = (int) data_get($p, 'auth.ssh.logins_from_outside_tailnet', 0);

        return [
            ['label' => 'SSH logins', 'value' => count(data_get($p, 'auth.ssh.logins', [])), 'attention' => false],
            ['label' => 'Logins from outside the tailnet', 'value' => $outside, 'attention' => $outside > 0],
            ['label' => 'Failed SSH attempts', 'value' => (int) data_get($p, 'auth.ssh.failed.total', 0), 'attention' => false],
            ['label' => 'fail2ban bans', 'value' => $sum(data_get($p, 'fail2ban.bans_by_jail', [])), 'attention' => false],
            ['label' => 'Firewall blocks', 'value' => (int) data_get($p, 'firewall.blocked_total', 0), 'attention' => false],
            ['label' => 'Scanner requests (shared log)', 'value' => (int) data_get($p, 'nginx.shared_access_log.requests', 0), 'attention' => false],
            ['label' => 'Nginx errors', 'value' => $nginxErrors, 'attention' => false],
            ['label' => 'App errors', 'value' => $appErrors, 'attention' => $appErrors > 0],
            ['label' => 'Journal errors and worse', 'value' => $sum(array_diff_key($journal, ['warning' => 0])), 'attention' => false],
            ['label' => 'Failed services now', 'value' => count(data_get($p, 'system.units.failed_now', [])), 'attention' => count(data_get($p, 'system.units.failed_now', [])) > 0],
            ['label' => 'OOM kills', 'value' => $sum(data_get($p, 'system.journal.oom_kills', [])), 'attention' => $sum(data_get($p, 'system.journal.oom_kills', [])) > 0],
            ['label' => 'Access and schedule changes', 'value' => $stateChanges, 'attention' => $stateChanges > 0],
            ['label' => 'New or changed webroot files', 'value' => $webrootChanges, 'attention' => $webrootChanges > 0],
            ['label' => 'Fullest disk', 'value' => $disk.'%', 'attention' => $disk >= 80],
            ['label' => 'Security updates pending', 'value' => (int) data_get($p, 'state.patching.security_updates', 0), 'attention' => false],
            ['label' => 'Reboot required', 'value' => data_get($p, 'state.patching.reboot_required') ? 'yes' : 'no', 'attention' => false],
        ];
    }
}
