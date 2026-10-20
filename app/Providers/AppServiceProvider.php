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
    }
}
