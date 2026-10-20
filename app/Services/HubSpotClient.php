<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HubSpot CRM and analytics calls used by the report (the AEO API has its own client).
 * Scopes: crm.objects.contacts.read, crm.objects.deals.read, forms, business-intelligence.
 */
class HubSpotClient
{
    private function http(): PendingRequest
    {
        $token = config('hubspot.token');
        if (! $token) {
            throw new RuntimeException('No HubSpot key yet. Add it in Settings.');
        }

        return Http::baseUrl(rtrim(config('hubspot.base_url'), '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(60)
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

    public function post(string $path, array $body): array
    {
        return $this->http()->post($path, $body)->throw()->json() ?? [];
    }

    /**
     * Legacy analytics v2 report, e.g. sources/total or pages/total.
     * Dates are YYYYMMDD in the portal's time zone.
     */
    public function analytics(string $report, string $start, string $end, array $query = []): array
    {
        return $this->get("/analytics/v2/reports/{$report}", $query + ['start' => $start, 'end' => $end]);
    }

    /** Number of CRM records matching the filters, without fetching them. */
    public function count(string $object, array $filters): int
    {
        $page = $this->post("/crm/v3/objects/{$object}/search", [
            'filterGroups' => [['filters' => $filters]],
            'limit' => 1,
        ]);

        return (int) ($page['total'] ?? 0);
    }

    /** Every CRM record matching the filters, up to $max (search caps at 10,000). */
    public function search(string $object, array $filters, array $properties, int $max = 5000): array
    {
        $rows = [];
        $after = null;
        do {
            $page = $this->post("/crm/v3/objects/{$object}/search", array_filter([
                'filterGroups' => [['filters' => $filters]],
                'properties' => $properties,
                'limit' => 200,
                'after' => $after,
            ]));
            foreach ($page['results'] ?? [] as $r) {
                $rows[] = $r['properties'] ?? [];
            }
            $after = data_get($page, 'paging.next.after');
        } while ($after && count($rows) < $max);

        return $rows;
    }

    /** Form id => form name. */
    public function formNames(): array
    {
        $names = [];
        $after = null;
        do {
            $page = $this->get('/marketing/v3/forms', array_filter(['limit' => 100, 'after' => $after]));
            foreach ($page['results'] ?? [] as $f) {
                $names[$f['id']] = $f['name'] ?? $f['id'];
            }
            $after = data_get($page, 'paging.next.after');
        } while ($after);

        return $names;
    }
}
