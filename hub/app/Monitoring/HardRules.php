<?php

namespace App\Monitoring;

use App\Models\Server;

/**
 * Deterministic checks run on every summary as it arrives. A hit is always an
 * alert, whatever the model later says, so text an attacker planted in a log
 * can't talk the review out of reporting it.
 *
 * Each rule returns at most one hit per server and day; its evidence lists
 * everything that matched.
 */
class HardRules
{
    public const SSH_LOGIN_OUTSIDE_TAILNET = 'ssh_login_outside_tailnet';

    public const NEW_ACCOUNT = 'new_account';

    public const OOM_KILL = 'oom_kill';

    public const DISK_FULL = 'disk_full';

    public const FAILED_UNIT = 'failed_unit';

    public const NEW_PORT = 'new_listening_port';

    public const ACCESS_CHANGE = 'access_change';

    public const SCHEDULE_CHANGE = 'schedule_change';

    public const FIREWALL_CHANGE = 'firewall_change';

    public const WEBROOT_CODE = 'webroot_code_change';

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{rule: string, title: string, evidence: array<string, mixed>}>
     */
    public function evaluate(Server $server, array $payload): array
    {
        return array_values(array_filter([
            $this->sshLoginOutsideTailnet($server, $payload),
            $this->newAccount($payload),
            $this->oomKill($payload),
            $this->diskFull($payload),
            $this->failedUnit($server, $payload),
            $this->newPort($payload),
            $this->accessChange($payload),
            $this->scheduleChange($payload),
            $this->firewallChange($payload),
            $this->webrootCode($payload),
        ]));
    }

