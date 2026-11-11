<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KeywordsTest extends TestCase
{
    use RefreshDatabase;

    private const ROWS = [
        ['keyword' => 'yeshiva break panama', 'position' => 3, 'position_prev' => 7, 'url' => 'https://www.giltravel.com/yeshiva-break-panama',
            'volume' => 900, 'traffic' => 210, 'keyword_difficulty' => 12, 'location' => 'United States', 'tags' => ['panama', 'core'],
            'is_branded' => false, 'serp_updated' => '2026-11-09T04:00:00Z'],
        ['keyword' => 'gil travel', 'position' => 1, 'position_prev' => 1, 'url' => 'https://www.giltravel.com/',
            'volume' => 300, 'traffic' => 180, 'keyword_difficulty' => 2, 'location' => 'United States', 'tags' => [],
            'is_branded' => true, 'serp_updated' => '2026-11-09T04:00:00Z'],
        ['keyword' => 'kosher cruises', 'position' => 14, 'position_prev' => 9, 'url' => 'https://www.giltravel.com/cruises',
            'volume' => 1500, 'traffic' => 40, 'keyword_difficulty' => 30, 'location' => 'United States', 'tags' => [],
            'is_branded' => false, 'serp_updated' => '2026-11-09T04:00:00Z'],
        ['keyword' => 'pesach programs', 'position' => null, 'position_prev' => 18, 'url' => null,
            'volume' => 2400, 'traffic' => 0, 'keyword_difficulty' => 25, 'location' => 'United States', 'tags' => [],
            'is_branded' => false, 'serp_updated' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 08:00:00');
        config([
            'report.password' => null,
            'report.ahrefs' => ['key' => 'ahrefs-key', 'project_id' => '10363968', 'device' => 'desktop'],
            'hubspot.token' => 'test-token',
            'hubspot.keywords_sync' => true,
        ]);
    }

    private function fake(array $schemas = []): void
    {
        Http::fake([
            'api.ahrefs.com/*' => Http::response(['overviews' => self::ROWS]),
            'api.hubapi.com/crm/v3/schemas' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['results' => $schemas])
                : Http::response(['objectTypeId' => '2-888', 'name' => 'keyword_ranking'], 201),
            'api.hubapi.com/crm/v3/objects/*/batch/upsert' => Http::response(['status' => 'COMPLETE']),
            '*' => Http::response([], 404),
        ]);
    }

    public function test_seo_section_lists_tracked_keywords_with_their_movement(): void
    {
        $this->fake();

        $this->get('/?period=last_28')->assertOk()
            ->assertSee('Tracked keywords')
            ->assertSee('(4, 2 in the top 10)')
            ->assertSee('yeshiva break panama')
            ->assertSee('▲</span> 4', false)   // 7 -> 3
            ->assertSee('▼</span> 5', false)   // 9 -> 14
            ->assertSee('/yeshiva-break-panama');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.ahrefs.com/v3/rank-tracker/overview')
            && $r->hasHeader('Authorization', 'Bearer ahrefs-key')
            && $r['project_id'] === '10363968' && $r['device'] === 'desktop'
            && $r['date'] === '2026-11-11' && $r['date_compared'] === '2026-10-14'
            && str_contains($r['select'], 'position_prev'));
    }

    public function test_seo_section_explains_a_missing_ahrefs_key(): void
    {
        config(['report.ahrefs.key' => null]);
        $this->fake();

        $this->get('/')->assertOk()->assertSee('AHREFS_API_KEY');
    }

    public function test_first_sync_creates_the_object_and_takes_12_weeks_of_snapshots(): void
    {
        $this->fake();

        $this->artisan('keywords:sync')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/schemas') && $r['name'] === 'keyword_ranking');
        $this->assertCount(13, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.ahrefs.com')));

        $inputs = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/batch/upsert'))->flatMap(fn ($p) => $p[0]['inputs']);
        $panama = $inputs->firstWhere('properties.kw_keyword', 'yeshiva break panama')['properties'];
        $this->assertSame('2026-11-09', $panama['kw_date']); // the Ahrefs check date, not the day asked
        $this->assertSame(3, $panama['kw_position']);
        $this->assertSame(4, $panama['kw_change']);
        $this->assertSame(1, $panama['kw_top10']);
        $this->assertSame('panama, core', $panama['kw_tags']);

        $dropped = $inputs->firstWhere('properties.kw_keyword', 'pesach programs')['properties'];
        $this->assertSame('', $dropped['kw_position']);
        $this->assertSame(0, $dropped['kw_top10']);
        $this->assertNotNull(Setting::get('keywords_sync_last'));
    }

    public function test_daily_sync_takes_only_todays_snapshot(): void
    {
        Setting::put('keywords_sync_last', '2026-11-10T07:00:00+00:00');
        $this->fake([['name' => 'keyword_ranking', 'objectTypeId' => '2-555']]);

        $this->artisan('keywords:sync')->assertSuccessful();

        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'api.ahrefs.com')));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/crm/v3/objects/2-555/batch/upsert') && count($r['inputs']) === 4);
    }
}
