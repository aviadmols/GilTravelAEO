<?php

namespace App\Console\Commands;

use App\Models\AeoCitation;
use App\Models\AeoPrompt;
use App\Models\AeoPromptRun;
use App\Models\AeoRecommendation;
use App\Services\HubSpotAeoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class AeoSync extends Command
{
    protected $signature = 'aeo:sync {--skip-details : Do not fetch run details (citations, response text)}';
    protected $description = 'Pull prompts, runs, citations and recommendations from HubSpot AEO into MySQL';

    public function handle(HubSpotAeoClient $api): int
    {
        $prompts = $runs = $details = $recs = 0;

        foreach ($api->prompts() as $p) {
            $id = (string) $p['id'];
            $full = $this->safe(fn () => $api->prompt($id)) ?? $p;

            AeoPrompt::updateOrCreate(['id' => $id], [
                'prompt'               => $p['prompt'] ?? '',
                'language'             => $p['language'] ?? null,
                'buying_journey_phase' => $p['buyingJourneyPhase'] ?? null,
                'ai_assistants'        => json_encode($p['aiAssistants'] ?? []),
                'business_unit_id'     => isset($p['businessUnitId']) ? (string) $p['businessUnitId'] : null,
                'visibility'           => json_encode($full['visibility'] ?? null),
                'hs_created_at'        => $this->ts($p['createdAt'] ?? null),
                'raw'                  => $full,
            ]);
            $prompts++;

            foreach ($api->promptRuns($id) as $r) {
                $run = AeoPromptRun::updateOrCreate(['id' => $r['id']], [
                    'prompt_id'           => $id,
                    'state'               => $r['state'] ?? null,
                    'ai_model'            => $r['aiModel'] ?? null,
                    'completed_at'        => $this->ts($r['completedAt'] ?? null),
                    'total_citations'     => (int) ($r['totalCitations'] ?? 0),
                    'owned_mentions'      => (int) ($r['ownedMentions'] ?? 0),
                    'competitor_mentions' => (int) ($r['competitorMentions'] ?? 0),
                    'hs_created_at'       => $this->ts($r['createdAt'] ?? null),
                    'raw'                 => $r,
                ]);
                $runs++;

                // Run details are immutable once completed: fetch each run only once.
                if (! $this->option('skip-details') && ! $run->details_fetched && $run->completed_at) {
                    if ($d = $this->safe(fn () => $api->run($r['id']))) {
                        DB::transaction(function () use ($run, $d) {
                            $run->update([
                                'response_text'   => $d['responseText'] ?? null,
                                'search_queries'  => json_encode($d['searchQueries'] ?? []),
                                'details_fetched' => true,
                            ]);
                            AeoCitation::where('run_id', $run->id)->delete();
                            foreach ($d['citations'] ?? [] as $c) {
                                if (empty($c['url'])) continue;
                                AeoCitation::create([
                                    'run_id' => $run->id,
                                    'url'    => $c['url'],
                                    'domain' => $this->domain($c['url']),
                                    'title'  => $c['title'] ?? null,
                                ]);
                            }
                        });
                        $details++;
                    }
                }
            }
        }

        foreach ($api->recommendations() as $r) {
            AeoRecommendation::updateOrCreate(['id' => (string) $r['id']], [
                'prompt_ids'             => json_encode($r['promptIds'] ?? []),
                'answer_engine'          => $r['answerEngine'] ?? null,
                'recommendation_type'    => $r['recommendationType'] ?? null,
                'action_category'        => $r['actionCategory'] ?? null,
                'action_channel'         => $r['actionChannel'] ?? null,
                'content_type'           => $r['contentType'] ?? null,
                'content_topic'          => $r['contentTopic'] ?? null,
                'domain'                 => $r['domain'] ?? null,
                'url'                    => $r['url'] ?? null,
                'priority'               => $r['priority'] ?? null,
                'status'                 => $r['status'] ?? null,
                'recommendation_summary' => $r['recommendationSummary'] ?? null,
                'justification'          => is_array($r['justification'] ?? null) ? json_encode($r['justification']) : ($r['justification'] ?? null),
                'associated_content_url' => $r['associatedContentUrl'] ?? null,
                'start_date'             => $this->ts($r['startDate'] ?? null)?->toDateString(),
                'end_date'               => $this->ts($r['endDate'] ?? null)?->toDateString(),
                'hs_created_at'          => $this->ts($r['createdAt'] ?? null),
                'raw'                    => $r,
            ]);
            $recs++;
        }

        $this->info("Synced {$prompts} prompts, {$runs} runs ({$details} new details), {$recs} recommendations.");
        return self::SUCCESS;
    }

    private function safe(callable $fn): ?array
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->warn($e->getMessage());
            return null;
        }
    }

    /** Accepts ISO strings or epoch milliseconds. */
    private function ts(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? Carbon::createFromTimestampMs((int) $v) : Carbon::parse($v);
    }

    private function domain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return preg_replace('/^www\./i', '', strtolower($host));
    }
}
