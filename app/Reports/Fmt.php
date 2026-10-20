<?php

namespace App\Reports;

/** Number formatting for the report view. */
final class Fmt
{
    public static function num(int|float|null $v): string
    {
        if ($v === null) return '—';
        if (abs($v) >= 100000) return number_format($v / 1000, 0) . 'K';
        if (abs($v) >= 10000) return number_format($v / 1000, 1) . 'K';

        return number_format($v, is_float($v) && floor($v) != $v ? 1 : 0);
    }

    public static function pct(?float $v, int $decimals = 0): string
    {
        return $v === null ? '—' : number_format($v * 100, $decimals) . '%';
    }

    public static function money(int|float|null $v): string
    {
        if ($v === null) return '—';
        if (abs($v) >= 1000000) return '$' . number_format($v / 1000000, 1) . 'M';
        if (abs($v) >= 10000) return '$' . number_format($v / 1000, 1) . 'K';

        return '$' . number_format($v, 0);
    }

    /**
     * Change against the previous period: ['text' => '+12%', 'dir' => 'up|down|flat', 'good' => bool|null].
     * $kind: "count" (relative %), "rate" (percentage points), "position" (lower is better).
     */
    public static function delta(int|float|null $now, int|float|null $prev, string $kind = 'count'): ?array
    {
        if ($now === null || $prev === null) return null;

        if ($kind === 'count') {
            if ($prev == 0) return $now == 0 ? ['text' => '0%', 'dir' => 'flat', 'good' => null] : ['text' => 'new', 'dir' => 'up', 'good' => true];
            $change = ($now - $prev) / $prev;
            $text = sprintf('%+.0f%%', $change * 100);
        } elseif ($kind === 'rate') {
            $change = $now - $prev;
            $text = sprintf('%+.1f pts', $change * 100);
        } else { // position
            $change = $now - $prev;
            $text = sprintf('%+.1f', $change);
        }

        if (round($change, 3) == 0) return ['text' => $text, 'dir' => 'flat', 'good' => null];
        $up = $change > 0;

        return ['text' => $text, 'dir' => $up ? 'up' : 'down', 'good' => $kind === 'position' ? ! $up : $up];
    }

    /** Short display form of a URL: path only, or host when the path is "/". */
    public static function url(?string $url): string
    {
        $parts = parse_url((string) $url);
        $path = ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return ($path === '' || $path === '/') ? ($parts['host'] ?? (string) $url) : $path;
    }
}
