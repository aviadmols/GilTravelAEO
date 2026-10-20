<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['report.password' => null, 'hubspot.token' => null]);
    }

    public function test_saved_key_is_encrypted_and_used(): void
    {
        $this->get('/settings')->assertOk()->assertSee('No key yet');

        $this->post('/settings', ['hubspot_token' => '  pat-na1-secret-1234  '])->assertRedirect('/settings');

        $stored = DB::table('settings')->where('key', 'hubspot_token')->value('value');
        $this->assertStringNotContainsString('pat-na1', $stored);
        $this->assertSame('pat-na1-secret-1234', Setting::secret('hubspot_token'));
        $this->assertSame('1', Setting::get('aeo_sync_requested'));

        (new AppServiceProvider($this->app))->boot();
        $this->assertSame('pat-na1-secret-1234', config('hubspot.token'));

        $this->get('/settings')->assertSee('…1234')->assertDontSee('pat-na1-secret');
    }

    public function test_key_can_be_removed(): void
    {
        Setting::putSecret('hubspot_token', 'pat-old');

        $this->post('/settings', ['remove' => 1])->assertRedirect('/settings');

        $this->assertNull(Setting::get('hubspot_token'));
    }

    public function test_settings_need_the_dashboard_login(): void
    {
        config(['report.password' => 'secret']);

        $this->get('/settings')->assertStatus(401);
        $this->post('/settings', ['hubspot_token' => 'x'])->assertStatus(401);
    }

    public function test_sync_without_key_clears_the_request(): void
    {
        Setting::put('aeo_sync_requested', '1');

        $this->artisan('aeo:sync')->assertSuccessful();

        $this->assertNull(Setting::get('aeo_sync_requested'));
    }
}
