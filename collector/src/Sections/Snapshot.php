<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Anon;
use LogWatch\Context;
use LogWatch\SafeFile;
use LogWatch\Tally;

/**
 * Today's view of the server, compared with the previous run's snapshot.
 * These checks show whether something got in, not just that someone tried.
 */
final class Snapshot implements Section
{
    private const WEBSHELL_EXT = '/\.(php\d?|phtml|phar|pht)$/i';
    private const MAX_WALK = 50000;
    private const WALK_SECONDS = 20;

    /** Data to persist for tomorrow's diff (written by the Collector). */
    public array $data = [];

    public function name(): string
    {
        return 'state';
    }

    public function collect(Context $ctx, array $sites): array
    {
        $prev = $ctx->state->previousSnapshot();
        $out = [
            'baseline' => $prev === null,
            'previous_date' => $prev['date'] ?? null,
        ];
        $this->data['date'] = $ctx->date;

        $out['ports'] = $this->ports($ctx, $prev['ports'] ?? null);
        $out['firewall_rules'] = $this->firewallRules($ctx, $prev['ufw'] ?? null);
        $out['access_control'] = $this->accessControl($ctx, $prev['access'] ?? null);
        $out['scheduled'] = $this->scheduled($ctx, $prev['scheduled'] ?? null);
        $out['webroots'] = $this->webroots($ctx, $sites, $prev['webroots'] ?? []);
        $out['processes'] = $this->processes($ctx, $prev['cpu'] ?? null);
        $out['patching'] = $this->patching($ctx);
        $out['tls'] = $this->tls($ctx);
        return $out;
    }

    // ---- listening ports -------------------------------------------------

    private function ports(Context $ctx, ?array $prev): array
    {
        $r = $ctx->cmd->run('ss', ['ss', '-H', '-tulnp']);
        if ($r === null) {
            return ['available' => false];
        }
        $now = [];
        foreach (preg_split('/\R/', trim($r['out'])) as $l) {
            $c = preg_split('/\s+/', trim($l), 7);
            if (count($c) < 5) {
                continue;
            }
            $local = $c[4];
            $pos = strrpos($local, ':');
            if ($pos === false) {
                continue;
            }
            $addr = trim(preg_replace('/%.*$/', '', substr($local, 0, $pos)), '[]');
            $port = substr($local, $pos + 1);
            $proc = preg_match('/\(\("([^"]+)"/', $c[6] ?? '', $p) ? $p[1] : '?';
            $scope = in_array($addr, ['0.0.0.0', '::', '*'], true) ? 'all' : Anon::scope($addr);
            $now[] = "{$c[0]}/$port $scope $proc";
        }
        $now = array_values(array_unique($now));
        sort($now);
        $this->data['ports'] = $now;
        return [
            'listening' => $now,
            'added' => $prev === null ? [] : array_values(array_diff($now, $prev)),
            'removed' => $prev === null ? [] : array_values(array_diff($prev, $now)),
        ];
    }

    // ---- UFW rules -------------------------------------------------------

    /**
     * Services like MySQL and Redis often listen on all interfaces and rely on
     * UFW alone to stay private, so any rule change matters.
     */
    private function firewallRules(Context $ctx, ?array $prev): array
    {
        $r = $ctx->cmd->run('ufw-status', ['ufw', 'status']);
        if ($r === null || !preg_match('/^Status: (\w+)/m', $r['out'], $m)) {
            return ['available' => false];
        }
        $rules = [];
        $inTable = false;
        foreach (preg_split('/\R/', $r['out']) as $l) {
            if (str_starts_with($l, '--')) {
                $inTable = true;
                continue;
            }
            if ($inTable && trim($l) !== '') {
                $rules[] = $ctx->anon->scrub((string) preg_replace('/\s{2,}/', ' | ', trim($l)), 120);
            }
        }
        $rules = array_values(array_unique($rules));
        $this->data['ufw'] = ['status' => $m[1], 'rules' => $rules];
        $out = ['status' => $m[1], 'rules' => $rules];
        if ($prev !== null) {
            $out['status_changed'] = ($prev['status'] ?? null) !== $m[1];
            $out['added'] = array_values(array_diff($rules, $prev['rules'] ?? []));
            $out['removed'] = array_values(array_diff($prev['rules'] ?? [], $rules));
        }
        return $out;
    }

