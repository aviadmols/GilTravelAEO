<?php

namespace App\Http\Controllers;

use App\Reports\Dashboard;
use App\Reports\ReportPeriod;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The live sections can take a while (HubSpot, Google, Ahrefs). A browser that would wait gets a
     * light page with a loader first; it fetches the same URL with ?content=1 and swaps the report in.
     * Cached periods, and anything that is not a browser page load (Sec-Fetch-Mode: navigate, also
     * sent for the HubSpot iframe), get the report right away.
     */
    protected function showLoader(Request $request, Dashboard $dashboard, ReportPeriod $period): bool
    {
        return ! $request->boolean('content')
            && $request->header('Sec-Fetch-Mode') === 'navigate'
            && ($request->boolean('refresh') || ! $dashboard->cached($period));
    }
}
