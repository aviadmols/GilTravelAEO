<?php

namespace Tests\Feature;

use App\Http\Controllers\EmbedController;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Logged-out visitor; no HubSpot key, so the sections only show what's missing.
        config(['report.password' => 'secret', 'hubspot.token' => null]);
    }

    private function login(): array
    {
        return ['PHP_AUTH_USER' => config('report.user'), 'PHP_AUTH_PW' => 'secret'];
    }

    public function test_embed_link_opens_the_report_without_login(): void
    {
        Setting::putSecret('embed_token', 'tok123');

        $this->get('/embed/tok123?period=last_month')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', EmbedController::FRAME_ANCESTORS)
            ->assertHeaderMissing('X-Frame-Options')
            ->assertSee('Marketing report')
            ->assertSee('/embed/tok123?period=mtd', false)
            ->assertDontSee('/settings', false);

        $this->get('/')->assertStatus(401);
    }

    public function test_wrong_or_missing_token_is_not_found(): void
    {
        $this->get('/embed/anything')->assertNotFound();

        Setting::putSecret('embed_token', 'tok123');
        $this->get('/embed/tok124')->assertNotFound();
    }

    public function test_settings_create_replace_and_remove_the_link(): void
    {
        $this->post('/settings/embed')->assertStatus(401);

        $this->withServerVariables($this->login())->post('/settings/embed')->assertRedirect('/settings');
        $first = Setting::secret('embed_token');
        $this->assertSame(40, strlen($first));
        $this->withServerVariables($this->login())->get('/settings')->assertSee(route('embed', $first));

        $this->withServerVariables($this->login())->post('/settings/embed');
        $this->get("/embed/{$first}")->assertNotFound();

        $this->withServerVariables($this->login())->post('/settings/embed', ['remove' => 1]);
        $this->assertNull(Setting::get('embed_token'));
    }
}
