<?php

namespace App\Reports;

use App\Models\AeoPrompt;
use App\Models\AeoPromptRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AI search visibility from the synced HubSpot AEO runs.
 * HubSpot's API returns only citation counts per run, so brand mentions,
 * share of voice and rank are measured here by finding each tracked brand in the answer text.
 */
class AeoReport
{
    public const ENGINES = [
        'CHATGPT' => 'ChatGPT',
        'GEMINI' => 'Gemini',
        'PERPLEXITY' => 'Perplexity',
        'CLAUDE' => 'Claude',
        'COPILOT' => 'Copilot',
        'AI_OVERVIEWS' => 'Google AI Overviews',
    ];

    public function build(ReportPeriod $period): array
    {
        $brands = Brands::all();
        $runs = $this->runs($period->bounds());
        $prevRuns = $this->runs($period->bounds(true));
        $now = $this->measure($runs, $brands);
        $prev = $this->measure($prevRuns, $brands);

        foreach ($now['brands'] as $i => &$b) {
            $b['prev_mention_rate'] = $prev['brands'][$i]['mention_rate'];
            $b['prev_rank'] = $prev['brands'][$i]['rank'];
        }
        unset($b);

        return [
            'synced_at' => AeoPromptRun::max('updated_at'),
            'brands_configured' => count($brands),
            'runs' => $runs->count(),
            'analyzed_runs' => $now['analyzed'],
            'own' => collect($now['brands'])->firstWhere('own', true),
            'prev_own' => collect($prev['brands'])->firstWhere('own', true),
            'brands' => $now['brands'],
            'engines' => $this->engines($runs, $brands),
            'citations' => [
                'total' => (int) $runs->sum('total_citations'),
                'owned' => (int) $runs->sum('owned_mentions'),
                'competitor' => (int) $runs->sum('competitor_mentions'),
                'prev_total' => (int) $prevRuns->sum('total_citations'),
                'prev_owned' => (int) $prevRuns->sum('owned_mentions'),
            ],
            'top_domains' => $this->topCitations($runs, 'domain'),
            'top_pages' => $this->topCitations($runs, 'url'),
            'prompts' => $this->prompts($runs, $prevRuns, $brands),
        ];
    }

    private function runs(array $bounds): Collection
    {
        return AeoPromptRun::query()
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', $bounds)
            ->get(['id', 'prompt_id', 'ai_model', 'total_citations', 'owned_mentions', 'competitor_mentions', 'response_text'])
            ->each(fn ($r) => $r->setAttribute('engine', self::ENGINES[strtoupper((string) $r->ai_model)] ?? ($r->ai_model ?: 'Unknown')));
    }

    /** Mention rate, share of voice and rank per brand over runs that have answer text. */
    private function measure(Collection $runs, array $brands): array
    {
        $analyzed = $runs->filter(fn ($r) => filled($r->response_text));
        $hits = array_map(fn ($b) => $analyzed->filter(fn ($r) => preg_match($b['pattern'], $r->response_text))->count(), $brands);
        $allHits = array_sum($hits);

        $rows = [];
        foreach ($brands as $i => $b) {
            $rows[] = [
                'name' => $b['name'],
                'own' => $b['own'],
                'mentions' => $hits[$i],
                'mention_rate' => $analyzed->count() ? $hits[$i] / $analyzed->count() : null,
                'share_of_voice' => $allHits ? $hits[$i] / $allHits : null,
            ];
        }
        // Rank by mentions; ties share a rank.
        $sorted = collect($rows)->pluck('mentions')->sortDesc()->values();
        foreach ($rows as &$row) {
            $row['rank'] = $analyzed->count() ? $sorted->search($row['mentions']) + 1 : null;
        }
        unset($row);

        return ['analyzed' => $analyzed->count(), 'brands' => $rows];
    }

    private function engines(Collection $runs, array $brands): array
    {
        return $runs->groupBy('engine')->map(function (Collection $group, string $engine) use ($brands) {
            $own = collect($this->measure($group, $brands)['brands'])->firstWhere('own', true);

            return [
                'engine' => $engine,
                'runs' => $group->count(),
                'mention_rate' => $own['mention_rate'] ?? null,
                'share_of_voice' => $own['share_of_voice'] ?? null,
                'citations' => (int) $group->sum('total_citations'),
                'owned_citations' => (int) $group->sum('owned_mentions'),
            ];
        })->sortByDesc('runs')->values()->all();
    }

    private function topCitations(Collection $runs, string $column, int $limit = 10): array
    {
        if ($runs->isEmpty()) return [];

        return DB::table('aeo_citations')
            ->whereIn('run_id', $runs->pluck('id'))
            ->selectRaw("{$column} as label, MAX(title) as title, COUNT(*) as citations, COUNT(DISTINCT run_id) as runs")
            ->groupBy($column)
            ->orderByDesc('citations')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function prompts(Collection $runs, Collection $prevRuns, array $brands): array
    {
        $prevByPrompt = $prevRuns->groupBy('prompt_id');
        $ownPattern = collect($brands)->firstWhere('own', true)['pattern'] ?? null;

        return AeoPrompt::query()->orderBy('prompt')->get(['id', 'prompt', 'buying_journey_phase'])
            ->map(function ($p) use ($runs, $prevByPrompt, $brands, $ownPattern) {
                $mine = $runs->where('prompt_id', $p->id);
                $measured = collect($this->measure($mine, $brands)['brands']);
                $own = $measured->firstWhere('own', true);
                $prevOwn = collect($this->measure($prevByPrompt->get($p->id, collect()), $brands)['brands'])->firstWhere('own', true);
                $leader = $measured->sortByDesc('mentions')->first();

                return [
                    'prompt' => $p->prompt,
                    'phase' => $p->buying_journey_phase,
                    'runs' => $mine->count(),
                    'mention_rate' => $own['mention_rate'] ?? null,
                    'prev_mention_rate' => $prevOwn['mention_rate'] ?? null,
                    'share_of_voice' => $own['share_of_voice'] ?? null,
                    'rank' => $own['rank'] ?? null,
                    'leader' => ($leader['mentions'] ?? 0) > 0 ? $leader['name'] : null,
                    'citations' => (int) $mine->sum('total_citations'),
                    'engines_mentioning' => $mine->filter(fn ($r) => $ownPattern && filled($r->response_text) && preg_match($ownPattern, $r->response_text))
                        ->pluck('engine')->unique()->values()->all(),
                ];
            })
            ->sortByDesc(fn ($p) => [$p['mention_rate'] ?? -1, $p['runs']])
            ->values()->all();
    }
}
