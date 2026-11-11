<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

// HubSpot AEO runs prompts roughly weekly; a daily sync keeps the DB current.
Schedule::command('aeo:sync')->dailyAt('05:30')->timezone('Asia/Jerusalem')->withoutOverlapping();

// Search Console data into HubSpot's "SEO metrics" object (skips itself unless HUBSPOT_SEO_SYNC=true).
Schedule::command('seo:sync')->dailyAt('06:00')->timezone('Asia/Jerusalem')->withoutOverlapping();

// AEO brand mentions into HubSpot's "AEO metrics" object, after the 05:30 pull (skips itself unless HUBSPOT_AEO_SYNC=true).
Schedule::command('aeo:push')->dailyAt('06:30')->timezone('Asia/Jerusalem')->withoutOverlapping();

// Ahrefs keyword rankings into HubSpot's "Keyword rankings" object (skips itself unless HUBSPOT_KEYWORDS_SYNC=true).
Schedule::command('keywords:sync')->dailyAt('07:00')->timezone('Asia/Jerusalem')->withoutOverlapping();

// A key saved in Settings asks for a sync right away instead of waiting for the morning.
Schedule::command('aeo:sync')->everyMinute()->withoutOverlapping()
    ->when(fn () => rescue(fn () => Setting::get('aeo_sync_requested') === '1', false, false));