    /**
     * A login from outside the tailnet with a key that isn't allowlisted. Password
     * logins from outside the tailnet are never allowlisted.
     *
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function sshLoginOutsideTailnet(Server $server, array $payload): ?array
    {
        $allowlist = [...config('logwatch.rules.ssh_allowlist', []), ...($server->ssh_allowlist ?? [])];

        $logins = [];
        foreach ((array) data_get($payload, 'auth.ssh.logins', []) as $login) {
            if (($login['source']['scope'] ?? null) === 'tailnet') {
                continue;
            }
            $isKey = ($login['method'] ?? '') === 'publickey';
            $allowed = $isKey && (in_array($login['key'] ?? null, $allowlist, true) || in_array($login['key_comment'] ?? null, $allowlist, true));
            if (! $allowed) {
                $logins[] = $login;
            }
        }
        if ($logins === []) {
            return null;
        }

        $first = $logins[0];
        $who = ($first['user'] ?? '?').' via '.($first['method'] ?? '?').' from a '.($first['source']['scope'] ?? 'unknown').' address';

        return $this->hit(self::SSH_LOGIN_OUTSIDE_TAILNET, 'SSH login from outside the tailnet: '.$who, ['logins' => $logins], count($logins));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function newAccount(array $payload): ?array
    {
        $evidence = array_filter([
            'users_added' => $this->union($payload, ['state.access_control.users_added', 'auth.accounts.users_added']),
            'groups_added' => $this->union($payload, ['state.access_control.groups_added', 'auth.accounts.groups_added']),
            'sudoers_added' => $this->union($payload, ['state.access_control.sudoers_added']),
            'admin_group_membership' => array_values(array_filter(
                $this->union($payload, ['auth.accounts.group_membership']),
                fn (string $change): bool => (bool) preg_match('/\+(sudo|admin|wheel)$/', $change),
            )),
        ]);
        if ($evidence === []) {
            return null;
        }

        $names = array_merge(...array_values($evidence));

        return $this->hit(self::NEW_ACCOUNT, 'New user, group or sudoer: '.$this->list($names), $evidence, count($names));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function oomKill(array $payload): ?array
    {
        $kills = array_filter((array) data_get($payload, 'system.journal.oom_kills', []));
        if ($kills === []) {
            return null;
        }

        return $this->hit(self::OOM_KILL, 'Out-of-memory kill: '.$this->list(array_keys($kills)), ['oom_kills' => $kills]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function diskFull(array $payload): ?array
    {
        $limit = (int) config('logwatch.rules.disk_used_pct', 90);
        $full = array_values(array_filter(
            (array) data_get($payload, 'system.disk', []),
            fn ($disk): bool => (int) ($disk['used_pct'] ?? 0) > $limit || (int) ($disk['inodes_used_pct'] ?? 0) > $limit,
        ));
        if ($full === []) {
            return null;
        }

        $first = $full[0];

        return $this->hit(self::DISK_FULL, sprintf('Disk over %d%%: %s at %d%%', $limit, $first['mount'] ?? '?', max((int) ($first['used_pct'] ?? 0), (int) ($first['inodes_used_pct'] ?? 0))), ['disks' => $full], count($full));
    }

    /**
     * Units still failed at collection time, minus the ones known to be broken on this server.
     *
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function failedUnit(Server $server, array $payload): ?array
    {
        $failed = array_values(array_diff((array) data_get($payload, 'system.units.failed_now', []), $server->known_failed_units ?? []));
        if ($failed === []) {
            return null;
        }

        return $this->hit(self::FAILED_UNIT, 'Service failed and still down: '.$this->list($failed), [
            'failed_now' => $failed,
            'failures' => array_intersect_key((array) data_get($payload, 'system.units.failures', []), array_flip($failed)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function newPort(array $payload): ?array
    {
        $added = (array) data_get($payload, 'state.ports.added', []);
        if ($added === []) {
            return null;
        }

        return $this->hit(self::NEW_PORT, 'New listening port: '.$this->list($added), ['added' => $added]);
    }

    /**
     * SSH keys added or removed, and changes to sudoers, authorized_keys or sshd_config.
     *
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function accessChange(array $payload): ?array
    {
        $watched = fn (string $path): bool => (bool) preg_match('#(^/etc/sudoers|/authorized_keys2?$|^/etc/ssh/sshd_config)#', $path);

        $evidence = array_filter([
            'keys_added' => (array) data_get($payload, 'state.access_control.keys_added', []),
            'keys_removed' => (array) data_get($payload, 'state.access_control.keys_removed', []),
            'changed_files' => array_values(array_filter((array) data_get($payload, 'state.access_control.changed_files', []), $watched)),
            'removed_files' => array_values(array_filter((array) data_get($payload, 'state.access_control.removed_files', []), $watched)),
        ]);
        if ($evidence === []) {
            return null;
        }

        if (isset($evidence['keys_added'])) {
            $comments = array_map(fn ($key): string => (string) ($key['comment'] ?? 'no comment'), $evidence['keys_added']);
            $title = 'SSH key added: '.$this->list($comments);
        } else {
            $title = 'Access files changed: '.$this->list([...($evidence['changed_files'] ?? []), ...($evidence['removed_files'] ?? []), ...array_map(fn ($k): string => 'key removed', $evidence['keys_removed'] ?? [])]);
        }

        return $this->hit(self::ACCESS_CHANGE, $title, $evidence);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function scheduleChange(array $payload): ?array
    {
        $evidence = array_filter([
            'cron_added' => (array) data_get($payload, 'state.scheduled.cron_added', []),
            'cron_changed' => (array) data_get($payload, 'state.scheduled.cron_changed', []),
            'cron_removed' => (array) data_get($payload, 'state.scheduled.cron_removed', []),
            'timers_added' => (array) data_get($payload, 'state.scheduled.timers_added', []),
            'timers_removed' => (array) data_get($payload, 'state.scheduled.timers_removed', []),
        ]);
        if ($evidence === []) {
            return null;
        }

        return $this->hit(self::SCHEDULE_CHANGE, 'Cron or timer changed: '.$this->list(array_merge(...array_values($evidence))), $evidence);
    }

    /**
     * Rule changes, or UFW no longer active. MySQL, Redis and Memcached can rely on UFW alone.
     *
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function firewallChange(array $payload): ?array
    {
        $firewall = (array) data_get($payload, 'state.firewall_rules', []);
        if ($firewall === []) {
            return null;
        }

        $status = $firewall['status'] ?? null;
        $inactive = $status !== null && $status !== 'active';
        $evidence = array_filter([
            'status' => ($inactive || ! empty($firewall['status_changed'])) ? $status : null,
            'added' => (array) ($firewall['added'] ?? []),
            'removed' => (array) ($firewall['removed'] ?? []),
        ]);
        if ($evidence === []) {
            return null;
        }

        $title = $inactive
            ? 'Firewall is '.($status ?: 'off')
            : 'Firewall rules changed: '.$this->list([...array_map(fn ($r): string => '+'.$r, $evidence['added'] ?? []), ...array_map(fn ($r): string => '−'.$r, $evidence['removed'] ?? [])]);

        return $this->hit(self::FIREWALL_CHANGE, $title, $evidence);
    }

    /**
     * New or changed PHP (and other executable) files in a webroot, HEAD moving
     * inside a release, or a repository the collector refused to trust.
     *
     * @param  array<string, mixed>  $payload
     * @return array{rule: string, title: string, evidence: array<string, mixed>}|null
     */
    private function webrootCode(array $payload): ?array
    {
        $sites = [];
        foreach ((array) data_get($payload, 'state.webroots.findings', []) as $site => $finding) {
            $relevant = array_filter(array_intersect_key((array) $finding, array_flip(['new_executable', 'changed_executable', 'head_moved_within_release', 'git_skipped'])));
            if ($relevant !== []) {
                $sites[$site] = $relevant;
            }
        }
        if ($sites === []) {
            return null;
        }

        $files = [];
        foreach ($sites as $site => $finding) {
            foreach ([...($finding['new_executable'] ?? []), ...($finding['changed_executable'] ?? [])] as $file) {
                $files[] = "$site/$file";
            }
        }

        $title = $files !== []
            ? 'Code changed in a webroot outside a deploy: '.$this->list($files)
            : 'Webroot repository changed unexpectedly: '.$this->list(array_keys($sites));

        return $this->hit(self::WEBROOT_CODE, $title, ['sites' => $sites]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{rule: string, title: string, evidence: array<string, mixed>}
     */
    private function hit(string $rule, string $title, array $evidence, ?int $count = null): array
    {
        if ($count !== null) {
            $evidence['count'] = $count;
        }

        return ['rule' => $rule, 'title' => mb_strimwidth($title, 0, 180, '…'), 'evidence' => $evidence];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function union(array $payload, array $paths): array
    {
        $values = [];
        foreach ($paths as $path) {
            foreach ((array) data_get($payload, $path, []) as $value) {
                $values[] = is_array($value) ? (string) ($value['name'] ?? $value['user'] ?? json_encode($value)) : (string) $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param  list<string>  $items
     */
    private function list(array $items): string
    {
        $items = array_values(array_unique(array_map('strval', $items)));
        $shown = array_slice($items, 0, 3);
        $more = count($items) - count($shown);

        return implode(', ', $shown).($more > 0 ? " and $more more" : '');
    }
}
