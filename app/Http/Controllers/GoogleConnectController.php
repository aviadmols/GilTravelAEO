<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SearchConsoleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * "Connect with Google" for the SEO section. It starts from a signed link (Settings shows it),
 * so whoever manages Search Console can connect it without the dashboard login. Opening the link,
 * or the Settings page, marks the session as allowed to finish the connection and pick the property.
 */
class GoogleConnectController extends Controller
{
    public function __construct(private SearchConsoleClient $gsc) {}

    public function show(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put(['gsc_connect' => true, 'gsc_state' => $state]);

        return view('google-connect', [
            'ready' => $this->gsc->oauthReady(),
            'authUrl' => $this->gsc->authUrl($state, route('google.callback')),
        ]);
    }

    public function callback(Request $request)
    {
        $this->allowed($request);
        $state = $request->session()->pull('gsc_state');
        abort_unless($state && hash_equals($state, (string) $request->query('state')), 403, 'This connection attempt expired. Open the connect link again.');

        if ($request->filled('error')) {
            return $this->failed('Google access was not granted, so nothing changed.');
        }

        try {
            $token = $this->gsc->exchange((string) $request->query('code'), route('google.callback'));
        } catch (Throwable $e) {
            report($e);

            return $this->failed($e->getMessage());
        }

        if ($old = Setting::secret('gsc_refresh_token')) $this->gsc->revoke($old);
        Setting::putSecret('gsc_refresh_token', $token['refresh_token']);
        Setting::put('gsc_account', $token['email']);
        Setting::put('gsc_site_url', null);
        Cache::flush(); // report sections and the access token belong to the previous connection
        Cache::put('gsc:access-token', $token['access_token'], now()->addSeconds($token['expires_in'] - 300));
        config(['report.gsc.refresh_token' => $token['refresh_token']]);

        // One property is the usual case: pick it so the report works right away.
        $sites = rescue(fn () => $this->gsc->sites(), [], false);
        if (count($sites) === 1) Setting::put('gsc_site_url', $sites[0]);

        return redirect()->route('google.property')->with('status', 'Connected to Google as ' . ($token['email'] ?? 'your account') . '.');
    }

    public function property(Request $request)
    {
        $this->allowed($request);

        $sites = [];
        $error = null;
        if (! config('report.gsc.refresh_token')) {
            $error = 'No Google account is connected yet.';
        } else {
            try {
                $sites = $this->gsc->sites();
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return view('google-property', [
            'sites' => $sites,
            'error' => $error,
            'current' => Setting::get('gsc_site_url'),
            'account' => Setting::get('gsc_account'),
        ]);
    }

    public function saveProperty(Request $request)
    {
        $this->allowed($request);
        $data = $request->validate(['site_url' => ['required', 'string', Rule::in($this->gsc->sites())]]);

        Setting::put('gsc_site_url', $data['site_url']);
        Cache::flush(); // cached SEO sections were built for the previous property

        return redirect()->route('google.property')->with('status', 'Saved. The SEO section now reads ' . $data['site_url'] . '.');
    }

    private function allowed(Request $request): void
    {
        abort_unless($request->session()->get('gsc_connect'), 403, 'Open the connect link from the report Settings first.');
    }

    private function failed(string $message)
    {
        return redirect()->route('google.property')->with('error', $message);
    }
}
