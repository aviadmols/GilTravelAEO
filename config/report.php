<?php

return [
    // Basic-auth login for the dashboard. When the password is empty the dashboard is open.
    'user' => env('DASHBOARD_USER', 'gil'),
    'password' => env('DASHBOARD_PASSWORD'),

    // Live HubSpot / Search Console answers are cached this many minutes per period.
    'cache_minutes' => (int) env('REPORT_CACHE_MINUTES', 60),

    // Brands matched in AI answers. Brands are separated by ";", aliases by "|".
    // The first name is the one shown in the report.
    'own_brand' => env('AEO_OWN_BRAND', 'Gil Travel|Gil Travel Group'),
    'competitors' => env('AEO_COMPETITORS', ''),

    // Landing pages called out in the leads section: "Label=url-fragment;Label=url-fragment".
    'key_pages' => env('REPORT_KEY_PAGES', 'Yeshiva Break Panama=yeshiva-break-panama'),

    // Google Search Console. HubSpot's API does not expose the GSC data it shows in its UI,
    // so the SEO section reads it from Google with a service account added to the property.
    'gsc' => [
        'site_url' => env('GSC_SITE_URL'),                   // e.g. sc-domain:giltravel.com
        'credentials' => env('GOOGLE_SERVICE_ACCOUNT_JSON'), // the key file contents, raw or base64
    ],
];
