<?php

namespace Tests\Unit;

use App\Models\Holiday;
use App\Services\WorkingDayCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkingDayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function workingDays(string $start, string $end): int
    {
        return (new WorkingDayCalculator)->count(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    public function test_counts_weekdays_only(): void
    {
        // Mon 2 Nov 2026 .. Sun 8 Nov 2026
        $this->assertSame(5, $this->workingDays('2026-11-02', '2026-11-08'));
    }

    public function test_skips_public_holidays(): void
    {
        Holiday::create(['date' => '2026-11-04', 'name' => 'Test holiday']);

        $this->assertSame(4, $this->workingDays('2026-11-02', '2026-11-06'));
    }

    public function test_weekend_only_range_has_no_working_days(): void
    {
        $this->assertSame(0, $this->workingDays('2026-11-07', '2026-11-08'));
    }

    public function test_reversed_range_is_zero(): void
    {
        $this->assertSame(0, $this->workingDays('2026-11-06', '2026-11-02'));
    }
}
