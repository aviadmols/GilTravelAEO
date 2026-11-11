<?php

namespace App\Console\Commands;

use App\Models\AeoPrompt;
use App\Models\AeoPromptRun;
use App\Models\Setting;
use App\Reports\AeoReport;
use App\Reports\Brands;
use App\Services\HubSpotObjects;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies the AEO measurements of the report into a HubSpot custom object ("AEO metrics").
 * HubSpot has the AI answers but not the brand matching done here, so each record is one
 * AI answer x one tracked brand, with Mentioned = 1 or 0. In HubSpot reports:
 *  - mention rate    = average of Mentioned (own brand)
 *  - share of voice  = sum of Mentioned, split by brand
 *  - citations       = sum of Citations (filled on the own-brand record only, so it is not counted twice)
 */
class AeoPush extends Command
{
    public const OBJECT = 'aeo_metric';

    protected $signature = 'aeo:push {--days= : Days of AI answers to push (default: 14, or all of them on the first run)}';
    protected $description = 'Push AEO brand mentions per AI answer into the "AEO metrics" custom object in HubSpot';

    public function handle(HubSpotObjects $objects): int
    {
        if (! config('hubspot.aeo_sync')) {
            $this->warn('The HubSpot AEO sync is off. Set HUBSPOT_AEO_SYNC=true to turn it on.');
            return self::SUCCESS;
        }
        if (! config('hubspot.token')) {
            $this->warn('No HubSpot key yet. Add it in Settings.');
            return self::SUCCESS;
        }
        if (! $brands = Brands::all()) {
            $this->warn('No brands to track. Set AEO_OWN_BRAND.');
            return self::SUCCESS;
        }

        $days = $this->option('days') ?: (Setting::get('aeo_push_last') ? 14 : null);
        $prompts = AeoPrompt::query()->get(['id', 'prompt', 'buying_journey_phase'])->keyBy('id');
        $answers = 0;

        try {
            $objectType = $objects->ensure($this->schema(), fn () => $this->info('Creating the "AEO metrics" object in HubSpot.'));

            $records = [];
            AeoPromptRun::query()
                ->whereNotNull('completed_at')
                ->whereNotNull('response_text')
                ->when($days, fn ($q) => $q->where('completed_at', '>=', now()->subDays((int) $days)->startOfDay()))
                ->select(['id', 'prompt_id', 'ai_model', 'completed_at', 'total_citations', 'owned_mentions', 'response_text'])
                ->lazyById(200)
                ->each(function (AeoPromptRun $run) use ($brands, $prompts, $objectType, $objects, &$records, &$answers) {
                    $answers++;
                    foreach ($this->records($run, $brands, $prompts->get($run->prompt_id)) as $record) {
                        $records[] = $record;
                    }
                    if (count($records) >= 500) {
                        $objects->upsert($objectType, $records);
                        $records = [];
                    }
                });
            $objects->upsert($objectType, $records);
        } catch (Throwable $e) {
            $this->error(HubSpotObjects::explain($e));
            return self::FAILURE;
        }

        Setting::put('aeo_push_last', now()->toIso8601String());
        $this->info("Pushed {$answers} AI answers x " . count($brands) . ' brands to HubSpot' . ($days ? " (last {$days} days)." : ' (all).'));

        return self::SUCCESS;
    }

    private function schema(): array
    {
        $prop = HubSpotObjects::prop(...);

        return [
            'name' => self::OBJECT,
            'labels' => ['singular' => 'AEO metric', 'plural' => 'AEO metrics'],
            'primaryDisplayProperty' => 'aeo_brand',
            'secondaryDisplayProperties' => ['aeo_date', 'aeo_engine'],
            'searchableProperties' => ['aeo_brand', 'aeo_prompt'],
            'requiredProperties' => ['aeo_key'],
            'properties' => [
                $prop('aeo_key', 'Sync key', 'string', 'text', ['hasUniqueValue' => true]),
                $prop('aeo_date', 'Date', 'date', 'date'),
                $prop('aeo_engine', 'AI assistant', 'string', 'text'),
                $prop('aeo_prompt', 'Prompt', 'string', 'text'),
                $prop('aeo_stage', 'Stage', 'string', 'text'),
                $prop('aeo_brand', 'Brand', 'string', 'text'),
                $prop('aeo_own', 'Own brand', 'enumeration', 'select', ['options' => HubSpotObjects::options(['yes' => 'Yes', 'no' => 'No'])]),
                $prop('aeo_mentioned', 'Mentioned (1 = yes)', 'number', 'number'),
                $prop('aeo_citations', 'Citations', 'number', 'number'),
                $prop('aeo_owned_citations', 'Citations of our pages', 'number', 'number'),
            ],
        ];
    }

    /** One record per tracked brand for this AI answer. */
    private function records(AeoPromptRun $run, array $brands, ?AeoPrompt $prompt): array
    {
        $date = Carbon::parse($run->completed_at)->timezone(config('app.timezone'))->toDateString();
        $engine = AeoReport::ENGINES[strtoupper((string) $run->ai_model)] ?? ($run->ai_model ?: 'Unknown');
        $stage = $prompt?->buying_journey_phase ? Str::headline(strtolower($prompt->buying_journey_phase)) : '';

        return array_map(fn (array $brand) => HubSpotObjects::record('aeo_key', $run->id . ':' . substr(sha1($brand['name']), 0, 12), [
            'aeo_date' => $date,
            'aeo_engine' => $engine,
            'aeo_prompt' => mb_substr((string) $prompt?->prompt, 0, 1000),
            'aeo_stage' => $stage,
            'aeo_brand' => $brand['name'],
            'aeo_own' => $brand['own'] ? 'yes' : 'no',
            'aeo_mentioned' => preg_match($brand['pattern'], $run->response_text) ? 1 : 0,
            'aeo_citations' => $brand['own'] ? (int) $run->total_citations : 0,
            'aeo_owned_citations' => $brand['own'] ? (int) $run->owned_mentions : 0,
        ]), $brands);
    }
}
