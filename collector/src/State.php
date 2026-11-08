<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Root-only state kept on the server between runs:
 *  - snapshot.json  yesterday's state snapshot, for diffs
 *  - seen.json      14-day memory of paths/messages, to spot "never seen before"
 *  - ipmap/DATE.json  token -> real IP, kept 7 days for manual investigation
 * Nothing in here is ever sent to the hub.
 */
final class State
{
    private const SEEN_DAYS = 14;
    private const IPMAP_DAYS = 7;

    private array $seen;
    private ?array $previousSnapshot;

    public function __construct(
        private readonly string $dir,
        private readonly bool $write,
        private readonly string $date,
    ) {
        $this->seen = $this->readJson('seen.json') ?? [];
        $this->previousSnapshot = $this->readJson('snapshot.json');
    }

    public function previousSnapshot(): ?array
    {
        return $this->previousSnapshot;
    }

    /**
     * Mark items as seen today and return the ones that weren't seen in the last 14 days.
     * @param array<string,mixed> $items signature => display value
     * @return array{new: list<mixed>, new_total: int, baseline_days: int}
     */
    public function newSince(string $kind, array $items, int $cap = 20): array
    {
        $bucket = $this->seen[$kind] ?? ['since' => $this->date, 'items' => []];
        $cutoff = gmdate('Y-m-d', strtotime($this->date . ' UTC') - self::SEEN_DAYS * 86400);
        $bucket['items'] = array_filter($bucket['items'], fn ($d) => $d >= $cutoff);

        $new = [];
        foreach ($items as $sig => $display) {
            if (!isset($bucket['items'][$sig])) {
                $new[] = $display;
            }
            $bucket['items'][$sig] = $this->date;
        }
        $this->seen[$kind] = $bucket;

        $baselineDays = (int) round((strtotime($this->date . ' UTC') - strtotime($bucket['since'] . ' UTC')) / 86400);
        return [
            'new' => array_slice($new, 0, $cap),
            'new_total' => count($new),
            'baseline_days' => min($baselineDays, self::SEEN_DAYS),
        ];
    }

    public function save(array $snapshot, array $ipMap): void
    {
        if (!$this->write) {
            return;
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true)) {
            throw new \RuntimeException("Can't create state dir {$this->dir}");
        }
        $this->writeJson('seen.json', $this->seen);
        $this->writeJson('snapshot.json', $snapshot);

        $mapDir = "{$this->dir}/ipmap";
        if (!is_dir($mapDir)) {
            mkdir($mapDir, 0700, true);
        }
        $existing = json_decode((string) @file_get_contents("$mapDir/{$this->date}.json"), true) ?: [];
        $this->writeJson("ipmap/{$this->date}.json", $ipMap + $existing);

        $cutoff = gmdate('Y-m-d', strtotime($this->date . ' UTC') - self::IPMAP_DAYS * 86400);
        foreach (glob("$mapDir/*.json") ?: [] as $f) {
            if (basename($f, '.json') < $cutoff) {
                @unlink($f);
            }
        }
    }

    private function readJson(string $name): ?array
    {
        $p = "{$this->dir}/$name";
        if (!is_file($p)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($p), true);
        return is_array($d) ? $d : null;
    }

    private function writeJson(string $name, array $data): void
    {
        $p = "{$this->dir}/$name";
        $tmp = "$p.tmp";
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        chmod($tmp, 0600);
        rename($tmp, $p);
    }
}
