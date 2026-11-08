<?php

declare(strict_types=1);

namespace LogWatch;

/**
 * Optional country/ASN lookup from local MaxMind GeoLite2 files.
 * Only works when MaxMind's reader (maxmind-db/reader) is available and the
 * .mmdb files exist; otherwise the collector simply omits geo context.
 */
final class GeoLookup
{
    private function __construct(
        private readonly ?object $country,
        private readonly ?object $asn,
    ) {
    }

    public static function tryCreate(string $dir): ?self
    {
        if (!class_exists('MaxMind\\Db\\Reader')) {
            return null;
        }
        $open = function (string $file) use ($dir) {
            $p = "$dir/$file";
            return is_readable($p) ? new \MaxMind\Db\Reader($p) : null;
        };
        $country = $open('GeoLite2-Country.mmdb');
        $asn = $open('GeoLite2-ASN.mmdb');
        return ($country || $asn) ? new self($country, $asn) : null;
    }

    public function lookup(string $ip): ?array
    {
        $out = [];
        try {
            $c = $this->country?->get($ip);
            if (isset($c['country']['iso_code'])) {
                $out['country'] = $c['country']['iso_code'];
            }
            $a = $this->asn?->get($ip);
            if (isset($a['autonomous_system_number'])) {
                $out['asn'] = $a['autonomous_system_number'];
                $out['org'] = mb_substr((string) ($a['autonomous_system_organization'] ?? ''), 0, 60);
            }
        } catch (\Throwable) {
            return null;
        }
        return $out ?: null;
    }
}
