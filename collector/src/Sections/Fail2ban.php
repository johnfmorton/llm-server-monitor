<?php

declare(strict_types=1);

namespace LogWatch\Sections;

use LogWatch\Context;
use LogWatch\LogReader;
use LogWatch\Tally;

/** Bans per jail from /var/log/fail2ban.log. */
final class Fail2ban implements Section
{
    public function name(): string
    {
        return 'fail2ban';
    }

    public function collect(Context $ctx, array $sites): array
    {
        $files = LogReader::rotated($ctx, '/var/log/fail2ban.log');
        if ($files === []) {
            return ['available' => false];
        }
        $bans = [];
        $found = [];
        $banned = [];
        foreach ($files as $file) {
            foreach (LogReader::lines($file) as $line) {
                if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),\d+ \S+\s+\[\d+\]: \w+\s+\[([^\]]+)\] (Ban|Found|Restore Ban) (\S+)/', $line, $m)) {
                    continue;
                }
                if (!$ctx->inWindow(strtotime($m[1] . ' UTC') ?: null)) {
                    continue;
                }
                $jail = $m[2];
                if ($m[3] === 'Found') {
                    $found[$jail] = ($found[$jail] ?? 0) + 1;
                } else {
                    $bans[$jail] = ($bans[$jail] ?? 0) + 1;
                    $banned[$m[4]] = ($banned[$m[4]] ?? 0) + 1;
                }
            }
        }
        ksort($bans);
        ksort($found);
        return [
            'bans_by_jail' => $bans,
            'matches_by_jail' => $found,
            'unique_banned' => count($banned),
            'top_banned' => Tally::topIps($ctx->anon, $banned, 5),
        ];
    }
}