    // ---- users, sudoers, ssh keys, sshd config ---------------------------

    private function accessControl(Context $ctx, ?array $prev): array
    {
        $files = array_merge(
            ['/etc/passwd', '/etc/group', '/etc/sudoers', '/etc/ssh/sshd_config', '/root/.ssh/authorized_keys'],
            $ctx->glob('/etc/sudoers.d/*'),
            $ctx->glob('/etc/ssh/sshd_config.d/*'),
            $ctx->glob('/home/*/.ssh/authorized_keys'),
        );
        $hashes = [];
        $keys = [];
        foreach ($files as $f) {
            $p = $ctx->path($f);
            $hash = SafeFile::hash($p);
            if ($hash === null) {
                if (file_exists($p) || is_link($p)) {
                    $hashes[$f] = 'unreadable-or-not-a-regular-file';
                }
                continue;
            }
            $hashes[$f] = $hash;
            if (str_ends_with($f, 'authorized_keys')) {
                foreach (SafeFile::lines($p) as $line) {
                    $k = self::fingerprint($line);
                    if ($k !== null) {
                        $keys["$f {$k['fp']}"] = Anon::clean($k['comment']);
                    }
                }
            }
        }
        ksort($hashes);
        ksort($keys);

        $users = $this->names($ctx, '/etc/passwd');
        $groups = $this->names($ctx, '/etc/group');
        $sudoers = $this->groupMembers($ctx, ['sudo', 'admin', 'wheel']);
        $shellUsers = $this->loginUsers($ctx);

        $this->data['access'] = compact('hashes', 'keys', 'users', 'groups', 'sudoers');
        $out = [
            'sudo_group_members' => $sudoers,
            'login_shell_users' => $shellUsers,
            'authorized_keys' => count($keys),
        ];
        if ($prev !== null) {
            $out['changed_files'] = array_values(array_keys(array_diff_assoc($hashes, $prev['hashes'] ?? [])));
            $out['removed_files'] = array_values(array_diff(array_keys($prev['hashes'] ?? []), array_keys($hashes)));
            $out['keys_added'] = array_values(array_map(
                fn ($k) => ['key' => $k, 'comment' => $keys[$k]],
                array_diff(array_keys($keys), array_keys($prev['keys'] ?? []))
            ));
            $out['keys_removed'] = array_values(array_diff(array_keys($prev['keys'] ?? []), array_keys($keys)));
            $out['users_added'] = array_values(array_diff($users, $prev['users'] ?? []));
            $out['users_removed'] = array_values(array_diff($prev['users'] ?? [], $users));
            $out['groups_added'] = array_values(array_diff($groups, $prev['groups'] ?? []));
            $out['sudoers_added'] = array_values(array_diff($sudoers, $prev['sudoers'] ?? []));
        }
        return $out;
    }

