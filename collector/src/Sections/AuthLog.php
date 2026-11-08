<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Anon;
use LogWatch\Context;
use LogWatch\LogReader;
use LogWatch\Syslog;
use LogWatch\Tally;

/** SSH logins and failures, sudo use, and account changes from /var/log/auth.log. */
final class AuthLog implements Section
{
    public function name(): string
    {
        return 'auth';
    }

    public function collect(Context $ctx, array $sites): array
    {
        $files = LogReader::rotated($ctx, '/var/log/auth.log');
        if ($files === []) {
            return ['available' => false];
        }

        $accepted = [];          // user|method|ip|fingerprint => count
        $failedBySrc = [];       // ip => count
        $failedUsers = [];       // username => count
        $failed = ['invalid_user' => 0, 'bad_auth' => 0, 'preauth_disconnect' => 0, 'max_auth_exceeded' => 0];
        $sudo = [];              // user|runas|command => count
        $sudoFail = [];          // user => count
        $sudoDenied = 0;
        $accounts = ['users_added' => [], 'users_removed' => [], 'groups_added' => [], 'groups_removed' => [], 'group_membership' => [], 'password_changes' => []];
        $lines = 0;

        foreach ($files as $file) {
            foreach (LogReader::lines($file) as $line) {
                $p = Syslog::parse($line, $ctx->start);
                if ($p === null || !$ctx->inWindow($p['ts'])) {
                    continue;
                }
                $lines++;
                $prog = $p['prog'];
                $msg = $p['msg'];

                if (str_starts_with($prog, 'sshd')) {
                    if (preg_match('/^Accepted (\S+) for (\S+) from (\S+) port \d+(?: ssh2)?(?:: \S+ (\S+))?/', $msg, $m)) {
                        $key = implode('|', [$m[2], $m[1], $m[3], $m[4] ?? '']);
                        $accepted[$key] = ($accepted[$key] ?? 0) + 1;
                    } elseif (preg_match('/^Invalid user (.*?) from (\S+) port \d+/', $msg, $m)) {
                        $failed['invalid_user']++;
                        $failedBySrc[$m[2]] = ($failedBySrc[$m[2]] ?? 0) + 1;
                        $u = $m[1] === '' ? '(empty)' : $m[1];
                        $failedUsers[$u] = ($failedUsers[$u] ?? 0) + 1;
                    } elseif (preg_match('/^Failed \S+ for (?:invalid user )?(.*?) from (\S+) port \d+/', $msg, $m)) {
                        $failed['bad_auth']++;
                        $failedBySrc[$m[2]] = ($failedBySrc[$m[2]] ?? 0) + 1;
                        $failedUsers[$m[1]] = ($failedUsers[$m[1]] ?? 0) + 1;
                    } elseif (preg_match('/^Connection closed by authenticating user (\S+) (\S+) port \d+ \[preauth\]/', $msg, $m)) {
                        $failed['preauth_disconnect']++;
                        $failedBySrc[$m[2]] = ($failedBySrc[$m[2]] ?? 0) + 1;
                        $failedUsers[$m[1]] = ($failedUsers[$m[1]] ?? 0) + 1;
                    } elseif (str_contains($msg, 'maximum authentication attempts exceeded')) {
                        $failed['max_auth_exceeded']++;
                    }
                } elseif ($prog === 'sudo') {
                    if (preg_match('/^\s*(\S+) : .*?USER=(\S+) ; COMMAND=(\S+)/', $msg, $m)) {
                        $key = "{$m[1]}|{$m[2]}|{$m[3]}";
                        $sudo[$key] = ($sudo[$key] ?? 0) + 1;
                        if (str_contains($msg, 'incorrect password attempt')) {
                            $sudoFail[$m[1]] = ($sudoFail[$m[1]] ?? 0) + 1;
                        }
                    } elseif (preg_match('/authentication failure;.*\bruser=(\S*)/', $msg, $m)) {
                        $u = $m[1] !== '' ? $m[1] : '(unknown)';
                        $sudoFail[$u] = ($sudoFail[$u] ?? 0) + 1;
                    } elseif (str_contains($msg, 'NOT in sudoers')) {
                        $sudoDenied++;
                    }
                } elseif (in_array($prog, ['useradd', 'userdel', 'groupadd', 'groupdel', 'usermod', 'gpasswd', 'passwd', 'chpasswd', 'adduser', 'deluser'], true)) {
                    if (preg_match('/new user: name=([^,\s]+)/', $msg, $m)) {
                        $accounts['users_added'][] = $m[1];
                    } elseif (preg_match('/new group: name=([^,\s]+)/', $msg, $m)) {
                        $accounts['groups_added'][] = $m[1];
                    } elseif (preg_match("/delete user '([^']+)'/", $msg, $m)) {
                        $accounts['users_removed'][] = $m[1];
                    } elseif (preg_match("/(?:removed group|group) '([^']+)' removed/", $msg, $m)) {
                        $accounts['groups_removed'][] = $m[1];
                    } elseif (preg_match("/add '([^']+)' to (?:shadow )?group '([^']+)'/", $msg, $m)) {
                        $accounts['group_membership'][] = "{$m[1]} +{$m[2]}";
                    } elseif (preg_match("/(?:user (\S+) added by \S+ to group (\S+))/", $msg, $m)) {
                        $accounts['group_membership'][] = "{$m[1]} +{$m[2]}";
                    } elseif (preg_match('/password changed for (\S+)/', $msg, $m)) {
                        $accounts['password_changes'][] = $m[1];
                    }
                }
            }
        }

        $labels = $accepted !== [] ? self::keyLabels($ctx) : [];
        $logins = [];
        foreach ($accepted as $key => $count) {
            [$user, $method, $ip, $fp] = explode('|', $key);
            $logins[] = array_filter([
                'user' => $ctx->anon->scrub($user, 32),
                'method' => $method,
                'source' => $ctx->anon->describe($ip),
                'key' => $fp !== '' ? $fp : null,
                // The comment on the matching authorized_keys line, e.g. worker@forge.laravel.com,
                // so expected logins (Forge's own deploy key) can be allowlisted by key.
                'key_comment' => $fp !== '' ? ($labels[$fp] ?? '(not in any authorized_keys)') : null,
                'count' => $count,
            ], fn ($v) => $v !== null);
        }

        $sudoOut = [];
        foreach (Tally::top($sudo, 15) as $key => $count) {
            [$user, $runas, $cmd] = explode('|', $key);
            $sudoOut[] = ['user' => $user, 'as' => $runas, 'command' => $ctx->anon->scrub($cmd, 80), 'count' => $count];
        }

        $usernames = [];
        foreach (Tally::top($failedUsers, 10) as $u => $c) {
            $usernames[] = ['user' => $ctx->anon->scrub((string) $u, 32), 'count' => $c];
        }

        foreach ($accounts as &$list) {
            $list = array_values(array_unique(array_map(fn ($v) => $ctx->anon->scrub($v, 64), $list)));
        }
        unset($list);

        return [
            'lines_in_window' => $lines,
            'ssh' => [
                'logins' => $logins,
                'logins_from_outside_tailnet' => count(array_filter($logins, fn ($l) => $l['source']['scope'] !== 'tailnet')),
                'failed' => $failed + [
                    'total' => array_sum($failed),
                    'unique_sources' => count($failedBySrc),
                    'top_sources' => Tally::topIps($ctx->anon, $failedBySrc, 10),
                    'top_usernames' => $usernames,
                ],
            ],
            'sudo' => [
                'commands' => $sudoOut,
                'auth_failures' => $sudoFail,
                'denied' => $sudoDenied,
            ],
            'accounts' => $accounts,
        ];
    }

    /** @return array<string,string> fingerprint => scrubbed key comment */
    private static function keyLabels(Context $ctx): array
    {
        $labels = [];
        $files = array_merge(['/root/.ssh/authorized_keys'], $ctx->glob('/home/*/.ssh/authorized_keys'));
        foreach ($files as $f) {
            foreach (@file($ctx->path($f), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $k = Snapshot::fingerprint($line);
                if ($k !== null && !isset($labels[$k['fp']])) {
                    $labels[$k['fp']] = Anon::clean($k['comment'] !== '' ? $k['comment'] : '(no comment)');
                }
            }
        }
        return $labels;
    }
}
