<?php

namespace App\Services;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for HubSpot's AEO API (beta).
 * Docs: https://developers.hubspot.com/docs/api-reference/2027-03-beta/marketing/aeo/guide
 */
class HubSpotAeoClient
{
    private function http(): PendingRequest
    {
        $token = config('hubspot.token');
        if (! $token) {
            throw new RuntimeException('HUBSPOT_TOKEN is not set in .env');
        }

        return Http::baseUrl(rtrim(config('hubspot.base_url'), '/') . '/marketing/aeo/' . config('hubspot.aeo_version'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(60)
            // Back off on rate limits / transient errors
            ->retry(4, function (int $attempt, $e) {
                $retryAfter = optional($e->response ?? null)->header('Retry-After');
                return $retryAfter ? ((int) $retryAfter) * 1000 : 1000 * (2 ** $attempt);
            }, function ($e) {
                $status = optional($e->response ?? null)->status();
                return $status === null || $status === 429 || $status >= 500;
            });
    }

    public function get(string $path, array $query = []): array
    {
        return $this->http()->get($path, $query)->throw()->json() ?? [];
    }

    /** Iterates every item of a list endpoint, following paging.next.after when present. */
    public function paginate(string $path, array $query = []): Generator
    {
        $after = null;
        do {
            $page = $this->get($path, array_filter($query + ['after' => $after]));
            foreach ($page['results'] ?? [] as $item) {
                yield $item;
            }
            $after = data_get($page, 'paging.next.after');
        } while ($after);
    }

    public function prompts(): Generator
    {
        return $this->paginate('/prompts');
    }

    public function prompt(string $id): array
    {
        return $this->get("/prompts/{$id}");
    }

    public function promptRuns(string $promptId): Generator
    {
        return $this->paginate("/prompts/{$promptId}/runs");
    }

    public function run(string $runUuid): array
    {
        return $this->get("/prompt-runs/{$runUuid}");
    }

    public function recommendations(): Generator
    {
        return $this->paginate('/recommendations');
    }
}
