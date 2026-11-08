<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Context;
use LogWatch\LogReader;
use LogWatch\Tally;

/**
 * Laravel and Craft (Yii/Monolog) logs under each site's storage/logs.
 *
 * Only the level, the exception class and a scrubbed first line of the message
 * leave the server. Stack traces and context arrays never do: they can hold
 * client data and secrets.
 */
final class AppLogs implements Section
{
    private const SERIOUS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    /** Very large files that never rotate: only scan the tail. */
    private const MAX_SCAN_BYTES = 64 * 1024 * 1024;

    public function name(): string
    {
        return 'apps';
    }

    public function collect(Context $ctx, array $sites): array
    {
        $out = [];
        $allSigs = [];
        foreach ($sites as $site) {
            if ($site['dir'] === null) {
                continue;
            }
            $r = $this->site($ctx, $site['dir']);
            if ($r === null) {
                continue;
            }
            foreach ($r['_groups'] as $sig => $g) {
                $allSigs[$sig] = "{$site['name']}: {$g['sample']}";
            }
            unset($r['_groups']);
            $out['sites'][$site['name']] = $r;
        }
        if (isset($out['sites'])) {
            ksort($out['sites']);
        }
        $out['new_errors'] = $ctx->state->newSince('app-error', $allSigs, 15);
        return $out;
    }

    private function site(Context $ctx, string $dir): ?array
    {
        // Zero-downtime deploys share <dir>/storage; plain sites keep it in the checkout.
        $logDirs = [];
        foreach (["$dir/storage/logs", "$dir/current/storage/logs"] as $d) {
            $real = realpath($ctx->path($d));
            if ($real !== false && is_dir($real)) {
                $logDirs[$real] = true;
            }
        }
        if ($logDirs === []) {
            return null;
        }

        $framework = is_file($ctx->path("$dir/current/craft")) ? 'craft' : (is_file($ctx->path("$dir/current/artisan")) ? 'laravel' : 'unknown');
        $levels = [];   // channel => level => count
        $groups = [];   // sig => {sample, count}
        $truncated = [];

        foreach (array_keys($logDirs) as $logDir) {
            foreach (glob("$logDir/*.log") ?: [] as $file) {
                if (!$ctx->mayCover($file)) {
                    continue;
                }
                $channel = (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', basename($file, '.log'));
                $size = (int) @filesize($file);
                if ($size > self::MAX_SCAN_BYTES) {
                    $truncated[] = $channel;
                }
                foreach (LogReader::lines($file, self::MAX_SCAN_BYTES) as $line) {
                    $e = self::parse($line);
                    if ($e === null || !$ctx->inWindow($e['ts'])) {
                        continue;
                    }
                    $levels[$channel][$e['level']] = ($levels[$channel][$e['level']] ?? 0) + 1;
                    if (in_array($e['level'], self::SERIOUS, true)) {
                        $label = ($e['class'] !== null ? $e['class'] . ': ' : '') . $e['message'];
                        Tally::addMessage($groups, "[$channel.{$e['level']}] " . $ctx->anon->scrub($label, 200));
                    }
                }
            }
        }
        if ($levels === []) {
            return null;
        }
        ksort($levels);
        $totals = [];
        foreach ($levels as $byLevel) {
            foreach ($byLevel as $lvl => $n) {
                $totals[$lvl] = ($totals[$lvl] ?? 0) + $n;
            }
        }
        ksort($totals);
        return array_filter([
            'framework' => $framework,
            'by_level' => $totals,
            'by_channel' => $levels,
            'top_errors' => Tally::topMessages($groups, 8),
            'scanned_tail_only' => $truncated ?: null,
            '_groups' => $groups,
        ], fn ($v) => $v !== null);
    }

    /**
     * One log entry header, or null for continuation lines (stack traces).
     *   Laravel: [2026-11-07 19:00:02] production.ERROR: Message {"exception":"[object] (Foo\\Bar(code: 0): …"}
     *   Craft:   2026-11-07 19:20:50 [web.ERROR] [yii\web\HttpException:400] Message {"trace":[…]}
     * @return array{ts:?int,level:string,class:?string,message:string}|null
     */
    public static function parse(string $line): ?array
    {
        if ($line === '' || ($line[0] !== '[' && !ctype_digit($line[0]))) {
            return null;
        }
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+(?:[+-]\d{2}:?\d{2}|Z)?)\] [\w-]+\.([A-Z]+): (.*)$/', $line, $m)) {
            [$stamp, $level, $rest] = [$m[1], $m[2], $m[3]];
            $class = null;
            if (preg_match('/"exception":"\[object\] \(([A-Za-z0-9_\\\\]+)\(code: /', $rest, $c)) {
                $class = str_replace('\\\\', '\\', $c[1]);
            }
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[[\w-]+\.([a-zA-Z]+)\] (?:\[([^\]]+)\] )?(.*)$/', $line, $m)) {
            [$stamp, $level, $rest] = [$m[1], strtoupper($m[2]), $m[4]];
            $class = isset($m[3]) && $m[3] !== '' ? $m[3] : null;
        } else {
            return null;
        }
        // The message is everything before the JSON context / extra arrays.
        $message = (string) preg_replace('/\s+(\{".*|\[\].*|\{\}.*)$/', '', $rest);
        $ts = strtotime(str_contains($stamp, '+') || str_ends_with($stamp, 'Z') ? $stamp : "$stamp UTC");
        return ['ts' => $ts === false ? null : $ts, 'level' => $level, 'class' => $class, 'message' => $message];
    }
}
