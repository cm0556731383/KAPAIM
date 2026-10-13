<?php

namespace Tests\Unit;

use App\Services\HebrewCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HebrewCalendarTest extends TestCase
{
    public function test_year_in_letters(): void
    {
        $this->assertSame('תשפ״ז', HebrewCalendar::yearInLetters(5787));
        $this->assertSame('תשט״ו', HebrewCalendar::yearInLetters(5715));
        $this->assertSame('תשט״ז', HebrewCalendar::yearInLetters(5716));
        $this->assertSame('תש״ע', HebrewCalendar::yearInLetters(5770));
    }

    public function test_cycle_start_picks_the_running_cycle_or_the_next_one(): void
    {
        // Month 1 = חשוון; 10 months run חשוון–תמוז in leap year תשפ״ז.
        $this->assertSame('2026-10-12', HebrewCalendar::cycleStartFor(CarbonImmutable::parse('2026-09-01'), 1, 10)->toDateString());
        $this->assertSame('2026-10-12', HebrewCalendar::cycleStartFor(CarbonImmutable::parse('2027-05-01'), 1, 10)->toDateString());
        // Past the cycle's end (אב תשפ״ז) — the next cycle, חשוון תשפ״ח.
        $this->assertSame('חשוון תשפ״ח', HebrewCalendar::monthLabel(HebrewCalendar::cycleStartFor(CarbonImmutable::parse('2027-08-20'), 1, 10)));
    }

    public function test_months_count_both_adars_only_in_a_leap_year(): void
    {
        $leap = HebrewCalendar::cycleStartFor(CarbonImmutable::parse('2026-09-20'), 0, 10); // תשרי תשפ״ז
        $common = HebrewCalendar::cycleStartFor(CarbonImmutable::parse('2027-10-05'), 0, 10); // תשרי תשפ״ח

        $this->assertSame('אדר א׳ תשפ״ז', HebrewCalendar::monthLabel(HebrewCalendar::addMonths($leap, 5)));
        $this->assertSame('אדר ב׳ תשפ״ז', HebrewCalendar::monthLabel(HebrewCalendar::addMonths($leap, 6)));
        $this->assertSame('אדר תשפ״ח', HebrewCalendar::monthLabel(HebrewCalendar::addMonths($common, 5)));
        $this->assertSame('ניסן תשפ״ח', HebrewCalendar::monthLabel(HebrewCalendar::addMonths($common, 6)));
    }
}
