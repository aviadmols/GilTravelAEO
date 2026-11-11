<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeoSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 08:00:00');
        config([
            'hubspot.token' => 'test-token',
            'hubspot.seo_sync' => true,
            'hubspot.seo_sync_rows' => 25,
            'report.gsc.refresh_token' => 'g-refresh',
            'report.gsc.site_url' => 'sc-domain:giltravel.com',
        ]);
    }

    private function fake(array $schemas = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-access']),
            'searchconsole.googleapis.com/*' => function (Request $r) {
                return Http::response(['rows' => match ($r['dimensions'][0] ?? null) {
                    'date' => collect(Carbon::parse($r['startDate'])->daysUntil($r['endDate']))
                        ->map(fn ($d) => ['keys' => [$d->toDateString()], 'clicks' => 40, 'impressions' => 1000, 'ctr' => 0.04, 'position' => 8.24])
                        ->all(),
                    'query' => [['keys' => ['yeshiva break panama'], 'clicks' => 12, 'impressions' => 90, 'ctr' => 0.1333, 'position' => 2.1]],
                    'page' => [['keys' => ['https://www.giltravel.com/panama'], 'clicks' => 20, 'impressions' => 300, 'ctr' => 0.0667, 'position' => 4]],
                }]);
            },
            'api.hubapi.com/crm/v3/schemas' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['results' => $schemas])
                : Http::response(['objectTypeId' => '2-999', 'name' => 'seo_metric'], 201),
            'api.hubapi.com/crm/v3/objects/*/batch/upsert' => Http::response(['status' => 'COMPLETE']),
        ]);
    }

    public function test_first_run_creates_the_object_and_backfills_90_days(): void
    {
        $this->fake();

        $this->artisan('seo:sync')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/schemas')
            && $r['name'] === 'seo_metric'
            && collect($r['properties'])->firstWhere('name', 'seo_key')['hasUniqueValue'] === true);

        // 90 totals from one GSC call, plus one query and one page per day.
        $inputs = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/crm/v3/objects/2-999/batch/upsert'))
            ->flatMap(fn ($pair) => $pair[0]['inputs']);
        $this->assertCount(270, $inputs);

        $query = $inputs->firstWhere('properties.seo_dimension', 'query');
        $this->assertSame('seo_key', $query['idProperty']);
        $this->assertSame($query['id'], $query['properties']['seo_key']);
        $this->assertSame('yeshiva break panama', $query['properties']['seo_label']);
        $this->assertSame(13.33, $query['properties']['seo_ctr']);

        $total = $inputs->firstWhere('properties.seo_dimension', 'total');
        $this->assertSame('2026-08-13', $total['properties']['seo_date']);
        $this->assertSame(8.2, $total['properties']['seo_position']);

        $this->assertNotNull(Setting::get('seo_sync_last'));
    }

    public function test_later_runs_reuse_the_object_and_sync_five_days(): void
    {
        Setting::put('seo_sync_last', '2026-11-10T06:00:00+00:00');
        $this->fake([['name' => 'seo_metric', 'objectTypeId' => '2-555']]);

        $this->artisan('seo:sync')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/schemas'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'searchAnalytics')
            && $r['dimensions'] === ['date'] && $r['startDate'] === '2026-11-06' && $r['endDate'] === '2026-11-10');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/crm/v3/objects/2-555/batch/upsert') && count($r['inputs']) === 15);
    }

    public function test_it_does_nothing_unless_turned_on(): void
    {
        config(['hubspot.seo_sync' => false]);
        Http::fake();

        $this->artisan('seo:sync')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_missing_scopes_are_explained(): void
    {
        Http::fake(['api.hubapi.com/*' => Http::response(['message' => 'forbidden'], 403)]);

        $this->artisan('seo:sync')
            ->expectsOutputToContain('crm.schemas.custom.write')
            ->assertFailed();
    }
}
