<?php

declare(strict_types=1);

namespace LogWatch;

use LogWatch\Sections;

final class Collector
{
    public const VERSION = '0.1.0';
    public const SCHEMA = 1;

    public function __construct(private readonly Context $ctx, private readonly string $server)
    {
    }

    /** @param list<string>|null $only limit to these section names (debugging) */
    public function run(?array $only = null): array
    {
        $ctx = $this->ctx;
        $started = microtime(true);
        $sites = Sites::discover($ctx);

        $snapshot = new Sections\Snapshot();
        $sections = [
            new Sections\AuthLog(),
            new Sections\Fail2ban(),
            new Sections\Firewall(),
            new Sections\System(),
            new Sections\Nginx(),
            new Sections\AppLogs(),
            $snapshot,
        ];

        $out = [
            'schema' => self::SCHEMA,
            'collector_version' => self::VERSION,
            'server' => $this->server,
            'date' => $ctx->date,
            'window' => ['start' => gmdate('c', $ctx->start), 'end' => gmdate('c', $ctx->end)],
            'generated_at' => gmdate('c'),
            'sites' => array_values(array_map(fn ($s) => [
                'name' => $s['name'],
                'id' => $s['id'],
                'domains' => count($s['domains']),
                'access_log' => $s['access_log'] !== null,
            ], $sites)),
        ];
        $timings = [];
        $errors = [];

        foreach ($sections as $section) {
            $name = $section->name();
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            $t = microtime(true);
            try {
                $out[$name] = $section->collect($ctx, $sites);
            } catch (\Throwable $e) {
                $out[$name] = ['available' => false];
                $errors[] = ['section' => $name, 'error' => $ctx->anon->scrub($e->getMessage(), 200)];
            }
            $timings[$name] = round(microtime(true) - $t, 2);
        }

        $out['collector'] = [
            'seconds' => round(microtime(true) - $started, 2),
            'section_seconds' => $timings,
            'errors' => $errors,
            'geo' => $ctx->cfg('geo_available', false),
        ];

        // Only persist state after a full run, so a debugging --only run can't wipe it.
        if ($only === null) {
            $ctx->state->save($snapshot->data, $ctx->anon->ipMap());
        }
        return $out;
    }
}
