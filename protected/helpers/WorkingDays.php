<?php

namespace app\helpers;

use DateTimeImmutable;

/**
 * Working-day (Monday-Friday) arithmetic for loan schedules. The client's
 * loans are repaid on weekdays only, so a "30-day" package means 30 working
 * days: Saturdays and Sundays never count towards a loan's repayment
 * period or towards how long it has been overdue.
 *
 * Both methods are O(1) (whole weeks are counted, not walked day by day),
 * so a package with an unusually long period can't make a page load or a
 * migration slow. Dates are 'Y-m-d' strings, like the loan table's date
 * columns.
 */
final class WorkingDays
{
    /**
     * The date `$days` working days after `$startDate` (day 1 is the first
     * working day AFTER the start date; the start date itself never counts,
     * whatever day of the week it is). A weekend start date behaves as the
     * Friday before it - the next working day is the following Monday
     * either way. With $days >= 1 the result is always a working day.
     */
    public static function add(string $startDate, int $days): string
    {
        $start = self::parse($startDate);
        if ($days <= 0) {
            return $start->format('Y-m-d');
        }

        $dow = (int) $start->format('N'); // 1 = Monday ... 7 = Sunday
        $base = $dow > 5 ? $start->modify('-' . ($dow - 5) . ' days') : $start; // weekend -> Friday
        $baseIndex = min($dow, 5) - 1; // Monday = 0 ... Friday = 4
        $monday = $base->modify('-' . $baseIndex . ' days');

        $total = $baseIndex + $days;
        $weeks = intdiv($total, 5);
        $remainder = $total % 5;

        return $monday->modify('+' . ($weeks * 7 + $remainder) . ' days')->format('Y-m-d');
    }

    /**
     * How many working days fall after `$from` up to and including `$to`
     * (0 if `$to` is not after `$from`). The inverse of add(): for a
     * working-day $to, between($from, add($from, $n)) === $n.
     */
    public static function between(string $from, string $to): int
    {
        if ($to <= $from) {
            return 0;
        }

        return self::workingDaysThrough(self::parse($to)) - self::workingDaysThrough(self::parse($from));
    }

    /**
     * Running count of working days from a fixed Monday (2000-01-03) up to
     * and including $date; only ever used as a difference of two of these.
     */
    private static function workingDaysThrough(DateTimeImmutable $date): int
    {
        $anchor = new DateTimeImmutable('2000-01-03');
        $days = (int) $anchor->diff($date)->format('%r%a');
        $weeks = intdiv($days, 7);
        $rest = $days % 7;
        if ($rest < 0) {
            $rest += 7;
            $weeks -= 1;
        }

        return $weeks * 5 + min($rest + 1, 5);
    }

    private static function parse(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00');
    }
}
