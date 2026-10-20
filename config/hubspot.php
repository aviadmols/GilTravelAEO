<?php

return [
    // Private App token with the marketing.aeo.read scope
    'token' => env('HUBSPOT_TOKEN'),
    'base_url' => env('HUBSPOT_BASE_URL', 'https://api.hubapi.com'),
    'aeo_version' => env('HUBSPOT_AEO_VERSION', '2027-03-beta'),
];
