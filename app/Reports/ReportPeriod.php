<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/** A reporting window plus the window it is compared with. */
final class ReportPeriod
{
    public const PRESETS = [
        'mtd' => 'Month to date',
        'last_month' => 'Last month',
        'last_7' => 'Last 7 days',
        'last_28' => 'Last 28 days',
        'last_90' => 'Last 90 days',
    ];

    private function __construct(
        public readonly string $key,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Carbon $prevStart,
        public readonly Carbon $prevEnd,
    ) {}

    public static function fromKey(?string $key, ?Carbon $today = null): self
    {
        $key = array_key_exists((string) $key, self::PRESETS) ? $key : 'mtd';
        $today = ($today ?? now())->copy()->startOfDay();

        switch ($key) {
            case 'last_month':
                $start = $today->copy()->subMonthNoOverflow()->startOfMonth();
                $end = $start->copy()->endOfMonth()->startOfDay();
                $prevStart = $start->copy()->subMonthNoOverflow()->startOfMonth();
                $prevEnd = $prevStart->copy()->endOfMonth()->startOfDay();
                break;
            case 'last_7':
            case 'last_28':
            case 'last_90':
                $days = (int) substr($key, 5);
                $end = $today->copy();
                $start = $end->copy()->subDays($days - 1);
                $prevEnd = $start->copy()->subDay();
                $prevStart = $prevEnd->copy()->subDays($days - 1);
                break;
            default: // month to date vs the same days of last month
                $start = $today->copy()->startOfMonth();
                $end = $today->copy();
                $prevStart = $start->copy()->subMonthNoOverflow();
                $prevEnd = $prevStart->copy()->addDays(min($start->diffInDays($end), $prevStart->daysInMonth - 1));
        }

        return new self($key, $start, $end, $prevStart, $prevEnd);
    }

    public function label(): string
    {
        return self::PRESETS[$this->key];
    }

    public function range(): string
    {
        return self::fmt($this->start, $this->end);
    }

    public function prevRange(): string
    {
        return self::fmt($this->prevStart, $this->prevEnd);
    }

    /** [start, end-of-day] for database queries. */
    public function bounds(bool $previous = false): array
    {
        return $previous
            ? [$this->prevStart->copy(), $this->prevEnd->copy()->endOfDay()]
            : [$this->start->copy(), $this->end->copy()->endOfDay()];
    }

    private static function fmt(Carbon $a, Carbon $b): string
    {
        return $a->isSameDay($b) ? $a->format('M j, Y') : $a->format('M j') . ' – ' . $b->format('M j, Y');
    }
}
