<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ahrefs Rank Tracker (API v3). The overview endpoint is free: it uses no API units.
 * Needs an Ahrefs API key and the id of the Rank Tracker project (in the project's URL).
 */
class AhrefsClient
{
    public const FIELDS = [
        'keyword', 'position', 'position_prev', 'url', 'volume', 'traffic', 'traffic_prev',
        'keyword_difficulty', 'location', 'country', 'tags', 'is_branded', 'serp_updated',
    ];

    public function configured(): bool
    {
        return filled(config('report.ahrefs.key')) && filled(config('report.ahrefs.project_id'));
    }

    /**
     * Tracked keywords as ranked on $date, with positions on $compared as position_prev.
     * Rows are sorted by traffic, highest first.
     */
    public function keywords(string $date, ?string $compared = null, int $limit = 1000): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Ahrefs is not connected. Set AHREFS_API_KEY (and AHREFS_PROJECT_ID) in Railway.');
        }

        return Http::baseUrl('https://api.ahrefs.com/v3')
            ->withToken(config('report.ahrefs.key'))
            ->acceptJson()
            ->timeout(60)
            ->retry(3, 2000, fn ($e) => in_array(optional($e->response ?? null)->status(), [null, 429, 500, 502, 503], true), throw: false)
            ->get('/rank-tracker/overview', array_filter([
                'project_id' => config('report.ahrefs.project_id'),
                'device' => config('report.ahrefs.device'),
                'date' => $date,
                'date_compared' => $compared,
                'select' => implode(',', self::FIELDS),
                'order_by' => 'traffic:desc',
                'limit' => $limit,
            ]))
            ->throw()
            ->json('overviews') ?? [];
    }
}
