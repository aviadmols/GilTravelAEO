<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Reports\Dashboard;
use App\Reports\ReportPeriod;
use Illuminate\Http\Request;

/** The report without the dashboard login, for the HubSpot dashboard's external content card. */
class EmbedController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard, string $token)
    {
        $saved = (string) Setting::secret('embed_token');
        abort_unless($saved !== '' && hash_equals($saved, $token), 404);

        $period = ReportPeriod::fromKey($request->query('period'));

        return response()
            ->view('report', [
                'period' => $period,
                'sections' => $dashboard->build($period),
                'embedToken' => $token,
            ])
            // Only HubSpot may frame it; Basic Auth prompts don't work inside its iframe.
            ->header('Content-Security-Policy', "frame-ancestors 'self' https://*.hubspot.com");
    }
}
