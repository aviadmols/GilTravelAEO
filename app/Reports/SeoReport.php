<?php

namespace App\Reports;

use App\Services\SearchConsoleClient;
use RuntimeException;

/** Clicks, impressions, CTR and position from Google Search Console. */
class SeoReport
{
    public function __construct(private SearchConsoleClient $gsc) {}

    public function build(ReportPeriod $period): array
    {
        if (! $this->gsc->configured()) {
            throw new RuntimeException(config('report.gsc.refresh_token')
                ? 'Search Console is connected, but no property is chosen yet. Choose one in Settings.'
                : 'Search Console is not connected. Connect it in Settings.');
        }

        $d = fn ($c) => $c->format('Y-m-d');
        $rows = fn (array $dims, int $limit, bool $prev = false) => $this->gsc->query(
            $d($prev ? $period->prevStart : $period->start), $d($prev ? $period->prevEnd : $period->end), $dims, $limit
        );

        return [
            'totals' => $this->totals($rows([], 1)),
            'prev_totals' => $this->totals($rows([], 1, true)),
            'queries' => $this->rows($rows(['query'], 10)),
            'pages' => $this->rows($rows(['page'], 10)),
        ];
    }

    private function totals(array $rows): array
    {
        $r = $rows[0] ?? [];

        return [
            'clicks' => (int) ($r['clicks'] ?? 0),
            'impressions' => (int) ($r['impressions'] ?? 0),
            'ctr' => $r['ctr'] ?? null,
            'position' => $r['position'] ?? null,
        ];
    }

    private function rows(array $rows): array
    {
        return array_map(fn ($r) => ['label' => $r['keys'][0] ?? ''] + $this->totals([$r]), $rows);
    }
}
