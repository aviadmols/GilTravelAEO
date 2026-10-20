<?php

namespace App\Reports;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds every report section for a period. A failing section reports its own error
 * so the rest of the page still renders.
 */
class Dashboard
{
    /** Section => what it needs, shown when HubSpot refuses the call. */
    private const NEEDS = [
        'traffic' => 'the business-intelligence scope',
        'leads' => 'the crm.objects.contacts.read, forms and business-intelligence scopes',
        'revenue' => 'the crm.objects.deals.read scope',
    ];

    public function __construct(
        private AeoReport $aeo,
        private SeoReport $seo,
        private HubSpotReport $hubspot,
    ) {}

    public function build(ReportPeriod $period, bool $refresh = false): array
    {
        $live = [
            'seo' => fn () => $this->seo->build($period),
            'traffic' => fn () => $this->hubspot->traffic($period),
            'leads' => fn () => $this->hubspot->leads($period),
            'revenue' => fn () => $this->hubspot->revenue($period),
        ];

        $sections = ['aeo' => $this->run('aeo', fn () => $this->aeo->build($period))];
        foreach ($live as $name => $fn) {
            $key = "report:{$name}:{$period->key}:{$period->end->toDateString()}";
            if ($refresh) Cache::forget($key);
            $result = Cache::get($key) ?? $this->run($name, $fn);
            // Errors are not cached, so a fixed token or scope shows up on the next load.
            if ($result['ok']) Cache::put($key, $result, now()->addMinutes(config('report.cache_minutes')));
            $sections[$name] = $result;
        }

        return $sections;
    }

    private function run(string $name, callable $fn): array
    {
        try {
            return ['ok' => true, 'data' => $fn(), 'fetched_at' => now()];
        } catch (Throwable $e) {
            Log::warning("Report section {$name} failed: {$e->getMessage()}");

            return ['ok' => false, 'error' => $this->explain($name, $e)];
        }
    }

    private function explain(string $name, Throwable $e): string
    {
        if ($e instanceof RequestException) {
            return match ($e->response->status()) {
                401 => 'The API key was rejected. Check the key in the Railway variables.',
                403 => 'The key has no access to this data. It needs ' . (self::NEEDS[$name] ?? 'access to this data') . '.',
                default => 'The API answered ' . $e->response->status() . ': ' . mb_substr((string) $e->response->json('message', $e->response->body()), 0, 200),
            };
        }

        return $e->getMessage();
    }
}
