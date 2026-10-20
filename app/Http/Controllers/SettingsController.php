<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingsController extends Controller
{
    public function edit()
    {
        $saved = Setting::find('hubspot_token');

        return view('settings', [
            'saved' => $saved,
            'savedTail' => $saved ? substr((string) Setting::secret('hubspot_token'), -4) : null,
            'fromEnv' => ! $saved && filled(env('HUBSPOT_TOKEN')),
        ]);
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
