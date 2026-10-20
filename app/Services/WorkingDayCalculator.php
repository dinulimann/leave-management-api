<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

class WorkingDayCalculator
{
    /**
     * Counts Monday–Friday dates between $start and $end (inclusive) that are
     * not public holidays.
     */
    public function count(CarbonImmutable $start, CarbonImmutable $end): int
    {
        if ($end->lt($start)) {
            return 0;
        }

        $holidays = Holiday::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->flip();

        $days = 0;
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (! $date->isWeekend() && ! $holidays->has($date->toDateString())) {
                $days++;
            }
        }

        return $days;
    }
}
