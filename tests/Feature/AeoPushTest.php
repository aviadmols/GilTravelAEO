<?php

namespace Tests\Feature;

use App\Models\AeoPrompt;
use App\Models\AeoPromptRun;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AeoPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 08:00:00');
        config([
            'hubspot.token' => 'test-token',
            'hubspot.aeo_sync' => true,
            'report.own_brand' => 'Gil Travel|Gil Travel Group',
            'report.competitors' => 'Kesher Tours',
        ]);

        AeoPrompt::create(['id' => 'p1', 'prompt' => 'Best yeshiva break trips', 'buying_journey_phase' => 'AWARENESS']);
        $runs = [
            ['r1', 'CHATGPT', '2026-11-05 09:00:00', 'Top picks: Gil Travel Group and Kesher Tours.', 3, 1],
            ['r2', 'GEMINI', '2026-11-06 09:00:00', 'Kesher Tours is popular.', 2, 0],
            ['r3', 'PERPLEXITY', '2026-09-01 09:00:00', 'Gil Travel runs Panama trips.', 4, 2], // older than 14 days
            ['r4', 'CHATGPT', '2026-11-07 09:00:00', null, 1, 0], // no answer text: not measured
        ];
        foreach ($runs as [$id, $model, $completed, $text, $citations, $owned]) {
            AeoPromptRun::create([
                'id' => $id, 'prompt_id' => 'p1', 'ai_model' => $model, 'completed_at' => $completed,
                'response_text' => $text, 'total_citations' => $citations, 'owned_mentions' => $owned,
            ]);
        }
    }

    private function fake(array $schemas = []): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/schemas' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['results' => $schemas])
                : Http::response(['objectTypeId' => '2-777', 'name' => 'aeo_metric'], 201),
            'api.hubapi.com/crm/v3/objects/*/batch/upsert' => Http::response(['status' => 'COMPLETE']),
        ]);
    }

    private function inputs()
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/batch/upsert'))
            ->flatMap(fn ($pair) => $pair[0]['inputs']);
    }

    public function test_first_run_creates_the_object_and_pushes_every_answer_per_brand(): void
    {
        $this->fake();

        $this->artisan('aeo:push')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/schemas') && $r['name'] === 'aeo_metric'
            // HubSpot refuses select options that share a display order.
            && array_column(collect($r['properties'])->firstWhere('name', 'aeo_own')['options'], 'displayOrder') === [0, 1]);

        // 3 answers with text x 2 brands.
        $inputs = $this->inputs();
        $this->assertCount(6, $inputs);

        $r1 = $inputs->filter(fn ($i) => str_starts_with($i['id'], 'r1:'))->keyBy('properties.aeo_brand');
        $this->assertSame(1, $r1['Gil Travel']['properties']['aeo_mentioned']);
        $this->assertSame('yes', $r1['Gil Travel']['properties']['aeo_own']);
        $this->assertSame(3, $r1['Gil Travel']['properties']['aeo_citations']);
        $this->assertSame(1, $r1['Kesher Tours']['properties']['aeo_mentioned']);
        $this->assertSame(0, $r1['Kesher Tours']['properties']['aeo_citations']);
        $this->assertSame('ChatGPT', $r1['Gil Travel']['properties']['aeo_engine']);
        $this->assertSame('Awareness', $r1['Gil Travel']['properties']['aeo_stage']);
        $this->assertSame('2026-11-05', $r1['Gil Travel']['properties']['aeo_date']);

        $r2own = $inputs->first(fn ($i) => str_starts_with($i['id'], 'r2:') && $i['properties']['aeo_own'] === 'yes');
        $this->assertSame(0, $r2own['properties']['aeo_mentioned']);

        $this->assertNotNull(Setting::get('aeo_push_last'));
    }

    public function test_later_runs_push_the_last_14_days_into_the_existing_object(): void
    {
        Setting::put('aeo_push_last', '2026-11-10T06:30:00+00:00');
        $this->fake([['name' => 'aeo_metric', 'objectTypeId' => '2-555']]);

        $this->artisan('aeo:push')->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/schemas'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/crm/v3/objects/2-555/batch/upsert'));
        $this->assertCount(4, $this->inputs()); // r1 and r2 only
    }

    public function test_an_existing_object_gets_the_properties_it_is_missing(): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/schemas' => Http::response(['results' => [[
                'name' => 'aeo_metric', 'objectTypeId' => '2-555',
                'properties' => [['name' => 'aeo_key'], ['name' => 'aeo_date'], ['name' => 'aeo_engine'], ['name' => 'aeo_prompt'],
                    ['name' => 'aeo_stage'], ['name' => 'aeo_brand'], ['name' => 'aeo_mentioned'], ['name' => 'aeo_citations'], ['name' => 'aeo_owned_citations']],
            ]]]),
            'api.hubapi.com/crm/v3/properties/2-555/groups' => Http::response(['results' => [['name' => 'aeo_metric_information']]]),
            'api.hubapi.com/crm/v3/properties/2-555' => Http::response(['name' => 'aeo_own'], 201),
            'api.hubapi.com/crm/v3/objects/*/batch/upsert' => Http::response(['status' => 'COMPLETE']),
        ]);

        $this->artisan('aeo:push')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/crm/v3/properties/2-555')
            && $r['name'] === 'aeo_own' && $r['groupName'] === 'aeo_metric_information');
        Http::assertSentCount(4); // schemas, groups, the one missing property, one upsert
    }

    public function test_it_does_nothing_unless_turned_on(): void
    {
        config(['hubspot.aeo_sync' => false]);
        Http::fake();

        $this->artisan('aeo:push')->assertSuccessful();

        Http::assertNothingSent();
    }
}