    /** @return array{fp:string,comment:string}|null OpenSSH-style SHA256 fingerprint */
    public static function fingerprint(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return null;
        }
        $parts = preg_split('/\s+/', $line);
        foreach ($parts as $i => $tok) {
            if (preg_match('/^(ssh-|ecdsa-|sk-)/', $tok) && isset($parts[$i + 1])) {
                $blob = base64_decode($parts[$i + 1], true);
                return [
                    // A key that doesn't decode is still tracked, by a hash of its raw text.
                    'fp' => $blob === false
                        ? 'raw:' . substr(hash('sha256', $parts[$i + 1]), 0, 16)
                        : 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='),
                    'comment' => implode(' ', array_slice($parts, $i + 2)),
                ];
            }
        }
        return null;
    }

    private function names(Context $ctx, string $file): array
    {
        $out = [];
        foreach (@file($ctx->path($file), FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if ($l !== '' && $l[0] !== '#') {
                $out[] = explode(':', $l, 2)[0];
            }
        }
        sort($out);
        return $out;
    }

    private function groupMembers(Context $ctx, array $groups): array
    {
        $out = [];
        foreach (@file($ctx->path('/etc/group'), FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $c = explode(':', $l);
            if (count($c) >= 4 && in_array($c[0], $groups, true) && $c[3] !== '') {
                foreach (explode(',', $c[3]) as $m) {
                    $out[] = "$m ({$c[0]})";
                }
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    private function loginUsers(Context $ctx): array
    {
        $out = [];
        foreach (@file($ctx->path('/etc/passwd'), FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $c = explode(':', $l);
            if (count($c) >= 7 && !preg_match('#(nologin|false|sync|halt|shutdown)$#', $c[6])) {
                $out[] = $c[0];
            }
        }
        sort($out);
        return $out;
    }

    // ---- cron and systemd timers -----------------------------------------

    private function scheduled(Context $ctx, ?array $prev): array
    {
        $files = array_merge(
            ['/etc/crontab'],
            $ctx->glob('/etc/cron.d/*'),
            $ctx->glob('/var/spool/cron/crontabs/*'),
            $ctx->glob('/etc/cron.hourly/*'),
            $ctx->glob('/etc/cron.daily/*'),
            $ctx->glob('/etc/cron.weekly/*'),
            $ctx->glob('/etc/cron.monthly/*'),
        );
        $hashes = [];
        foreach ($files as $f) {
            $p = $ctx->path($f);
            if (file_exists($p) || is_link($p)) {
                $hashes[$f] = SafeFile::hash($p) ?? 'unreadable-or-not-a-regular-file';
            }
        }
        ksort($hashes);

        $timers = [];
        $r = $ctx->cmd->run('timers', ['systemctl', 'list-timers', '--all', '--no-legend', '--plain', '--no-pager']);
        foreach (preg_split('/\R/', trim($r['out'] ?? '')) as $l) {
            if (preg_match('/(\S+\.timer)\s+(\S+\.service)?/', $l, $m)) {
                $timers[] = $m[1];
            }
        }
        sort($timers);
        $timers = array_values(array_unique($timers));

        $this->data['scheduled'] = ['hashes' => $hashes, 'timers' => $timers];
        $out = ['cron_files' => count($hashes), 'timers' => count($timers)];
        if ($prev !== null) {
            $old = $prev['hashes'] ?? [];
            $out['cron_added'] = array_values(array_diff(array_keys($hashes), array_keys($old)));
            $out['cron_changed'] = array_values(array_keys(array_filter(
                $hashes,
                fn ($h, $f) => isset($old[$f]) && $old[$f] !== $h,
                ARRAY_FILTER_USE_BOTH
            )));
            $out['cron_removed'] = array_values(array_diff(array_keys($old), array_keys($hashes)));
            $out['timers_added'] = array_values(array_diff($timers, $prev['timers'] ?? []));
            $out['timers_removed'] = array_values(array_diff($prev['timers'] ?? [], $timers));
        }
        return $out;
    }

    // ---- webroot integrity -----------------------------------------------

    /**
     * Each release is a git checkout. Anything executable in the webroot that git
     * doesn't track, or any tracked file that's been modified, is worth a look.
     * We remember what we saw per release, so after a deploy we start fresh and
     * only report files that appear later within the same release as "new".
     */
    private function webroots(Context $ctx, array $sites, array $prev): array
    {
        $out = [];
        $checked = 0;
        foreach ($sites as $id => $site) {
            if ($site['dir'] === null) {
                continue;
            }
            $current = $ctx->path($site['dir'] . '/current');
            $release = is_link($current) ? realpath($current) : (is_dir($ctx->path($site['dir'] . '/.git')) ? realpath($ctx->path($site['dir'])) : false);
            if ($release === false || !is_dir("$release/.git")) {
                continue;
            }
            $git = $this->gitCommand($ctx, $release);
            if ($git === null) {
                continue;
            }
            $checked++;
            $releaseId = basename($release);

            $r = $ctx->cmd->run("git-head-$id", [...$git, 'rev-parse', 'HEAD']);
            $head = substr(trim($r['out'] ?? ''), 0, 12);

            $modified = [];
            $r = $ctx->cmd->run("git-status-$id", [...$git, 'status', '--porcelain=v1', '--untracked-files=no']);
            foreach (preg_split('/\R/', rtrim($r['out'] ?? '')) as $l) {
                if (strlen($l) <= 3) {
                    continue;
                }
                $path = substr($l, 3);
                // Forge swaps each release's storage/ for a symlink to shared storage,
                // so git always sees its placeholder files as changed. Not code; skip.
                if (str_starts_with($path, 'storage/')) {
                    continue;
                }
                $modified[] = trim(substr($l, 0, 2)) . ' ' . $ctx->anon->scrub($path, 120);
            }

            // Our own record of every executable file in the webroot, by content hash.
            // Git can be fooled by anyone who can write to the repo (commit the shell,
            // or mark it assume-unchanged); this record can't.
            $untracked = [];
            $files = [];
            $truncated = false;
            $webroot = $site['webroot'] ?? 'public';
            $webDir = $webroot === '' ? $release : "$release/$webroot";
            if (is_dir($webDir)) {
                $r = $ctx->cmd->run("git-ls-files-$id", [...$git, 'ls-files', '--', $webroot === '' ? '.' : $webroot]);
                $tracked = array_flip(preg_split('/\R/', trim($r['out'] ?? '')));
                $prefix = strlen($release) + 1;
                foreach ($this->walk($webDir, $truncated) as $file) {
                    $rel = substr($file, $prefix);
                    $files[$rel] = SafeFile::hash($file, 5 * 1048576) ?? 'unreadable';
                    if (!isset($tracked[$rel])) {
                        $untracked[] = $ctx->anon->scrub($rel, 120);
                    }
                }
            }
            sort($modified);
            sort($untracked);
            ksort($files);

            $name = $site['name'];
            $before = $prev[$name] ?? null;
            $sameRelease = $before !== null && ($before['release'] ?? null) === $releaseId;
            $known = $sameRelease ? ($before['files'] ?? []) : [];
            $newFiles = [];
            $changedFiles = [];
            if ($sameRelease) {
                foreach ($files as $rel => $hash) {
                    if (!isset($known[$rel])) {
                        $newFiles[] = $ctx->anon->scrub($rel, 120);
                    } elseif ($known[$rel] !== $hash) {
                        $changedFiles[] = $ctx->anon->scrub($rel, 120);
                    }
                }
            }
            $this->data['webroots'][$name] = [
                'release' => $releaseId,
                'head' => $sameRelease ? ($before['head'] ?? $head) : $head,
                'modified' => $sameRelease ? array_values(array_unique(array_merge($before['modified'] ?? [], $modified))) : $modified,
                // Keep the first-seen hashes for the life of the release so later changes stay visible.
                'files' => $sameRelease ? $known + $files : $files,
            ];

            // Full lists only when this release is first seen (first run or a deploy);
            // after that, counts plus anything that appeared since, to keep the output small.
            $firstLook = !$sameRelease;
            $entry = array_filter([
                'release_changed' => $before !== null && !$sameRelease ? true : null,
                'head_moved_within_release' => $sameRelease && $head !== '' && $head !== ($before['head'] ?? $head) ? true : null,
                'modified_tracked' => $firstLook ? (array_slice($modified, 0, 20) ?: null) : null,
                'untracked_executable' => $firstLook ? (array_slice($untracked, 0, 20) ?: null) : null,
                'modified_count' => !$firstLook && $modified ? count($modified) : null,
                'untracked_count' => !$firstLook && $untracked ? count($untracked) : null,
                'new_modified' => $sameRelease ? (array_values(array_diff($modified, $before['modified'] ?? [])) ?: null) : null,
                'new_executable' => array_slice($newFiles, 0, 20) ?: null,
                'changed_executable' => array_slice($changedFiles, 0, 20) ?: null,
                'scan_truncated' => $truncated ?: null,
            ], fn ($v) => $v !== null);
            if ($entry !== []) {
                $out[$name] = $entry;
            }
        }
        ksort($out);
        return ['sites_checked' => $checked, 'findings' => $out];
    }

    /**
     * How to run git safely in a repo owned by another user.
     *
     * Running git as root in a repo the site user controls would let that user's
     * .git/config (core.fsmonitor, hooks…) execute commands as root. So git runs
     * as the repo's owner, with repo-supplied programs disabled and no index writes.
     *
     * @return list<string>|null argv prefix, or null if git can't be run safely
     */
    private function gitCommand(Context $ctx, string $release): ?array
    {
        $git = [
            'git', '--no-optional-locks',
            '-c', 'core.fsmonitor=false',
            '-c', 'core.hooksPath=/dev/null',
            '-c', 'core.untrackedCache=false',
            '-C', $release,
        ];
        if ($ctx->fixtureMode) {
            return $git;
        }
        $env = ['env', 'GIT_CONFIG_NOSYSTEM=1', 'GIT_CONFIG_GLOBAL=/dev/null', 'GIT_OPTIONAL_LOCKS=0', 'HOME=/nonexistent'];
        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            return null;
        }
        $ownerUid = @fileowner("$release/.git");
        if ($ownerUid === false) {
            return null;
        }
        if ($ownerUid === posix_geteuid()) {
            return [...$env, ...$git];
        }
        if (posix_geteuid() !== 0) {
            return null;
        }
        $owner = posix_getpwuid($ownerUid);
        if ($owner === false || $ownerUid === 0) {
            return null;
        }
        return ['runuser', '-u', $owner['name'], '--', ...$env, ...$git];
    }

    /**
     * Executable-looking files under a webroot. Follows symlinks (public/storage
     * points at user uploads), but never visits a directory twice, counts every
     * entry, and stops at a node and time budget, so a planted symlink loop or a
     * huge tree can't stall the run. Stopping early is reported, not hidden.
     */
    private function walk(string $dir, bool &$truncated): \Generator
    {
        $deadline = microtime(true) + self::WALK_SECONDS;
        $visited = [];
        $nodes = 0;
        $stack = [[$dir, 0]];
        while ($stack !== []) {
            [$d, $depth] = array_pop($stack);
            $real = realpath($d);
            if ($real === false || isset($visited[$real])) {
                continue;
            }
            $visited[$real] = true;
            $h = @opendir($d);
            if ($h === false) {
                continue;
            }
            while (($e = readdir($h)) !== false) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                if (++$nodes > self::MAX_WALK || microtime(true) > $deadline) {
                    closedir($h);
                    $truncated = true;
                    return;
                }
                $p = "$d/$e";
                if (is_dir($p)) {
                    if ($depth < 12) {
                        $stack[] = [$p, $depth + 1];
                    } else {
                        $truncated = true;
                    }
                } elseif (preg_match(self::WEBSHELL_EXT, $e)) {
                    yield $p;
                }
            }
            closedir($h);
        }
    }

    // ---- processes -------------------------------------------------------

    /** CPU seconds per (user, command) since the last run; a long-running miner stands out. */
    private function processes(Context $ctx, ?array $prev): array
    {
        $r = $ctx->cmd->run('ps', ['ps', '-eo', 'user:32,cputimes,comm', '--no-headers']);
        if ($r === null) {
            return ['available' => false];
        }
        $cpu = [];
        $count = [];
        foreach (preg_split('/\R/', trim($r['out'])) as $l) {
            $c = preg_split('/\s+/', trim($l), 3);
            if (count($c) < 3 || !ctype_digit($c[1])) {
                continue;
            }
            $k = "{$c[0]} {$c[2]}";
            $cpu[$k] = ($cpu[$k] ?? 0) + (int) $c[1];
            $count[$k] = ($count[$k] ?? 0) + 1;
        }
        $this->data['cpu'] = $cpu;
        $delta = [];
        foreach ($cpu as $k => $s) {
            // Processes that restarted have lower totals; count their whole time.
            $d = $prev !== null && isset($prev[$k]) && $s >= $prev[$k] ? $s - $prev[$k] : $s;
            $delta[$k] = $d;
        }
        $top = [];
        foreach (Tally::top($delta, 12) as $k => $s) {
            [$user, $comm] = explode(' ', (string) $k, 2);
            $top[] = ['user' => $user, 'command' => $comm, 'cpu_seconds' => $s, 'processes' => $count[$k]];
        }
        return ['since_previous_run' => $prev !== null, 'top_cpu' => $top, 'total_processes' => array_sum($count)];
    }

    // ---- patching --------------------------------------------------------

    private function patching(Context $ctx): array
    {
        $out = ['updates' => null, 'security_updates' => null];
        $r = $ctx->cmd->run('apt-check', ['/usr/lib/update-notifier/apt-check']);
        if ($r !== null && preg_match('/^(\d+);(\d+)/', trim($r['err'] . $r['out']), $m)) {
            $out['updates'] = (int) $m[1];
            $out['security_updates'] = (int) $m[2];
        }
        $out['reboot_required'] = is_file($ctx->path('/var/run/reboot-required'));
        if ($out['reboot_required']) {
            $pkgs = @file($ctx->path('/var/run/reboot-required.pkgs'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $out['reboot_packages'] = array_slice(array_values(array_unique($pkgs)), 0, 10);
        }
        return $out;
    }

    // ---- TLS certificates ------------------------------------------------

    private function tls(Context $ctx): array
    {
        $certs = [];
        $confs = array_merge($ctx->glob('/etc/nginx/sites-enabled/*'), $ctx->glob('/etc/nginx/forge-conf/*/*/*/*'), $ctx->glob('/etc/nginx/forge-conf/*/*/*'));
        foreach ($confs as $f) {
            $text = (string) @file_get_contents($ctx->path($f));
            if (preg_match_all('/^\s*ssl_certificate\s+(\S+);/m', $text, $m)) {
                foreach ($m[1] as $c) {
                    $certs[$c] = true;
                }
            }
        }
        $out = [];
        foreach (array_keys($certs) as $c) {
            $pem = @file_get_contents($ctx->path($c));
            $x = $pem ? @openssl_x509_parse($pem) : false;
            if (!$x) {
                continue;
            }
            $names = [];
            if (preg_match_all('/DNS:([^,\s]+)/', $x['extensions']['subjectAltName'] ?? '', $n)) {
                $names = $n[1];
            }
            $days = (int) floor(($x['validTo_time_t'] - time()) / 86400);
            $out[] = ['names' => array_slice($names ?: [$x['subject']['CN'] ?? '?'], 0, 4), 'days_left' => $days];
        }
        usort($out, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);
        $warn = (int) $ctx->cfg('tls_warn_days', 14);
        return [
            'certificates' => count($out),
            'expiring_soon' => array_values(array_filter($out, fn ($c) => $c['days_left'] < $warn)),
            'soonest_days_left' => $out[0]['days_left'] ?? null,
        ];
    }
}
