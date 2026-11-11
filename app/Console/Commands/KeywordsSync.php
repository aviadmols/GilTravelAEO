<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Reports\KeywordsReport;
use App\Services\AhrefsClient;
use App\Services\HubSpotObjects;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Copies the Ahrefs Rank Tracker keywords into a HubSpot custom object ("Keyword rankings").
 * One record per keyword per Ahrefs ranking check (serp_updated), so a keyword's position can be
 * charted over time. The first run also takes weekly snapshots of the last 12 weeks.
 */
class KeywordsSync extends Command
{
    public const OBJECT = 'keyword_ranking';

    protected $signature = 'keywords:sync {--weeks= : Weekly snapshots to take back in time (default: 0, or 12 on the first run)}';
    protected $description = 'Push Ahrefs Rank Tracker keywords into the "Keyword rankings" custom object in HubSpot';

    public function handle(HubSpotObjects $objects, AhrefsClient $ahrefs): int
    {
        if (! config('hubspot.keywords_sync')) {
            $this->warn('The HubSpot keywords sync is off. Set HUBSPOT_KEYWORDS_SYNC=true to turn it on.');
            return self::SUCCESS;
        }
        if (! config('hubspot.token') || ! $ahrefs->configured()) {
            $this->warn('Needs both a HubSpot key and an Ahrefs key (AHREFS_API_KEY, AHREFS_PROJECT_ID).');
            return self::SUCCESS;
        }

        $weeks = (int) ($this->option('weeks') ?? (Setting::get('keywords_sync_last') ? 0 : 12));
        $count = 0;

        try {
            $objectType = $objects->ensure($this->schema(), fn () => $this->info('Creating the "Keyword rankings" object in HubSpot.'));

            for ($w = $weeks; $w >= 0; $w--) {
                $date = Carbon::today()->subWeeks($w);
                $rows = $ahrefs->keywords($date->toDateString(), $date->copy()->subWeek()->toDateString());
                $records = array_map(fn (array $r) => $this->record($r, $date), $rows);
                $objects->upsert($objectType, $records);
                $count += count($records);
            }
        } catch (Throwable $e) {
            $this->error(HubSpotObjects::explain($e));
            return self::FAILURE;
        }

        Setting::put('keywords_sync_last', now()->toIso8601String());
        $this->info("Synced {$count} keyword rankings" . ($weeks ? " ({$weeks} weeks back)." : '.'));

        return self::SUCCESS;
    }

    private function schema(): array
    {
        $prop = HubSpotObjects::prop(...);

        return [
            'name' => self::OBJECT,
            'labels' => ['singular' => 'Keyword ranking', 'plural' => 'Keyword rankings'],
            'primaryDisplayProperty' => 'kw_keyword',
            'secondaryDisplayProperties' => ['kw_date', 'kw_position'],
            'searchableProperties' => ['kw_keyword'],
            'requiredProperties' => ['kw_key'],
            'properties' => [
                $prop('kw_key', 'Sync key', 'string', 'text', ['hasUniqueValue' => true]),
                $prop('kw_date', 'Date', 'date', 'date'),
                $prop('kw_keyword', 'Keyword', 'string', 'text'),
                $prop('kw_position', 'Position', 'number', 'number'),
                $prop('kw_position_prev', 'Position a week earlier', 'number', 'number'),
                $prop('kw_change', 'Places gained', 'number', 'number'),
                $prop('kw_top10', 'In top 10 (1 = yes)', 'number', 'number'),
                $prop('kw_volume', 'Search volume', 'number', 'number'),
                $prop('kw_traffic', 'Traffic', 'number', 'number'),
                $prop('kw_difficulty', 'Keyword difficulty', 'number', 'number'),
                $prop('kw_url', 'Ranking page', 'string', 'text'),
                $prop('kw_location', 'Location', 'string', 'text'),
                $prop('kw_device', 'Device', 'string', 'text'),
                $prop('kw_tags', 'Tags', 'string', 'text'),
                $prop('kw_branded', 'Branded', 'enumeration', 'select', ['options' => HubSpotObjects::options(['yes' => 'Yes', 'no' => 'No'])]),
            ],
        ];
    }

    private function record(array $r, Carbon $asked): array
    {
        // The date Ahrefs last checked the ranking, so daily runs between weekly checks update one record.
        $date = isset($r['serp_updated']) ? Carbon::parse($r['serp_updated'])->toDateString() : $asked->toDateString();
        $device = (string) config('report.ahrefs.device');
        $keyword = (string) ($r['keyword'] ?? '');
        $position = $r['position'] ?? null;

        $properties = [
            'kw_date' => $date,
            'kw_keyword' => mb_substr($keyword, 0, 1000),
            'kw_position' => $position,
            'kw_position_prev' => $r['position_prev'] ?? null,
            'kw_change' => KeywordsReport::change($position, $r['position_prev'] ?? null),
            'kw_top10' => $position !== null && $position <= 10 ? 1 : 0,
            'kw_volume' => $r['volume'] ?? null,
            'kw_traffic' => $r['traffic'] ?? null,
            'kw_difficulty' => $r['keyword_difficulty'] ?? null,
            'kw_url' => $r['url'] ?? null,
            'kw_location' => $r['location'] ?? null,
            'kw_device' => $device,
            'kw_tags' => implode(', ', $r['tags'] ?? []),
            'kw_branded' => ($r['is_branded'] ?? false) ? 'yes' : 'no',
        ];

        // Empty values clear a HubSpot property, so a keyword that dropped out shows no position.
        $properties = array_map(fn ($v) => $v ?? '', $properties);
        $key = $date . ':' . substr(sha1($keyword . '|' . ($r['location'] ?? '') . '|' . $device), 0, 16);

        return HubSpotObjects::record('kw_key', $key, $properties);
    }
}
