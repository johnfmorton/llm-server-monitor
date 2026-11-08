<?php

declare(strict_types=1);

namespace LogWatch;

/** Small counting helpers shared by the sections. */
final class Tally
{
    /** @param array<string,int> $counts @return array<string,int> top N, highest first, ties by key */
    public static function top(array $counts, int $n): array
    {
        uksort($counts, fn ($a, $b) => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);
        return array_slice($counts, 0, $n, true);
    }

    /**
     * Top IPs as [{ip: token, scope, ..., count}].
     * @param array<string,int> $byIp real IP => count
     */
    public static function topIps(Anon $anon, array $byIp, int $n): array
    {
        $out = [];
        foreach (self::top($byIp, $n) as $ip => $count) {
            $out[] = $anon->describe((string) $ip) + ['count' => $count];
        }
        return $out;
    }

    /** @param array<string,int> $counts @return list<array{value:string,count:int}> */
    public static function list(array $counts, int $n, string $label = 'value'): array
    {
        $out = [];
        foreach (self::top($counts, $n) as $k => $c) {
            $out[] = [$label => (string) $k, 'count' => $c];
        }
        return $out;
    }

    /**
     * Group messages by signature, keeping one scrubbed sample and a count.
     * @param array<string,array{sample:string,count:int}> $groups
     */
    public static function addMessage(array &$groups, string $scrubbed, int $by = 1): string
    {
        $sig = Anon::signature($scrubbed);
        if (!isset($groups[$sig])) {
            $groups[$sig] = ['sample' => $scrubbed, 'count' => 0];
        }
        $groups[$sig]['count'] += $by;
        return $sig;
    }

    /** @return list<array{sig:string,sample:string,count:int}> */
    public static function topMessages(array $groups, int $n): array
    {
        uasort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);
        $out = [];
        foreach (array_slice($groups, 0, $n, true) as $sig => $g) {
            $out[] = ['sig' => (string) $sig] + $g;
        }
        return $out;
    }
}
