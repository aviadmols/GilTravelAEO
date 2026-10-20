<?php

namespace App\Reports;

/** The tracked brands from config, each with a regex that finds it in an AI answer. */
final class Brands
{
    /** @return list<array{name: string, own: bool, pattern: string}> */
    public static function all(): array
    {
        $brands = [];
        foreach ([[config('report.own_brand'), true], [config('report.competitors'), false]] as [$spec, $own]) {
            foreach (array_filter(array_map('trim', explode(';', (string) $spec))) as $brand) {
                $aliases = array_values(array_filter(array_map('trim', explode('|', $brand))));
                if (! $aliases) continue;
                // Longest alias first so "Gil Travel Group" wins over "Gil Travel".
                usort($aliases, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                $quoted = implode('|', array_map(fn ($a) => preg_quote($a, '/'), $aliases));
                $brands[] = [
                    'name' => trim(explode('|', $brand)[0]),
                    'own' => $own,
                    'pattern' => '/(?<![\p{L}\p{N}])(?:' . $quoted . ')(?![\p{L}\p{N}])/iu',
                ];
                if ($own) break; // one own brand
            }
        }

        return $brands;
    }
}
