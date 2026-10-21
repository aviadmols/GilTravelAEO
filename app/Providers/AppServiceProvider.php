<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A HubSpot key saved in Settings wins over the HUBSPOT_TOKEN variable.
        // rescue(): the settings table does not exist yet before the first migrate.
        if ($token = rescue(fn () => Setting::secret('hubspot_token'), null, false)) {
            config(['hubspot.token' => $token]);
        }

        // Likewise a Google account connected in Settings wins over the service-account variables.
        if ($refresh = rescue(fn () => Setting::secret('gsc_refresh_token'), null, false)) {
            config(['report.gsc.refresh_token' => $refresh, 'report.gsc.site_url' => Setting::get('gsc_site_url')]);
        }
    }
}
