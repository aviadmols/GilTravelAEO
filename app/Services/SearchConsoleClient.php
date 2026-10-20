<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Search Console searchAnalytics.query with a service account (no Google SDK needed).
 * The service account's email must be added as a user on the Search Console property.
 */
class SearchConsoleClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function configured(): bool
    {
        return config('report.gsc.site_url') && config('report.gsc.credentials');
    }

    /** Rows of clicks / impressions / ctr / position, grouped by $dimensions (none = one total row). */
    public function query(string $start, string $end, array $dimensions = [], int $limit = 10): array
    {
        $site = rawurlencode(config('report.gsc.site_url'));

        return Http::withToken($this->accessToken())
            ->timeout(60)
            ->post("https://searchconsole.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query", array_filter([
                'startDate' => $start,
                'endDate' => $end,
                'dimensions' => $dimensions,
                'rowLimit' => $limit,
                'dataState' => 'all',
            ]))
            ->throw()
            ->json('rows') ?? [];
    }

    private function accessToken(): string
    {
        return Cache::remember('gsc:access-token', now()->addMinutes(50), function () {
            $key = $this->credentials();
            $now = time();
            $segments = [
                $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->b64(json_encode([
                    'iss' => $key['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $key['private_key'], 'sha256WithRSAEncryption')) {
                throw new RuntimeException('Could not sign the Google token request');
            }
            $segments[] = $this->b64($signature);

            return Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private function credentials(): array
    {
        $raw = trim((string) config('report.gsc.credentials'));
        $json = str_starts_with($raw, '{') ? $raw : base64_decode($raw, true);
        $key = json_decode((string) $json, true);
        if (empty($key['client_email']) || empty($key['private_key'])) {
            throw new RuntimeException('GOOGLE_SERVICE_ACCOUNT_JSON is not a valid service-account key');
        }

        return $key;
    }

    private function b64(string $v): string
    {
        return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    }
}
