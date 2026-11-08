<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Context;
use LogWatch\LogReader;
use LogWatch\Syslog;
use LogWatch\Tally;

/** Blocked connections from /var/log/ufw.log: volume and which ports are being probed. */
final class Firewall implements Section
{
    public function name(): string
    {
        return 'firewall';
    }

    public function collect(Context $ctx, array $sites): array
    {
        $files = LogReader::rotated($ctx, '/var/log/ufw.log');
        if ($files === []) {
            return ['available' => false];
        }
        $total = 0;
        $ports = [];
        $sources = [];
        foreach ($files as $file) {
            foreach (LogReader::lines($file) as $line) {
                if (!str_contains($line, '[UFW BLOCK]')) {
                    continue;
                }
                $p = Syslog::parse($line, $ctx->start);
                if ($p === null || !$ctx->inWindow($p['ts'])) {
                    continue;
                }
                $total++;
                if (preg_match('/ SRC=(\S+)/', $line, $s)) {
                    $sources[$s[1]] = ($sources[$s[1]] ?? 0) + 1;
                }
                if (preg_match('/ PROTO=(\w+).*? DPT=(\d+)/', $line, $d)) {
                    $k = strtolower($d[1]) . '/' . $d[2];
                    $ports[$k] = ($ports[$k] ?? 0) + 1;
                }
            }
        }
        return [
            'blocked_total' => $total,
            'unique_sources' => count($sources),
            'top_ports' => Tally::list($ports, 10, 'port'),
            'top_sources' => Tally::topIps($ctx->anon, $sources, 5),
        ];
    }
}
