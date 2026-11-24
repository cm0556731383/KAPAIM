<?php

namespace App\Services;

use App\Models\AppSetting;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlCalendar;
use IntlDateFormatter;

/**
 * Subscription months run by the Hebrew calendar (owner's call, 2026-10-14):
 * month 1 is a Hebrew month chosen on the settings page, and each following
 * month is the next Hebrew month — a leap year's אדר א׳ and אדר ב׳ count as
 * two months. Built on ICU's Hebrew calendar (ext-intl; there's no
 * ext-calendar in crm_app), whose month field is 0 = תשרי … 12 = אלול, with
 * 5 = אדר א׳ existing only in a leap year (in a common year ICU normalises 5
 * to 6, plain אדר — which is why "אדר" is offered as 5 below).
 */
class HebrewCalendar
{
    public const START_MONTH_SETTING = 'subscription_start_hebrew_month';

    public const DEFAULT_START_MONTH = 0;

    /** Values for the settings page's month select (ICU month index => name). */
    public const MONTHS = [
        0 => 'תשרי', 1 => 'חשוון', 2 => 'כסלו', 3 => 'טבת', 4 => 'שבט', 5 => 'אדר',
        7 => 'ניסן', 8 => 'אייר', 9 => 'סיוון', 10 => 'תמוז', 11 => 'אב', 12 => 'אלול',
    ];

    private const TIMEZONE = 'Asia/Jerusalem';

    /** תמוז: a start month from here on opens the school year in the summer before it. */
    private const SUMMER_FIRST_MONTH = 10;

    public static function startMonth(): int
    {
        $month = (int) AppSetting::get(self::START_MONTH_SETTING, (string) self::DEFAULT_START_MONTH);

        return array_key_exists($month, self::MONTHS) ? $month : self::DEFAULT_START_MONTH;
    }

