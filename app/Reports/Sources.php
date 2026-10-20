<?php

namespace App\Reports;

/** HubSpot traffic source keys (analytics "organic", CRM "ORGANIC_SEARCH", ...) to one label. */
final class Sources
{
    private const LABELS = [
        'organic' => 'Organic search',
        'organic-search' => 'Organic search',
        'paid' => 'Paid search',
        'paid-search' => 'Paid search',
        'ai-referrals' => 'AI referrals',
        'ai-referral' => 'AI referrals',
        'referrals' => 'Referrals',
        'social' => 'Organic social',
        'social-media' => 'Organic social',
        'paid-social' => 'Paid social',
        'email' => 'Email marketing',
        'email-marketing' => 'Email marketing',
        'direct' => 'Direct traffic',
        'direct-traffic' => 'Direct traffic',
        'other-campaigns' => 'Other campaigns',
        'offline' => 'Offline sources',
    ];

    public static function label(?string $key): string
    {
        if ($key === null || $key === '') return 'Unknown';
        $norm = str_replace('_', '-', strtolower($key));

        return self::LABELS[$norm] ?? ucfirst(str_replace('-', ' ', $norm));
    }
}
