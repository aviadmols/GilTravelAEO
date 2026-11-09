<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Reports\Dashboard;
use App\Reports\ReportPeriod;
use Illuminate\Http\Request;

/** The report without the dashboard login, for the HubSpot dashboard's external content card. */
class EmbedController extends Controller
{
    /**
     * Only HubSpot may frame it; Basic Auth prompts don't work inside its iframe.
     * HubSpot nests the card in a preview.hubspoteditor.com frame inside app.hubspot.com,
     * and frame-ancestors checks every ancestor, so both domains must be listed.
     */
    public const FRAME_ANCESTORS = "frame-ancestors 'self' https://app.hubspot.com https://*.hubspot.com"
        .' https://preview.hubspoteditor.com https://*.hubspoteditor.com';

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
            ->header('Content-Security-Policy', self::FRAME_ANCESTORS)
            ->withoutHeader('X-Frame-Options');
    }
}
