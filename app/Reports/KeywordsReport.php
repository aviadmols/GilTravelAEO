<?php

namespace App\Reports;

use App\Services\AhrefsClient;

/** Tracked keywords from Ahrefs Rank Tracker: position at the end of the period, against the end of the previous one. */
class KeywordsReport
{
    public function __construct(private AhrefsClient $ahrefs) {}

    public function build(ReportPeriod $period): array
    {
        $rows = $this->ahrefs->keywords($period->end->toDateString(), $period->prevEnd->toDateString());

        // Plain values only: the cache does not unserialize objects (cache.serializable_classes).
        return [
            'date' => $period->end->toDateString(),
            'compared' => $period->prevEnd->toDateString(),
            'keywords' => array_map(fn (array $r) => [
                'keyword' => (string) ($r['keyword'] ?? ''),
                'position' => $r['position'] ?? null,
                'position_prev' => $r['position_prev'] ?? null,
                'change' => self::change($r['position'] ?? null, $r['position_prev'] ?? null),
                'volume' => $r['volume'] ?? null,
                'traffic' => $r['traffic'] ?? null,
                'url' => $r['url'] ?? null,
                'location' => $r['location'] ?? null,
            ], $rows),
            'top10' => count(array_filter($rows, fn ($r) => ($r['position'] ?? null) !== null && $r['position'] <= 10)),
            'prev_top10' => count(array_filter($rows, fn ($r) => ($r['position_prev'] ?? null) !== null && $r['position_prev'] <= 10)),
        ];
    }

    /** Places gained (positive) or lost (negative). Ahrefs does not document the sign of its own diff, so it is computed here. */
    public static function change(?int $now, ?int $prev): ?int
    {
        return $now !== null && $prev !== null ? $prev - $now : null;
    }
}
