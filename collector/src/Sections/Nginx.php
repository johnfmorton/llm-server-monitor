<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Anon;
use LogWatch\Context;
use LogWatch\LogReader;
use LogWatch\Tally;

/**
 * Nginx: the shared access.log (catch-all and redirect blocks, i.e. scanner noise),
 * any per-site access logs that have been turned on, and per-site error logs.
 */
final class Nginx implements Section
{
    /** Known scanner targets, counted separately and labelled as background noise. */
    private const SENSITIVE = [
        'wp-login' => '#wp-login|wp-admin|wp-includes|wp-content|xmlrpc\.php#i',
        'env' => '#/\.env\b#i',
        'git' => '#/\.git(/|$)#i',
        'phpmyadmin' => '#phpmyadmin|/pma\b|/myadmin#i',
        'admin' => '#^/admin\b#i',
        'php_probe' => '#\.php\d?$#i',
        'cgi' => '#/cgi-bin/#i',
        'aws_creds' => '#\.aws/|credentials#i',
    ];

    public function name(): string
    {
        return 'nginx';
    }

    public function collect(Context $ctx, array $sites): array
    {
        $out = [
            'shared_access_log' => $this->accessLog($ctx, '/var/log/nginx/access.log', 'shared'),
            'site_access_logs' => [],
            'site_errors' => [],
        ];
        foreach ($sites as $id => $site) {
            if ($site['access_log'] !== null) {
                $out['site_access_logs'][$site['name']] = $this->accessLog($ctx, $site['access_log'], "site-$id");
            }
            $err = $this->errorLog($ctx, $site['error_log']);
            if ($err !== null) {
                $out['site_errors'][$site['name']] = $err;
            }
        }
        $global = $this->errorLog($ctx, '/var/log/nginx/error.log');
        if ($global !== null) {
            $out['site_errors']['(nginx global)'] = $global;
        }
        ksort($out['site_errors']);

        // "Never seen before" across all error logs together.
        $all = [];
        foreach ($out['site_errors'] as $name => &$e) {
            foreach ($e['_groups'] as $sig => $g) {
                $all[$sig] = "$name: {$g['sample']}";
            }
            unset($e['_groups']);
        }
        unset($e);
        $out['new_error_messages'] = $ctx->state->newSince('nginx-error', $all, 15);
        return $out;
    }

    /** Combined-format access log summary. */
    private function accessLog(Context $ctx, string $path, string $seenKind): array
    {
        $files = LogReader::rotated($ctx, $path);
        if ($files === []) {
            return ['available' => false];
        }
        $total = 0;
        $statusClass = [];
        $status = [];
        $methods = [];
        $byIp = [];
        $paths = [];
        $agents = [];
        $sensitive = [];
        $perMinute = [];
        $malformed = 0;
        $tsCache = [];

        foreach ($files as $file) {
            foreach (LogReader::lines($file) as $line) {
                if (!preg_match('/^(\S+) \S+ \S+ \[([^\]]+)\] "((?:[^"\\\\]|\\\\.)*)" (\d{3}) \S+ "(?:[^"\\\\]|\\\\.)*" "((?:[^"\\\\]|\\\\.)*)"/', $line, $m)) {
                    continue;
                }
                $stamp = $m[2];
                $ts = $tsCache[$stamp] ??= (\DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $stamp) ?: null)?->getTimestamp();
                if (count($tsCache) > 5000) {
                    $tsCache = [];
                }
                if (!$ctx->inWindow($ts)) {
                    continue;
                }
                $total++;
                $ip = $m[1];
                $code = $m[4];
                $statusClass[$code[0] . 'xx'] = ($statusClass[$code[0] . 'xx'] ?? 0) + 1;
                $status[$code] = ($status[$code] ?? 0) + 1;
                $byIp[$ip] = ($byIp[$ip] ?? 0) + 1;
                $minuteKey = $ip . '|' . intdiv($ts, 60);
                $perMinute[$minuteKey] = ($perMinute[$minuteKey] ?? 0) + 1;

                if (preg_match('/^([A-Z]{3,10}) (\S+) HTTP\/[\d.]+$/', $m[3], $r)) {
                    $methods[$r[1]] = ($methods[$r[1]] ?? 0) + 1;
                    $path = $ctx->anon->scrubPath($r[2], 120);
                    $paths[$path] = ($paths[$path] ?? 0) + 1;
                    foreach (self::SENSITIVE as $label => $re) {
                        if (preg_match($re, $path)) {
                            $sensitive[$label] = ($sensitive[$label] ?? 0) + 1;
                            break;
                        }
                    }
                } else {
                    $malformed++;
                }
                $ua = $m[5] === '-' ? '(none)' : $m[5];
                $agents[$ua] = ($agents[$ua] ?? 0) + 1;
            }
        }

