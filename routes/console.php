<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

// HubSpot AEO runs prompts roughly weekly; a daily sync keeps the DB current.
Schedule::command('aeo:sync')->dailyAt('05:30')->timezone('Asia/Jerusalem')->withoutOverlapping();

// A key saved in Settings asks for a sync right away instead of waiting for the morning.
Schedule::command('aeo:sync')->everyMinute()->withoutOverlapping()
    ->when(fn () => rescue(fn () => Setting::get('aeo_sync_requested') === '1', false, false));
