<?php

use Illuminate\Support\Facades\Schedule;

// HubSpot AEO runs prompts roughly weekly; a daily sync keeps the DB current.
Schedule::command('aeo:sync')->dailyAt('05:30')->timezone('Asia/Jerusalem')->withoutOverlapping();