        // Bursts: busiest minute per IP, above the threshold.
        $threshold = (int) $ctx->cfg('burst_per_minute', 120);
        $peak = [];
        foreach ($perMinute as $k => $n) {
            $ip = substr($k, 0, strrpos($k, '|'));
            if ($n > $threshold && $n > ($peak[$ip] ?? 0)) {
                $peak[$ip] = $n;
            }
        }
        $bursts = [];
        foreach (Tally::top($peak, 10) as $ip => $n) {
            $bursts[] = $ctx->anon->describe((string) $ip) + ['peak_per_minute' => $n];
        }

        $topAgents = [];
        foreach (Tally::top($agents, 10) as $ua => $c) {
            $topAgents[] = ['agent' => $ctx->anon->scrub((string) $ua, 160), 'count' => $c];
        }

        // New paths, keyed by a normalised form so /x/123 and /x/456 count as one.
        $pathSigs = [];
        foreach ($paths as $p => $_) {
            $pathSigs[Anon::signature((string) $p)] = (string) $p;
        }

        ksort($statusClass);
        ksort($methods);
        ksort($sensitive);
        return [
            'requests' => $total,
            'by_status_class' => $statusClass,
            'top_status' => Tally::top($status, 8),
            'methods' => $methods,
            'malformed_requests' => $malformed,
            'unique_ips' => count($byIp),
            'top_ips' => Tally::topIps($ctx->anon, $byIp, 10),
            'top_paths' => Tally::list($paths, 15, 'path'),
            'top_agents' => $topAgents,
            'scanner_patterns' => $sensitive,
            'bursts' => $bursts,
            'new_paths' => $ctx->state->newSince("paths-$seenKind", $pathSigs, 15),
        ];
    }

    /** Nginx error log for one site: counts by type, probe scripts, top messages. */
    private function errorLog(Context $ctx, string $path): ?array
    {
        $files = LogReader::rotated($ctx, $path);
        if ($files === []) {
            return null;
        }
        $levels = [];
        $types = [];
        $probes = [];
        $clients = [];
        $groups = [];
        $total = 0;

        foreach ($files as $file) {
            foreach (LogReader::lines($file) as $line) {
                if (!preg_match('#^(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] \d+\#\d+: (?:\*\d+ )?(.*)$#', $line, $m)) {
                    continue;
                }
                if (!$ctx->inWindow(strtotime(str_replace('/', '-', $m[1]) . ' UTC') ?: null)) {
                    continue;
                }
                $total++;
                $levels[$m[2]] = ($levels[$m[2]] ?? 0) + 1;
                $msg = $m[3];
                if (preg_match('/, client: ([^,]+),/', $msg, $c)) {
                    $clients[$c[1]] = true;
                }
                $main = preg_replace('/, client: .*$/', '', $msg);

                $type = $this->classify($main);
                $types[$type] = ($types[$type] ?? 0) + 1;
                if ($type === 'missing_script_probe' && preg_match('#Unable to open primary script: (\S+)#', $main, $s)) {
                    $script = $ctx->anon->scrub(basename($s[1]), 60);
                    $probes[$script] = ($probes[$script] ?? 0) + 1;
                    continue; // probes are counted, not listed as messages
                }
                Tally::addMessage($groups, $ctx->anon->scrub($main, 200));
            }
        }
        if ($total === 0) {
            return null;
        }
        ksort($levels);
        return [
            'total' => $total,
            'by_level' => $levels,
            'by_type' => Tally::top($types, 12),
            'unique_clients' => count($clients),
            'probe_scripts' => Tally::list($probes, 5, 'script'),
            'top_messages' => Tally::topMessages($groups, 5),
            '_groups' => $groups,
        ];
    }

    private function classify(string $msg): string
    {
        return match (true) {
            str_contains($msg, 'Unable to open primary script') => 'missing_script_probe',
            str_contains($msg, 'PHP Fatal error') => 'php_fatal',
            str_contains($msg, 'PHP Warning'), str_contains($msg, 'PHP Notice'), str_contains($msg, 'PHP Deprecated') => 'php_warning',
            str_contains($msg, 'FastCGI sent in stderr') => 'php_stderr',
            str_contains($msg, 'upstream timed out') => 'upstream_timeout',
            (bool) preg_match('/connect\(\) .*failed|no live upstreams|upstream prematurely closed/', $msg) => 'upstream_unavailable',
            str_contains($msg, 'access forbidden by rule') => 'forbidden_by_rule',
            str_contains($msg, 'is forbidden') => 'forbidden',
            str_contains($msg, 'limiting requests') => 'rate_limited',
            str_contains($msg, 'client intended to send too large body') => 'body_too_large',
            str_contains($msg, 'SSL_'), str_contains($msg, 'ssl') => 'tls',
            (bool) preg_match('/open\(\) ".*" failed|No such file or directory/', $msg) => 'file_not_found',
            default => 'other',
        };
    }
}
