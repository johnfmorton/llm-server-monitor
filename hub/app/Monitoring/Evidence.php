<?php

namespace App\Monitoring;

use Illuminate\Support\Str;

/**
 * Turns a rule hit's evidence into short readable lines for email and the day page.
 */
class Evidence
{
    /**
     * @param  array<string, mixed>  $evidence
     * @return list<string>
     */
    public static function lines(array $evidence): array
    {
        $lines = [];
        foreach ($evidence as $key => $value) {
            if ($key === 'count') {
                continue;
            }
            $label = Str::ucfirst(str_replace('_', ' ', (string) $key));
            if (is_array($value) && array_is_list($value) && $value !== [] && is_array($value[0])) {
                foreach ($value as $item) {
                    $lines[] = "{$label}: ".self::inline($item);
                }
            } else {
                $lines[] = "{$label}: ".self::inline($value);
            }
        }

        return $lines;
    }

    private static function inline(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (! is_array($value)) {
            return (string) $value;
        }
        if (array_is_list($value)) {
            return implode(', ', array_map(self::inline(...), $value));
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $name = str_replace('_', ' ', (string) $key);
            $parts[] = is_array($item) && ! array_is_list($item) ? "{$name} (".self::inline($item).')' : "{$name}: ".self::inline($item);
        }

        return implode('; ', $parts);
    }
}
