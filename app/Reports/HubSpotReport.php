<?php

namespace App\Reports;

use App\Services\HubSpotClient;

/** Website traffic, leads and revenue, read live from HubSpot. */
class HubSpotReport
{
    public function __construct(private HubSpotClient $api) {}

    public function traffic(ReportPeriod $period): array
    {
        [$sources, $prevSources] = [$this->analytics('sources/total', $period), $this->analytics('sources/total', $period, true)];
        $prevBySource = collect($prevSources['breakdowns'] ?? [])->keyBy(fn ($b) => Sources::label($b['breakdown'] ?? null));
        $pages = $this->pages($period);

        return [
            'sessions' => (int) data_get($sources, 'totals.visits', 0),
            'prev_sessions' => (int) data_get($prevSources, 'totals.visits', 0),
            'views' => (int) data_get($sources, 'totals.rawViews', 0),
            'prev_views' => (int) data_get($prevSources, 'totals.rawViews', 0),
            'sources' => collect($sources['breakdowns'] ?? [])
                ->map(function ($b) use ($prevBySource) {
                    $label = Sources::label($b['breakdown'] ?? null);

                    return [
                        'label' => $label,
                        'sessions' => (int) ($b['visits'] ?? 0),
                        'prev_sessions' => (int) data_get($prevBySource->get($label), 'visits', 0),
                        'contacts' => (int) ($b['contacts'] ?? 0),
                    ];
                })
                ->sortByDesc('sessions')->values()->all(),
            'top_pages' => $pages->sortByDesc('views')->take(10)->values()->all(),
            'landing_pages' => $pages->sortByDesc('entrances')->take(10)->values()->all(),
        ];
    }

    public function leads(ReportPeriod $period): array
    {
        $contacts = $this->api->search('contacts', $this->between('createdate', $period), ['hs_analytics_source']);
        $forms = $this->analytics('forms/total', $period);
        $prevForms = $this->analytics('forms/total', $period, true);
        $names = rescue(fn () => $this->api->formNames(), [], false);
        $fragments = $this->keyPages();
        $pages = $this->pages($period);

        return [
            'new_contacts' => count($contacts),
            'prev_new_contacts' => $this->api->count('contacts', $this->between('createdate', $period, true)),
            'submissions' => (int) data_get($forms, 'totals.submissions', 0),
            'prev_submissions' => (int) data_get($prevForms, 'totals.submissions', 0),
            'by_source' => collect($contacts)
                ->groupBy(fn ($c) => Sources::label($c['hs_analytics_source'] ?? null))
                ->map(fn ($group, $label) => ['label' => $label, 'contacts' => $group->count()])
                ->sortByDesc('contacts')->values()->all(),
            'by_form' => collect($forms['breakdowns'] ?? [])
                ->map(fn ($b) => [
                    'label' => $names[$b['breakdown'] ?? ''] ?? ($b['meta']['name'] ?? $b['breakdown'] ?? 'Form'),
                    'submissions' => (int) ($b['submissions'] ?? 0),
                    'views' => (int) ($b['formViews'] ?? $b['rawViews'] ?? 0),
                ])
                ->filter(fn ($f) => $f['submissions'] > 0)
                ->sortByDesc('submissions')->take(15)->values()->all(),
            'by_page' => $pages->filter(fn ($p) => $p['submissions'] > 0)->sortByDesc('submissions')->take(10)->values()->all(),
            'key_pages' => collect($fragments)->map(function ($fragment, $label) use ($pages) {
                $matched = $pages->filter(fn ($p) => str_contains(strtolower($p['url']), strtolower($fragment)));
                $views = $matched->sum('views');
                $subs = $matched->sum('submissions');

                return [
                    'label' => $label,
                    'fragment' => $fragment,
                    'views' => $views,
                    'submissions' => $subs,
                    'conversion' => $views ? $subs / $views : null,
                ];
            })->values()->all(),
        ];
    }

    public function revenue(ReportPeriod $period): array
    {
        $props = ['amount', 'amount_in_home_currency', 'hs_analytics_source'];
        $won = [['propertyName' => 'hs_is_closed_won', 'operator' => 'EQ', 'value' => 'true']];
        $created = $this->api->search('deals', $this->between('createdate', $period), $props);
        $closed = $this->api->search('deals', array_merge($this->between('closedate', $period), $won), $props);
        $prevCreated = $this->api->search('deals', $this->between('createdate', $period, true), $props);
        $prevClosed = $this->api->search('deals', array_merge($this->between('closedate', $period, true), $won), $props);

        $amount = fn ($d) => (float) ($d['amount_in_home_currency'] ?? $d['amount'] ?? 0);
        $bySource = fn (array $deals) => collect($deals)->groupBy(fn ($d) => Sources::label($d['hs_analytics_source'] ?? null));
        $createdBy = $bySource($created);
        $closedBy = $bySource($closed);

        return [
            'created' => count($created),
            'created_amount' => collect($created)->sum($amount),
            'won' => count($closed),
            'won_amount' => collect($closed)->sum($amount),
            'prev_created' => count($prevCreated),
            'prev_won' => count($prevClosed),
            'prev_won_amount' => collect($prevClosed)->sum($amount),
            'by_source' => $createdBy->keys()->merge($closedBy->keys())->unique()
                ->map(fn ($label) => [
                    'label' => $label,
                    'created' => $createdBy->get($label, collect())->count(),
                    'created_amount' => $createdBy->get($label, collect())->sum($amount),
                    'won' => $closedBy->get($label, collect())->count(),
                    'won_amount' => $closedBy->get($label, collect())->sum($amount),
                ])
                ->sortByDesc(fn ($r) => [$r['won_amount'], $r['created']])->values()->all(),
        ];
    }

    private function analytics(string $report, ReportPeriod $period, bool $previous = false, array $query = []): array
    {
        [$start, $end] = $previous ? [$period->prevStart, $period->prevEnd] : [$period->start, $period->end];

        return $this->api->analytics($report, $start->format('Ymd'), $end->format('Ymd'), $query);
    }

    /** Page analytics rows: url, views, entrances, submissions. */
    private function pages(ReportPeriod $period, int $limit = 1000)
    {
        $report = $this->analytics('pages/total', $period, false, ['limit' => $limit, 'sort' => 'rawViews']);

        return collect($report['breakdowns'] ?? [])->map(fn ($b) => [
            'url' => (string) ($b['breakdown'] ?? ''),
            'views' => (int) ($b['rawViews'] ?? 0),
            'entrances' => (int) ($b['entrances'] ?? 0),
            'submissions' => (int) ($b['submissions'] ?? 0),
            'bounce_rate' => $b['pageBounceRate'] ?? $b['bounceRate'] ?? null,
        ]);
    }

    private function between(string $property, ReportPeriod $period, bool $previous = false): array
    {
        [$start, $end] = $period->bounds($previous);

        return [[
            'propertyName' => $property,
            'operator' => 'BETWEEN',
            'value' => (string) $start->getTimestampMs(),
            'highValue' => (string) $end->getTimestampMs(),
        ]];
    }

    /** label => url fragment */
    private function keyPages(): array
    {
        $pages = [];
        foreach (array_filter(array_map('trim', explode(';', (string) config('report.key_pages')))) as $pair) {
            [$label, $fragment] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, null);
            if ($fragment) $pages[$label] = $fragment;
        }

        return $pages;
    }
}
