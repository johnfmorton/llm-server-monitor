<?php

namespace App\Monitoring;

/**
 * The countable numbers in a collector summary, by stable key. Used for the
 * day page's key numbers and for comparing a day with the days before it.
 */
class SummaryMetrics
{
    /** @var array<string, string> key => label, in display order */
    public const LABELS = [
        'ssh_logins' => 'SSH logins',
        'ssh_logins_outside_tailnet' => 'Logins from outside the tailnet',
        'ssh_failed' => 'Failed SSH attempts',
        'ssh_failed_sources' => 'Distinct failed-SSH sources',
        'fail2ban_bans' => 'fail2ban bans',
        'firewall_blocks' => 'Firewall blocks',
        'scanner_requests' => 'Scanner requests (shared log)',
        'nginx_errors' => 'Nginx errors',
        'app_errors' => 'App errors',
        'new_app_errors' => 'App errors never seen before',
        'journal_errors' => 'Journal errors and worse',
        'failed_units' => 'Failed services now',
        'oom_kills' => 'OOM kills',
        'state_changes' => 'Access and schedule changes',
        'webroot_changes' => 'New or changed webroot files',
        'disk_used_pct' => 'Fullest disk (%)',
        'security_updates' => 'Security updates pending',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    public static function extract(array $payload): array
    {
        $sum = fn (mixed $values): int => is_array($values) ? (int) array_sum(array_map('intval', $values)) : 0;
        $count = fn (string $path): int => count((array) data_get($payload, $path, []));

        $appErrors = 0;
        foreach ((array) data_get($payload, 'apps.sites', []) as $site) {
            $appErrors += $sum(array_intersect_key((array) ($site['by_level'] ?? []), array_flip(['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])));
        }

        $webrootChanges = 0;
        foreach ((array) data_get($payload, 'state.webroots.findings', []) as $site) {
            $webrootChanges += count((array) ($site['new_executable'] ?? [])) + count((array) ($site['changed_executable'] ?? []));
        }

        $stateChanges = 0;
        foreach (['state.ports.added', 'state.firewall_rules.added', 'state.firewall_rules.removed',
            'state.access_control.keys_added', 'state.access_control.keys_removed', 'state.access_control.users_added',
            'state.access_control.groups_added', 'state.access_control.sudoers_added',
            'state.scheduled.cron_added', 'state.scheduled.cron_changed', 'state.scheduled.timers_added'] as $path) {
            $stateChanges += $count($path);
        }

        $journal = (array) data_get($payload, 'system.journal.by_priority', []);
        unset($journal['warning']);

        return [
            'ssh_logins' => $sum(array_column((array) data_get($payload, 'auth.ssh.logins', []), 'count')),
            'ssh_logins_outside_tailnet' => (int) data_get($payload, 'auth.ssh.logins_from_outside_tailnet', 0),
            'ssh_failed' => (int) data_get($payload, 'auth.ssh.failed.total', 0),
            'ssh_failed_sources' => (int) data_get($payload, 'auth.ssh.failed.unique_sources', 0),
            'fail2ban_bans' => $sum(data_get($payload, 'fail2ban.bans_by_jail', [])),
            'firewall_blocks' => (int) data_get($payload, 'firewall.blocked_total', 0),
            'scanner_requests' => (int) data_get($payload, 'nginx.shared_access_log.requests', 0),
            'nginx_errors' => $sum(array_column((array) data_get($payload, 'nginx.site_errors', []), 'total')),
            'app_errors' => $appErrors,
            'new_app_errors' => (int) data_get($payload, 'apps.new_errors.new_total', 0),
            'journal_errors' => $sum($journal),
            'failed_units' => $count('system.units.failed_now'),
            'oom_kills' => $sum(data_get($payload, 'system.journal.oom_kills', [])),
            'state_changes' => $stateChanges,
            'webroot_changes' => $webrootChanges,
            'disk_used_pct' => (int) max([0, ...array_map('intval', array_column((array) data_get($payload, 'system.disk', []), 'used_pct'))]),
            'security_updates' => (int) data_get($payload, 'state.patching.security_updates', 0),
        ];
    }
}
