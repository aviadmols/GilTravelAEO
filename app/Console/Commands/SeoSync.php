<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\HubSpotClient;
use App\Services\SearchConsoleClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Copies Search Console data into a HubSpot custom object ("SEO metrics"), so HubSpot's
 * custom report builder can use it. One record per day for the total, and one per day for
 * each top query and top page. Records are upserted by seo_key, so re-running is safe.
 */
class SeoSync extends Command
{
    public const OBJECT = 'seo_metric';

    protected $signature = 'seo:sync {--days= : Days back to sync (default: 5, or 90 on the first run)}';
    protected $description = 'Push Google Search Console data into the "SEO metrics" custom object in HubSpot';

    public function handle(HubSpotClient $hubspot, SearchConsoleClient $gsc): int
    {
        if (! config('hubspot.seo_sync')) {
            $this->warn('The HubSpot SEO sync is off. Set HUBSPOT_SEO_SYNC=true to turn it on.');
            return self::SUCCESS;
        }
        if (! config('hubspot.token') || ! $gsc->configured()) {
            $this->warn('Needs both a HubSpot key and a Search Console connection (Settings).');
            return self::SUCCESS;
        }

        try {
            $objectType = $this->objectType($hubspot);

            // Search Console has no data for today yet, and the last few days keep changing,
            // so every run re-syncs a few days back.
            $days = (int) ($this->option('days') ?: (Setting::get('seo_sync_last') ? 5 : 90));
            $end = Carbon::yesterday();
            $start = $end->copy()->subDays($days - 1);

            $records = $this->records($gsc, $start, $end);
            foreach (array_chunk($records, 100) as $chunk) {
                $hubspot->post("/crm/v3/objects/{$objectType}/batch/upsert", ['inputs' => $chunk]);
            }
        } catch (Throwable $e) {
            $this->error($this->explain($e));
            return self::FAILURE;
        }

        Setting::put('seo_sync_last', now()->toIso8601String());
        $this->info('Synced ' . count($records) . " SEO records ({$start->toDateString()} to {$end->toDateString()}).");

        return self::SUCCESS;
    }

    /** objectTypeId of the custom object (e.g. 2-1234567), created on the first run. */
    private function objectType(HubSpotClient $hubspot): string
    {
        foreach ($hubspot->get('/crm/v3/schemas')['results'] ?? [] as $schema) {
            if (($schema['name'] ?? null) === self::OBJECT) {
                return $schema['objectTypeId'];
            }
        }

        $this->info('Creating the "SEO metrics" object in HubSpot.');
        $prop = fn (string $name, string $label, string $type, string $field, array $extra = []) =>
            ['name' => $name, 'label' => $label, 'type' => $type, 'fieldType' => $field] + $extra;

        return $hubspot->post('/crm/v3/schemas', [
            'name' => self::OBJECT,
            'labels' => ['singular' => 'SEO metric', 'plural' => 'SEO metrics'],
            'primaryDisplayProperty' => 'seo_label',
            'secondaryDisplayProperties' => ['seo_date', 'seo_dimension'],
            'searchableProperties' => ['seo_label'],
            'requiredProperties' => ['seo_key'],
            'properties' => [
                $prop('seo_key', 'Sync key', 'string', 'text', ['hasUniqueValue' => true]),
                $prop('seo_date', 'Date', 'date', 'date'),
                $prop('seo_dimension', 'Type', 'enumeration', 'select', ['options' => [
                    ['label' => 'Total', 'value' => 'total', 'displayOrder' => 0],
                    ['label' => 'Query', 'value' => 'query', 'displayOrder' => 1],
                    ['label' => 'Page', 'value' => 'page', 'displayOrder' => 2],
                ]]),
                $prop('seo_label', 'Query / page', 'string', 'text'),
                $prop('seo_clicks', 'Clicks', 'number', 'number'),
                $prop('seo_impressions', 'Impressions', 'number', 'number'),
                $prop('seo_ctr', 'CTR (%)', 'number', 'number'),
                $prop('seo_position', 'Average position', 'number', 'number'),
            ],
            'associatedObjects' => [],
        ])['objectTypeId'];
    }

    /** Batch-upsert inputs: the daily totals, then the top queries and pages of each day. */
    private function records(SearchConsoleClient $gsc, Carbon $start, Carbon $end): array
    {
        $limit = (int) config('hubspot.seo_sync_rows');
        $records = [];

        foreach ($gsc->query($start->toDateString(), $end->toDateString(), ['date'], 1000) as $row) {
            $records[] = $this->record($row['keys'][0], 'total', 'Total', $row);
        }

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            foreach (['query', 'page'] as $dimension) {
                foreach ($gsc->query($date, $date, [$dimension], $limit) as $row) {
                    $records[] = $this->record($date, $dimension, $row['keys'][0] ?? '', $row);
                }
            }
        }

        return $records;
    }

    private function record(string $date, string $dimension, string $label, array $row): array
    {
        return [
            'idProperty' => 'seo_key',
            // Queries can be long and contain anything, so the key holds a hash of the label.
            'id' => "{$date}:{$dimension}:" . substr(sha1($label), 0, 16),
            'properties' => [
                'seo_key' => "{$date}:{$dimension}:" . substr(sha1($label), 0, 16),
                'seo_date' => $date,
                'seo_dimension' => $dimension,
                'seo_label' => mb_substr($label, 0, 1000),
                'seo_clicks' => (int) ($row['clicks'] ?? 0),
                'seo_impressions' => (int) ($row['impressions'] ?? 0),
                'seo_ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'seo_position' => round($row['position'] ?? 0, 1),
            ],
        ];
    }

    private function explain(Throwable $e): string
    {
        if ($e instanceof RequestException && str_contains($e->response->effectiveUri() ?? '', 'hubapi.com')) {
            return match ($e->response->status()) {
                401 => 'HubSpot rejected the key.',
                403 => 'HubSpot refused: the key needs the crm.schemas.custom.read, crm.schemas.custom.write, '
                    . 'crm.objects.custom.read and crm.objects.custom.write scopes, and custom objects need an Enterprise plan.',
                default => 'HubSpot answered ' . $e->response->status() . ': ' . mb_substr((string) $e->response->json('message', $e->response->body()), 0, 300),
            };
        }

        return $e->getMessage();
    }
}
