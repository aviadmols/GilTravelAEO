<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\AppServiceProvider;
use App\Services\SearchConsoleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GoogleConnectTest extends TestCase
{
    use RefreshDatabase;

    private const SCOPE = 'openid https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/userinfo.email';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'report.password' => 'secret',
            'hubspot.token' => null,
            'report.gsc' => ['site_url' => null, 'credentials' => null, 'client_id' => 'cid', 'client_secret' => 'csecret', 'refresh_token' => null],
        ]);
    }

    private function fakeGoogle(array $sites, string $scope = self::SCOPE): void
    {
        $claims = rtrim(strtr(base64_encode(json_encode(['email' => 'seo@agency.com'])), '+/', '-_'), '=');
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'g-access', 'expires_in' => 3599, 'refresh_token' => 'g-refresh',
                'scope' => $scope, 'id_token' => "header.{$claims}.sig",
            ]),
            'oauth2.googleapis.com/revoke' => Http::response(),
            'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => $sites]),
        ]);
    }

    /** Opens the signed link like the SEO manager would (no dashboard login) and returns the OAuth state. */
    private function openConnectLink(): string
    {
        $this->get(URL::temporarySignedRoute('google.connect', now()->addDays(7)))
            ->assertOk()->assertSee('accounts.google.com/o/oauth2/v2/auth', false);

        return session('gsc_state');
    }

    public function test_connect_link_needs_a_valid_signature(): void
    {
        $this->get('/google/connect')->assertStatus(403);
        $this->get('/google/property')->assertStatus(403);
    }

    public function test_seo_manager_connects_from_the_link_and_the_only_property_is_picked(): void
    {
        $this->fakeGoogle([
            ['siteUrl' => 'sc-domain:giltravel.com', 'permissionLevel' => 'siteFullUser'],
            ['siteUrl' => 'https://other.example/', 'permissionLevel' => 'siteUnverifiedUser'],
        ]);
        $state = $this->openConnectLink();

        $this->get("/google/callback?code=abc&state={$state}")->assertRedirect('/google/property');

        $this->assertStringNotContainsString('g-refresh', DB::table('settings')->where('key', 'gsc_refresh_token')->value('value'));
        $this->assertSame('g-refresh', Setting::secret('gsc_refresh_token'));
        $this->assertSame('seo@agency.com', Setting::get('gsc_account'));
        $this->assertSame('sc-domain:giltravel.com', Setting::get('gsc_site_url'));

        $this->get('/google/property')->assertOk()->assertSee('seo@agency.com')->assertSee('reads');

        (new AppServiceProvider($this->app))->boot();
        $this->assertTrue(app(SearchConsoleClient::class)->configured());
    }

    public function test_property_is_chosen_from_the_accounts_list(): void
    {
        $this->fakeGoogle([
            ['siteUrl' => 'sc-domain:giltravel.com', 'permissionLevel' => 'siteOwner'],
            ['siteUrl' => 'https://www.giltravel.com/', 'permissionLevel' => 'siteRestrictedUser'],
        ]);
        $state = $this->openConnectLink();
        $this->get("/google/callback?code=abc&state={$state}");
        $this->assertNull(Setting::get('gsc_site_url'));

        $this->post('/google/property', ['site_url' => 'https://not-theirs.com/'])->assertSessionHasErrors('site_url');
        $this->post('/google/property', ['site_url' => 'https://www.giltravel.com/'])->assertRedirect('/google/property');

        $this->assertSame('https://www.giltravel.com/', Setting::get('gsc_site_url'));
    }

    public function test_callback_rejects_a_wrong_state(): void
    {
        $this->fakeGoogle([]);
        $this->openConnectLink();

        $this->get('/google/callback?code=abc&state=forged')->assertStatus(403);
        $this->assertNull(Setting::get('gsc_refresh_token'));
    }

    public function test_unticked_search_console_box_is_explained(): void
    {
        $this->fakeGoogle([], 'openid https://www.googleapis.com/auth/userinfo.email');
        $state = $this->openConnectLink();

        $this->get("/google/callback?code=abc&state={$state}")
            ->assertRedirect('/google/property')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not ticked'));
        $this->assertNull(Setting::get('gsc_refresh_token'));
    }

    public function test_connected_account_reads_search_console_with_a_refreshed_token(): void
    {
        config(['report.gsc.refresh_token' => 'g-refresh', 'report.gsc.site_url' => 'sc-domain:giltravel.com']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-access']),
            'searchconsole.googleapis.com/*' => Http::response(['rows' => [['clicks' => 5]]]),
        ]);

        $rows = app(SearchConsoleClient::class)->query('2026-10-01', '2026-10-20');

        $this->assertSame(5, $rows[0]['clicks']);
        Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['refresh_token'] === 'g-refresh');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'searchAnalytics') && $r->hasHeader('Authorization', 'Bearer fresh-access'));
    }

    public function test_revoked_connection_is_explained_in_the_report(): void
    {
        Setting::putSecret('gsc_refresh_token', 'g-refresh');
        Setting::put('gsc_site_url', 'sc-domain:giltravel.com');
        (new AppServiceProvider($this->app))->boot();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400), 'api.hubapi.com/*' => Http::response([], 401)]);

        $this->get('/', $this->login())->assertOk()->assertSee('Connect it again in Settings');
    }

    public function test_settings_show_the_link_and_disconnect_clears_the_connection(): void
    {
        $this->get('/settings', $this->login())->assertOk()
            ->assertSee('Not connected yet')
            ->assertSee('Link for the SEO manager')
            ->assertSee('/google/connect?expires=', false);

        Setting::putSecret('gsc_refresh_token', 'g-refresh');
        Setting::put('gsc_account', 'seo@agency.com');
        Setting::put('gsc_site_url', 'sc-domain:giltravel.com');
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response()]);

        $this->get('/settings', $this->login())->assertSee('seo@agency.com')->assertSee('sc-domain:giltravel.com');
        $this->post('/settings/google/disconnect', [], $this->login())->assertRedirect('/settings');

        $this->assertNull(Setting::get('gsc_refresh_token'));
        $this->assertNull(Setting::get('gsc_site_url'));
        Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/revoke');
    }

    public function test_settings_explain_the_oauth_client_when_it_is_missing(): void
    {
        config(['report.gsc.client_id' => null]);

        $this->get('/settings', $this->login())->assertOk()
            ->assertSee('GOOGLE_CLIENT_ID')
            ->assertSee(route('google.callback'));
    }

    private function login(): array
    {
        return ['PHP_AUTH_USER' => 'gil', 'PHP_AUTH_PW' => 'secret'];
    }
}
