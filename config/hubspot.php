<?php

return [
    // Private App token with the marketing.aeo.read scope
    'token' => env('HUBSPOT_TOKEN'),
    'base_url' => env('HUBSPOT_BASE_URL', 'https://api.hubapi.com'),
    'aeo_version' => env('HUBSPOT_AEO_VERSION', '2027-03-beta'),

    // seo:sync copies Search Console data into the "SEO metrics" custom object (Enterprise only),
    // for HubSpot's custom report builder. Rows = top queries and top pages kept per day.
    'seo_sync' => (bool) env('HUBSPOT_SEO_SYNC', false),
    'seo_sync_rows' => (int) env('HUBSPOT_SEO_SYNC_ROWS', 25),
];
