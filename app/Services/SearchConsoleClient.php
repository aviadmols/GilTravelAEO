<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Search Console searchAnalytics.query (no Google SDK needed). Signs in either with a Google
 * account connected in Settings (OAuth refresh token) or with a service account whose email was
 * added as a user on the Search Console property. The connected account wins.
 */
class SearchConsoleClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API = 'https://searchconsole.googleapis.com/webmasters/v3';

    public function configured(): bool
    {
        return config('report.gsc.site_url') && (config('report.gsc.refresh_token') || config('report.gsc.credentials'));
    }

    /** Rows of clicks / impressions / ctr / position, grouped by $dimensions (none = one total row). */
    public function query(string $start, string $end, array $dimensions = [], int $limit = 10): array
    {
        $site = rawurlencode(config('report.gsc.site_url'));

        return Http::withToken($this->accessToken())
            ->timeout(60)
            ->post(self::API . "/sites/{$site}/searchAnalytics/query", array_filter([
                'startDate' => $start,
                'endDate' => $end,
                'dimensions' => $dimensions,
                'rowLimit' => $limit,
                'dataState' => 'all',
            ]))
            ->throw()
            ->json('rows') ?? [];
    }

    /** Properties the signed-in account can read, e.g. ["sc-domain:giltravel.com"]. */
    public function sites(): array
    {
        $entries = Http::withToken($this->accessToken())->get(self::API . '/sites')->throw()->json('siteEntry') ?? [];

        return collect($entries)
            ->reject(fn ($s) => ($s['permissionLevel'] ?? '') === 'siteUnverifiedUser')
            ->pluck('siteUrl')->sort()->values()->all();
    }

    // ---------- "Connect with Google" (OAuth) ----------

    public function oauthReady(): bool
    {
        return filled(config('report.gsc.client_id')) && filled(config('report.gsc.client_secret'));
    }

    public function authUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config('report.gsc.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email ' . self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent', // always hand out a refresh token, also on a reconnect
            'state' => $state,
        ]);
    }

    /** Trades the code from Google's redirect for tokens: refresh_token, access_token, expires_in, email. */
    public function exchange(string $code, string $redirectUri): array
    {
        $token = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('report.gsc.client_id'),
            'client_secret' => config('report.gsc.client_secret'),
            'redirect_uri' => $redirectUri,
        ])->throw()->json();

        // Google lets people untick single permissions on its consent screen.
        if (! in_array(self::SCOPE, explode(' ', $token['scope'] ?? ''), true)) {
            throw new RuntimeException('Search Console access was not ticked on Google\'s screen. Connect again and leave the Search Console box checked.');
        }
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google did not return a lasting connection. Connect again.');
        }

        // The id_token came straight from Google over TLS, so its payload is read without checking the signature.
        $idToken = explode('.', $token['id_token'] ?? '');
        $claims = json_decode((string) base64_decode(strtr($idToken[1] ?? '', '-_', '+/')), true);

        return [
            'refresh_token' => $token['refresh_token'],
            'access_token' => $token['access_token'],
            'expires_in' => (int) ($token['expires_in'] ?? 3600),
            'email' => $claims['email'] ?? null,
        ];
    }

    public function revoke(string $token): void
    {
        rescue(fn () => Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]), null, false);
    }

    // ---------- access tokens ----------

    private function accessToken(): string
    {
        return Cache::remember('gsc:access-token', now()->addMinutes(50), fn () => config('report.gsc.refresh_token')
            ? $this->refreshedToken()
            : $this->serviceAccountToken());
    }

    private function refreshedToken(): string
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => config('report.gsc.refresh_token'),
            'client_id' => config('report.gsc.client_id'),
            'client_secret' => config('report.gsc.client_secret'),
        ]);
        if ($response->json('error') === 'invalid_grant') {
            throw new RuntimeException('Google no longer accepts the Search Console connection (it was revoked or expired). Connect it again in Settings.');
        }

        return $response->throw()->json('access_token');
    }

    private function serviceAccountToken(): string
    {
        $key = $this->credentials();
        $now = time();
        $segments = [
            $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->b64(json_encode([
                'iss' => $key['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];
        if (! openssl_sign(implode('.', $segments), $signature, $key['private_key'], 'sha256WithRSAEncryption')) {
            throw new RuntimeException('Could not sign the Google token request');
        }
        $segments[] = $this->b64($signature);

        return Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => implode('.', $segments),
        ])->throw()->json('access_token');
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
