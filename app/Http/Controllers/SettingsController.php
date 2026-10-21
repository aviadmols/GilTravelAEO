<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SearchConsoleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

class SettingsController extends Controller
{
    public function edit(Request $request, SearchConsoleClient $gsc)
    {
        $saved = Setting::find('hubspot_token');
        $gscConnected = filled(Setting::get('gsc_refresh_token'));
        // Lets the dashboard user open the Search Console property picker (see GoogleConnectController).
        $request->session()->put('gsc_connect', true);

        return view('settings', [
            'saved' => $saved,
            'savedTail' => $saved ? substr((string) Setting::secret('hubspot_token'), -4) : null,
            'fromEnv' => ! $saved && filled(env('HUBSPOT_TOKEN')),
            'gsc' => [
                'ready' => $gsc->oauthReady(),
                'connected' => $gscConnected,
                'account' => Setting::get('gsc_account'),
                'site' => Setting::get('gsc_site_url'),
                'fromEnv' => ! $gscConnected && filled(env('GOOGLE_SERVICE_ACCOUNT_JSON')),
                'connectLink' => URL::temporarySignedRoute('google.connect', now()->addDays(7)),
                'callbackUrl' => route('google.callback'),
            ],
        ]);
    }

    public function disconnectGoogle(SearchConsoleClient $gsc)
    {
        if ($token = Setting::secret('gsc_refresh_token')) $gsc->revoke($token);
        foreach (['gsc_refresh_token', 'gsc_account', 'gsc_site_url'] as $key) Setting::put($key, null);
        Cache::flush();

        return redirect()->route('settings')->with('status', 'Google Search Console was disconnected.');
    }

    public function update(Request $request)
    {
        if ($request->boolean('remove')) {
            Setting::putSecret('hubspot_token', null);
            $message = 'The HubSpot key was removed.';
        } else {
            $data = $request->validate(['hubspot_token' => ['required', 'string', 'max:500']]);
            Setting::putSecret('hubspot_token', trim($data['hubspot_token']));
            // The scheduler picks this up within a minute, so AEO data does not wait for 05:30.
            Setting::put('aeo_sync_requested', '1');
            $message = 'Saved. AI visibility data starts syncing within a minute; the other sections use the key right away.';
        }
        Cache::flush(); // cached report sections were built with the old key

        return redirect()->route('settings')->with('status', $message);
    }
}
