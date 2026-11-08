<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Context;
use LogWatch\LogReader;
use LogWatch\Tally;

/** journalctl warnings and above, systemd unit failures/restarts, OOM kills, unattended-upgrades, disk. */
final class System implements Section
{
    private const PRIORITIES = ['emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug'];

    public function name(): string
    {
        return 'system';
    }

    public function collect(Context $ctx, array $sites): array
    {
        return [
            'journal' => $this->journal($ctx),
            'units' => $this->units($ctx),
            'unattended_upgrades' => $this->upgrades($ctx),
            'disk' => $this->disk($ctx),
        ];
    }

    private function journal(Context $ctx): array
    {
        $byPriority = [];
        $byIdent = [];
        $messages = [];
        $oom = [];
        $kernel = 0;
        $skippedFirewall = 0;

        $ok = $ctx->cmd->stream('journal-warnings', [
            'journalctl', '--since', '@' . $ctx->start, '--until', '@' . ($ctx->end - 1),
            '-p', 'warning', '-o', 'json', '--no-pager', '-q',
        ], function (string $line) use ($ctx, &$byPriority, &$byIdent, &$messages, &$oom, &$kernel, &$skippedFirewall) {
            $e = json_decode($line, true);
            if (!is_array($e)) {
                return;
            }
            $msg = $e['MESSAGE'] ?? '';
            if (is_array($msg)) {
                $msg = implode('', array_map('chr', array_filter($msg, 'is_int')));
            }
            $msg = (string) $msg;
            // The kernel logs every UFW block at warning level; the firewall section covers those.
            if (str_starts_with($msg, '[UFW ')) {
                $skippedFirewall++;
                return;
            }
            $prio = self::PRIORITIES[(int) ($e['PRIORITY'] ?? 4)] ?? 'warning';
            $byPriority[$prio] = ($byPriority[$prio] ?? 0) + 1;
            $ident = (string) ($e['SYSLOG_IDENTIFIER'] ?? $e['_COMM'] ?? $e['_SYSTEMD_UNIT'] ?? 'unknown');
            $byIdent[$ident] = ($byIdent[$ident] ?? 0) + 1;
            if (($e['_TRANSPORT'] ?? '') === 'kernel') {
                $kernel++;
            }
            if (preg_match('/Out of memory: Killed process \d+ \(([^)]+)\)/', $msg, $m)
                || preg_match('/oom-kill:.*task=([^,]+)/', $msg, $m)) {
                $oom[$m[1]] = ($oom[$m[1]] ?? 0) + 1;
            }
            Tally::addMessage($messages, $ident . ': ' . $ctx->anon->scrub($msg, 200));
        });

        if (!$ok) {
            return ['available' => false];
        }
        $order = array_flip(self::PRIORITIES);
        uksort($byPriority, fn ($a, $b) => $order[$a] <=> $order[$b]);
        return [
            'by_priority' => $byPriority,
            'by_source' => Tally::top($byIdent, 10),
            'kernel_messages' => $kernel,
            'firewall_lines_skipped' => $skippedFirewall,
            'oom_kills' => $oom,
            'top_messages' => Tally::topMessages($messages, 15),
            'new_messages' => $ctx->state->newSince('journal', array_map(fn ($g) => $g['sample'], $messages), 10),
        ];
    }

