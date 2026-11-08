<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Discovers Forge sites from the Nginx config.
 *
 * Forge writes one file per domain in /etc/nginx/sites-available, each including
 * forge-conf/<site-id>/site.conf. We group domains by site ID and read the root
 * directive to find the site folder (/home/forge/<dir>) and webroot (public, dist, web…).
 */
final class Sites
{
    /**
     * @return array<string, array{id:string, name:string, domains:list<string>, dir:?string, webroot:?string, error_log:?string, access_log:?string}>
     */
    public static function discover(Context $ctx): array
    {
        $sites = [];
        foreach ($ctx->glob('/etc/nginx/sites-enabled/*') as $file) {
            $text = (string) @file_get_contents($ctx->path($file));
            if (!preg_match('#include\s+forge-conf/(\d+)/site\.conf#', $text, $m)) {
                continue;
            }
            $id = $m[1];
            $site = $sites[$id] ?? ['id' => $id, 'domains' => [], 'dir' => null, 'webroot' => null];

            if (preg_match_all('/^\s*server_name\s+([^;]+);/m', $text, $sn)) {
                foreach ($sn[1] as $names) {
                    foreach (preg_split('/\s+/', trim($names)) as $n) {
                        if ($n !== '_' && $n !== '') {
                            $site['domains'][] = $n;
                        }
                    }
                }
            }
            if (preg_match('#^\s*root\s+/home/forge/([^/;\s]+)(?:/current)?(?:/([^;\s]*))?;#m', $text, $r)) {
                $site['dir'] = '/home/forge/' . $r[1];
                $site['webroot'] = isset($r[2]) && $r[2] !== '' ? rtrim($r[2], '/') : '';
            }
            $sites[$id] = $site;
        }

        foreach ($sites as $id => &$site) {
            $site['domains'] = array_values(array_unique($site['domains']));
            // A friendly name: the first domain that isn't Forge's on-forge.com one.
            $custom = array_values(array_filter($site['domains'], fn ($d) => !str_ends_with($d, '.on-forge.com')));
            $site['name'] = $custom[0] ?? ($site['domains'][0] ?? "site-$id");

            $conf = (string) @file_get_contents($ctx->path("/etc/nginx/forge-conf/$id/site.conf"));
            $site['access_log'] = null;
            if (preg_match('/^\s*access_log\s+(\/\S+?)(?:\s[^;]*)?;/m', $conf, $a)) {
                $site['access_log'] = $a[1];
            }
            $site['error_log'] = preg_match('/^\s*error_log\s+(\/\S+?)(?:\s[^;]*)?;/m', $conf, $e)
                ? $e[1]
                : "/var/log/nginx/$id-error.log";
        }
        unset($site);
        ksort($sites);
        return $sites;
    }

    /** Sites keyed by ID, mapped to their friendly name. */
    public static function nameFor(array $sites, string $id): string
    {
        return $sites[$id]['name'] ?? "site-$id";
    }
}