    /**
     * The 1st of the $startMonth that opens the $length-month cycle $date
     * falls in — or, when $date is past the end of the latest cycle (e.g.
     * bought in summer), the next one's.
     */
    public static function cycleStartFor(DateTimeInterface $date, int $startMonth, int $length): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date->format('Y-m-d'), self::TIMEZONE)->startOfDay();
        $year = self::calendarAt($day)->get(IntlCalendar::FIELD_YEAR);

        foreach ([$year - 1, $year, $year + 1] as $candidateYear) {
            $start = self::firstOfMonth($candidateYear, $startMonth);

            if ($day->lt(self::addMonths($start, $length))) {
                return $start;
            }
        }

        return self::firstOfMonth($year + 2, $startMonth);
    }

    /**
     * Subscription bundles carry the school year they're for (2026-11-24,
     * business owner: one subscription bundle per year), stored as the Hebrew
     * year the school year is named after — תשפ״ז = 5787. Its month 1 is the
     * settings' start month in that year, except a start month in the summer
     * (תמוז/אב/אלול), which opens the school year in the summer before it:
     * with אלול, תשפ״ז starts on 1 אלול תשפ״ו.
     */
    public static function schoolYearStart(int $schoolYear, ?int $startMonth = null): CarbonImmutable
    {
        $startMonth ??= self::startMonth();

        return self::firstOfMonth($startMonth >= self::SUMMER_FIRST_MONTH ? $schoolYear - 1 : $schoolYear, $startMonth);
    }

    /** The school year whose subscription months $date falls in — or, in the summer gap, the next one. */
    public static function schoolYearFor(DateTimeInterface $date, ?int $startMonth = null): int
    {
        $startMonth ??= self::startMonth();
        $start = self::cycleStartFor($date, $startMonth, 10);
        $year = self::calendarAt($start)->get(IntlCalendar::FIELD_YEAR);

        return $startMonth >= self::SUMMER_FIRST_MONTH ? $year + 1 : $year;
    }

    /** 5787 => "תשפ״ז (2026–2027)". */
    public static function schoolYearLabel(int $schoolYear): string
    {
        return self::yearInLetters($schoolYear).' ('.($schoolYear - 3761).'–'.($schoolYear - 3760).')';
    }

    /** Pick-list years: last year to three years ahead, year => label. */
    public static function schoolYearOptions(?int $include = null): array
    {
        $current = self::schoolYearFor(now());
        $years = range($current - 1, $current + 3);

        if ($include && ! in_array($include, $years, true)) {
            $years[] = $include;
            sort($years);
        }

        return collect($years)->mapWithKeys(fn (int $year) => [$year => self::schoolYearLabel($year)])->all();
    }

    /**
     * A school year as typed: "תשפ״ז" / "תשפז" / "תשפ"ז (2026–2027)", "5787",
     * or the Gregorian years "2026-2027" / "2026/27" / "2027" (the year it ends in).
     */
    public static function parseSchoolYear(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(5\d{3})$/', $value, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^(20\d{2})\s*[-–\/]\s*(\d{2}|\d{4})$/u', $value, $m)) {
            $end = strlen($m[2]) === 2 ? (int) (substr($m[1], 0, 2).$m[2]) : (int) $m[2];

            return $end === (int) $m[1] + 1 ? $end + 3760 : null;
        }

        if (preg_match('/^(20\d{2})$/', $value, $m)) {
            return (int) $m[1] + 3760;
        }

        // Hebrew letters — whatever follows them (a Gregorian label in brackets) is ignored.
        $letters = preg_replace('/["\'״׳]/u', '', preg_split('/\s|\(/u', $value)[0]);
        $values = ['א' => 1, 'ב' => 2, 'ג' => 3, 'ד' => 4, 'ה' => 5, 'ו' => 6, 'ז' => 7, 'ח' => 8, 'ט' => 9,
            'י' => 10, 'כ' => 20, 'ך' => 20, 'ל' => 30, 'מ' => 40, 'ם' => 40, 'נ' => 50, 'ן' => 50, 'ס' => 60, 'ע' => 70,
            'פ' => 80, 'ף' => 80, 'צ' => 90, 'ץ' => 90, 'ק' => 100, 'ר' => 200, 'ש' => 300, 'ת' => 400];
        $sum = 0;

        foreach (mb_str_split($letters) as $letter) {
            if (! isset($values[$letter])) {
                return null;
            }

            $sum += $values[$letter];
        }

        return $sum >= 700 && $sum < 1000 ? 5000 + $sum : null;
    }

    /** $months Hebrew months after $date (the 1st of a Hebrew month). */
    public static function addMonths(DateTimeInterface $date, int $months): CarbonImmutable
    {
        $calendar = self::calendarAt($date);
        $calendar->add(IntlCalendar::FIELD_MONTH, $months);

        return self::toCarbon($calendar);
    }

    /** e.g. "טבת תשפ״ז". */
    public static function monthLabel(DateTimeInterface $date): string
    {
        $calendar = self::calendarAt($date);
        $formatter = new IntlDateFormatter('he_IL@calendar=hebrew', IntlDateFormatter::NONE, IntlDateFormatter::NONE,
            new DateTimeZone(self::TIMEZONE), IntlDateFormatter::TRADITIONAL, 'MMMM');

        return $formatter->format($calendar).' '.self::yearInLetters($calendar->get(IntlCalendar::FIELD_YEAR));
    }

    /** A full Hebrew date, e.g. "י״ב תשרי תשפ״ז". */
    public static function dateLabel(DateTimeInterface $date): string
    {
        return self::yearInLetters(self::calendarAt($date)->get(IntlCalendar::FIELD_DAY_OF_MONTH)).' '.self::monthLabel($date);
    }

    /** 5787 => "תשפ״ז" (thousands dropped, as customary); also a day of the month, 15 => "ט״ו". */
    public static function yearInLetters(int $year): string
    {
        $n = $year % 1000;
        $letters = str_repeat('ת', intdiv($n, 400));
        $n %= 400;

        foreach ([300 => 'ש', 200 => 'ר', 100 => 'ק'] as $value => $letter) {
            if ($n >= $value) {
                $letters .= $letter;
                $n -= $value;
            }
        }

        if ($n === 15 || $n === 16) {
            $letters .= $n === 15 ? 'טו' : 'טז';
        } else {
            $tens = ['', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ'];
            $units = ['', 'א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט'];
            $letters .= $tens[intdiv($n, 10)].$units[$n % 10];
        }

        return mb_strlen($letters) === 1
            ? $letters.'׳'
            : mb_substr($letters, 0, -1).'״'.mb_substr($letters, -1);
    }

    private static function firstOfMonth(int $year, int $month): CarbonImmutable
    {
        $calendar = IntlCalendar::createInstance(self::TIMEZONE, 'he_IL@calendar=hebrew');
        $calendar->clear();
        $calendar->setDate($year, $month, 1);

        return self::toCarbon($calendar);
    }

    private static function calendarAt(DateTimeInterface $date): IntlCalendar
    {
        $calendar = IntlCalendar::createInstance(self::TIMEZONE, 'he_IL@calendar=hebrew');
        // Noon, so a date never slips a day across a DST/offset boundary.
        $calendar->setTime(CarbonImmutable::parse($date->format('Y-m-d').' 12:00', self::TIMEZONE)->getTimestamp() * 1000);

        return $calendar;
    }

    private static function toCarbon(IntlCalendar $calendar): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(intdiv((int) $calendar->getTime(), 1000), self::TIMEZONE)->startOfDay();
    }
}