    /** Unit failures and restart loops, from systemd's own (PID 1) messages. */
    private function units(Context $ctx): array
    {
        $failed = [];
        $restarts = [];
        $ok = $ctx->cmd->stream('journal-systemd', [
            'journalctl', '--since', '@' . $ctx->start, '--until', '@' . ($ctx->end - 1),
            '_PID=1', '-p', 'info', '-o', 'json', '--no-pager', '-q',
        ], function (string $line) use (&$failed, &$restarts) {
            $e = json_decode($line, true);
            if (!is_array($e) || !is_string($e['MESSAGE'] ?? null)) {
                return;
            }
            $msg = $e['MESSAGE'];
            $unit = (string) ($e['UNIT'] ?? $e['USER_UNIT'] ?? '');
            if (preg_match("/^(?:(\S+): )?Failed with result '([^']+)'/", $msg, $m)) {
                $u = $unit !== '' ? $unit : ($m[1] ?? '?');
                $failed[$u][$m[2]] = ($failed[$u][$m[2]] ?? 0) + 1;
            } elseif (preg_match('/Scheduled restart job, restart counter is at (\d+)/', $msg, $m)) {
                $u = $unit !== '' ? $unit : '?';
                $restarts[$u] = max($restarts[$u] ?? 0, (int) $m[1]);
            }
        });

        $now = [];
        $r = $ctx->cmd->run('systemctl-failed', ['systemctl', '--failed', '--plain', '--no-legend', '--no-pager']);
        if ($r !== null) {
            foreach (preg_split('/\R/', trim($r['out'])) as $l) {
                if (preg_match('/^\S*?(\S+\.(?:service|timer|socket|mount|target|path))\s/', trim($l) . ' ', $m)) {
                    $now[] = $m[1];
                }
            }
        }
        ksort($failed);
        ksort($restarts);
        return [
            'available' => $ok,
            'failures' => $failed,
            'restart_loops' => $restarts,
            'failed_now' => $now,
        ];
    }

    private function upgrades(Context $ctx): array
    {
        $files = LogReader::rotated($ctx, '/var/log/unattended-upgrades/unattended-upgrades.log');
        if ($files === []) {
            return ['available' => false];
        }
        $runs = 0;
        $packages = [];
        $errors = [];
        foreach ($files as $file) {
            foreach (LogReader::lines($file) as $line) {
                if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),\d+ (\w+) (.*)$/', $line, $m)
                    || !$ctx->inWindow(strtotime($m[1] . ' UTC') ?: null)) {
                    continue;
                }
                if (str_starts_with($m[3], 'Starting unattended upgrades')) {
                    $runs++;
                } elseif (preg_match('/^Packages that will be upgraded: (.*)$/', $m[3], $p)) {
                    foreach (preg_split('/\s+/', trim($p[1])) as $pkg) {
                        $packages[$pkg] = true;
                    }
                } elseif ($m[2] === 'ERROR' || $m[2] === 'CRITICAL') {
                    Tally::addMessage($errors, $ctx->anon->scrub($m[3], 200));
                }
            }
        }
        ksort($packages);
        return [
            'runs' => $runs,
            'packages_upgraded' => count($packages),
            'packages' => array_slice(array_keys($packages), 0, 40),
            'errors' => Tally::topMessages($errors, 5),
        ];
    }

    private function disk(Context $ctx): array
    {
        $out = [];
        $r = $ctx->cmd->run('df', ['df', '-P', '-x', 'tmpfs', '-x', 'devtmpfs', '-x', 'squashfs', '-x', 'overlay', '-x', 'efivarfs']);
        $ri = $ctx->cmd->run('df-inodes', ['df', '-P', '-i', '-x', 'tmpfs', '-x', 'devtmpfs', '-x', 'squashfs', '-x', 'overlay', '-x', 'efivarfs']);
        $inodes = [];
        foreach (preg_split('/\R/', trim($ri['out'] ?? '')) as $l) {
            $c = preg_split('/\s+/', trim($l));
            if (count($c) >= 6 && str_ends_with($c[4], '%')) {
                $inodes[$c[5]] = (int) $c[4];
            }
        }
        foreach (preg_split('/\R/', trim($r['out'] ?? '')) as $l) {
            $c = preg_split('/\s+/', trim($l));
            if (count($c) >= 6 && str_ends_with($c[4], '%')) {
                $out[] = [
                    'mount' => $c[5],
                    'size_gb' => round(((int) $c[1]) * 1024 / 1e9, 1),
                    'used_pct' => (int) $c[4],
                    'inodes_used_pct' => $inodes[$c[5]] ?? null,
                ];
            }
        }
        return $out;
    }
}
