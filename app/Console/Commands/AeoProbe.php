<?php

namespace App\Console\Commands;

use App\Services\HubSpotAeoClient;
use Illuminate\Console\Command;

/**
 * Prints raw API answers so you can see the real response shapes
 * (the docs leave the `visibility` object undocumented).
 */
class AeoProbe extends Command
{
    protected $signature = 'aeo:probe';
    protected $description = 'Call each HubSpot AEO endpoint once and print the raw JSON';

    public function handle(HubSpotAeoClient $api): int
    {
        $prompts = $api->get('/prompts');
        $this->dump('GET /prompts (first page)', array_merge($prompts, ['results' => array_slice($prompts['results'] ?? [], 0, 2)]));

        $first = $prompts['results'][0]['id'] ?? null;
        if (! $first) {
            $this->warn('No prompts returned.');
            return self::SUCCESS;
        }

        $this->dump("GET /prompts/{$first}", $api->prompt($first));

        $runs = $api->get("/prompts/{$first}/runs");
        $this->dump("GET /prompts/{$first}/runs", array_merge($runs, ['results' => array_slice($runs['results'] ?? [], 0, 2)]));

        if ($runId = $runs['results'][0]['id'] ?? null) {
            $run = $api->run($runId);
            $run['responseText'] = mb_substr($run['responseText'] ?? '', 0, 300) . '…';
            $this->dump("GET /prompt-runs/{$runId}", $run);
        }

        $recs = $api->get('/recommendations');
        $this->dump('GET /recommendations', array_merge($recs, ['results' => array_slice($recs['results'] ?? [], 0, 1)]));

        return self::SUCCESS;
    }

    private function dump(string $title, array $data): void
    {
        $this->info("\n=== {$title} ===");
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
