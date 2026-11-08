<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Parses rsyslog lines in either format:
 *   2026-11-08T17:12:01.123456+00:00 host prog[123]: message   (Ubuntu 24.04+)
 *   Nov  8 17:12:01 host prog[123]: message                     (classic)
 */
final class Syslog
{
    private static string $lastStamp = '';
    private static ?int $lastTs = null;

    /** @return array{ts:int,prog:string,msg:string}|null */
    public static function parse(string $line, int $windowStart): ?array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?) \S+ (.*)$/', $line, $m)) {
            $stamp = $m[1];
            $rest = $m[2];
        } elseif (preg_match('/^([A-Z][a-z]{2} [ \d]\d \d{2}:\d{2}:\d{2}) \S+ (.*)$/', $line, $m)) {
            $stamp = $m[1];
            $rest = $m[2];
        } else {
            return null;
        }
        $ts = self::ts($stamp, $windowStart);
        if ($ts === null || !preg_match('/^([^\s:\[]+)(?:\[\d+\])?: ?(.*)$/', $rest, $p)) {
            return null;
        }
        return ['ts' => $ts, 'prog' => $p[1], 'msg' => $p[2]];
    }

    private static function ts(string $stamp, int $windowStart): ?int
    {
        // Whole seconds repeat across many lines, so cache the last conversion.
        $key = substr($stamp, 0, 19);
        if ($key === self::$lastStamp) {
            return self::$lastTs;
        }
        if ($stamp[4] === '-') {
            $ts = strtotime($stamp);
        } else {
            // Classic format has no year: take the window's year, and if that lands
            // far in the future (a December line read in January), use the year before.
            $year = (int) gmdate('Y', $windowStart);
            $ts = strtotime("$stamp $year UTC");
            if ($ts !== false && $ts > $windowStart + 180 * 86400) {
                $ts = strtotime("$stamp " . ($year - 1) . ' UTC');
            }
        }
        self::$lastStamp = $key;
        return self::$lastTs = ($ts === false ? null : $ts);
    }
}
