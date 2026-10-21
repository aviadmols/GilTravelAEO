<?php

namespace Tests\Feature;

use App\Models\AeoCitation;
use App\Models\AeoPrompt;
use App\Models\AeoPromptRun;
use App\Reports\AeoReport;
use App\Reports\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        config([
            'report.password' => null,
            'report.own_brand' => 'Gil Travel|Gil Travel Group',
            'report.competitors' => 'Kesher Tours;Yeshiva Breaks Inc',
            'hubspot.token' => 'test-token',
        ]);
    }

    private function seedAeo(): void
    {
        AeoPrompt::create(['id' => 'p1', 'prompt' => 'Best yeshiva break trips', 'buying_journey_phase' => 'AWARENESS']);
        AeoPrompt::create(['id' => 'p2', 'prompt' => 'Kosher travel to Panama', 'buying_journey_phase' => 'CONSIDERATION']);

        $runs = [
            // this month
            ['p1', 'CHATGPT', '2026-10-05', 'Top picks: Gil Travel Group and Kesher Tours.', 3, 1],
            ['p1', 'GEMINI', '2026-10-06', 'Kesher Tours is popular.', 2, 0],
            ['p2', 'CHATGPT', '2026-10-07', 'Try Gil Travel for Panama.', 4, 2],
            ['p2', 'PERPLEXITY', '2026-10-08', 'Yeshiva Breaks Inc and Kesher Tours.', 1, 0],
            // same days last month
            ['p1', 'CHATGPT', '2026-09-05', 'Kesher Tours.', 1, 0],
            ['p2', 'GEMINI', '2026-09-06', 'Gil Travel.', 1, 1],
        ];
        foreach ($runs as [$prompt, $model, $date, $text, $citations, $owned]) {
            $run = AeoPromptRun::create([
                'id' => (string) Str::uuid(), 'prompt_id' => $prompt, 'state' => 'COMPLETED', 'ai_model' => $model,
                'completed_at' => $date . ' 09:00:00', 'total_citations' => $citations, 'owned_mentions' => $owned,
                'response_text' => $text, 'details_fetched' => true,
            ]);
            AeoCitation::create(['run_id' => $run->id, 'url' => 'https://www.giltravel.com/panama', 'domain' => 'giltravel.com', 'title' => 'Panama trips']);
        }
    }

    private function fakeHubSpot(): void
    {
        Http::fake([
            'api.hubapi.com/analytics/v2/reports/sources/*' => Http::response([
                'totals' => ['visits' => 1200, 'rawViews' => 3400],
                'breakdowns' => [
                    ['breakdown' => 'organic', 'visits' => 600, 'contacts' => 12],
                    ['breakdown' => 'direct', 'visits' => 400, 'contacts' => 5],
                    ['breakdown' => 'ai-referrals', 'visits' => 50, 'contacts' => 2],
                ],
            ]),
            'api.hubapi.com/analytics/v2/reports/pages/*' => Http::response(['breakdowns' => [
                ['breakdown' => 'https://giltravel.com/', 'rawViews' => 900, 'entrances' => 500, 'submissions' => 4],
                ['breakdown' => 'https://giltravel.com/yeshiva-break-panama', 'rawViews' => 300, 'entrances' => 250, 'submissions' => 15],
            ]]),
            'api.hubapi.com/analytics/v2/reports/forms/*' => Http::response([
                'totals' => ['submissions' => 19],
                'breakdowns' => [['breakdown' => 'form-1', 'submissions' => 19]],
            ]),
            'api.hubapi.com/marketing/v3/forms*' => Http::response(['results' => [['id' => 'form-1', 'name' => 'Panama signup']]]),
            'api.hubapi.com/crm/v3/objects/contacts/search' => Http::response([
                'total' => 3,
                'results' => [
                    ['properties' => ['hs_analytics_source' => 'ORGANIC_SEARCH']],
                    ['properties' => ['hs_analytics_source' => 'ORGANIC_SEARCH']],
                    ['properties' => ['hs_analytics_source' => 'AI_REFERRALS']],
                ],
            ]),
            'api.hubapi.com/crm/v3/objects/deals/search' => Http::response([
                'total' => 2,
                'results' => [
                    ['properties' => ['amount' => '5000', 'hs_analytics_source' => 'ORGANIC_SEARCH']],
                    ['properties' => ['amount' => '2500', 'hs_analytics_source' => 'DIRECT_TRAFFIC']],
                ],
            ]),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-token']),
            'searchconsole.googleapis.com/*' => function (Request $r) {
                $dims = $r->data()['dimensions'] ?? [];

                return Http::response(['rows' => $dims
                    ? [['keys' => [$dims[0] === 'query' ? 'yeshiva break trips' : 'https://giltravel.com/panama'], 'clicks' => 40, 'impressions' => 900, 'ctr' => 0.044, 'position' => 6.2]]
                    : [['clicks' => 210, 'impressions' => 8000, 'ctr' => 0.026, 'position' => 11.4]]]);
            },
        ]);
    }

    public function test_aeo_metrics_are_measured_from_answer_text(): void
    {
        $this->seedAeo();
        $a = app(AeoReport::class)->build(ReportPeriod::fromKey('mtd'));

        $this->assertSame(4, $a['runs']);
        $this->assertEqualsWithDelta(0.5, $a['own']['mention_rate'], 0.001);   // 2 of 4 answers
        $this->assertEqualsWithDelta(2 / 6, $a['own']['share_of_voice'], 0.001); // 2 of 6 brand mentions
        $this->assertSame(2, $a['own']['rank']);                                 // Kesher Tours leads with 3
        $this->assertEqualsWithDelta(0.5, $a['prev_own']['mention_rate'], 0.001);
        $this->assertSame(3, $a['citations']['owned']);
        $this->assertSame('giltravel.com', $a['top_domains'][0]['label']);
        $this->assertSame(['ChatGPT', 'Gemini', 'Perplexity'], collect($a['engines'])->pluck('engine')->sort()->values()->all());
    }

    public function test_full_report_renders(): void
    {
        config(['report.gsc.site_url' => 'sc-domain:giltravel.com', 'report.gsc.credentials' => '{}']);
        Cache::put('gsc:access-token', 'g-token', 60); // skips signing the Google token request
        $this->seedAeo();
        $this->fakeHubSpot();

        $html = $this->get('/')->assertOk()
            ->assertSee('Brand mention rate')
            ->assertSee('Kesher Tours')
            ->assertSee('yeshiva break trips')       // GSC query
            ->assertSee('AI referrals')
            ->assertSee('Panama signup')             // form name lookup
            ->assertSee('Yeshiva Break Panama conversion')
            ->assertSee('5.0%')                      // 15 submissions / 300 views
            ->assertSee('$7,500')                    // closed-won revenue
            ->assertDontSee('Not available yet')
            ->getContent();

        if ($out = env('REPORT_HTML_OUT')) file_put_contents($out, $html);
    }

    public function test_prompt_table_shows_20_and_reveals_the_rest(): void
    {
        config(['hubspot.token' => null]);
        foreach (range(1, 25) as $n) {
            AeoPrompt::create(['id' => "p{$n}", 'prompt' => "Prompt number {$n}"]);
            AeoPromptRun::create([
                'id' => (string) Str::uuid(), 'prompt_id' => "p{$n}", 'state' => 'COMPLETED', 'ai_model' => 'CHATGPT',
                'completed_at' => now()->startOfMonth()->addHours(9), 'total_citations' => 0, 'owned_mentions' => 0,
                'response_text' => 'Gil Travel', 'details_fetched' => true,
            ]);
        }

        $html = $this->get('/')->assertOk()->assertSee('Show 5 more (5 left)')->getContent();

        $this->assertSame(5, preg_match_all('/<tr\s+hidden/', $html));

        if ($out = env('REPORT_HTML_OUT')) file_put_contents($out, $html);
    }

    public function test_sections_fail_independently(): void
    {
        config(['hubspot.token' => null]);
        $this->seedAeo();

        $this->get('/')->assertOk()
            ->assertSee('Brand mention rate')
            ->assertSee('No HubSpot key yet')
            ->assertSee('Search Console is not connected');
    }

    public function test_missing_scope_is_explained(): void
    {
        Http::fake(['api.hubapi.com/*' => Http::response(['message' => 'forbidden'], 403)]);

        $this->get('/')->assertOk()->assertSee('crm.objects.deals.read');
    }

    public function test_password_protects_the_dashboard(): void
    {
        config(['report.password' => 'secret', 'hubspot.token' => null]);

        $this->get('/')->assertStatus(401);
        $this->get('/', ['PHP_AUTH_USER' => 'gil', 'PHP_AUTH_PW' => 'secret'])->assertOk();
    }

    public function test_month_to_date_compares_with_same_days_last_month(): void
    {
        $p = ReportPeriod::fromKey('mtd', Carbon::parse('2026-10-20'));

        $this->assertSame('2026-10-01', $p->start->toDateString());
        $this->assertSame('2026-10-20', $p->end->toDateString());
        $this->assertSame('2026-09-01', $p->prevStart->toDateString());
        $this->assertSame('2026-09-20', $p->prevEnd->toDateString());

        $march = ReportPeriod::fromKey('mtd', Carbon::parse('2026-03-31'));
        $this->assertSame('2026-02-28', $march->prevEnd->toDateString());
    }
}
