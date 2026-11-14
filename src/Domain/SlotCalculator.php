<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

/**
 * Free slots of a booking type. A port of listFreeSlots() from the core calendar
 * (apps/web/convex/lib/calendarSlots.ts): windows follow the wall clock across
 * daylight saving changes, slots sit on a grid from the window start, never from
 * "now". All times are Unix seconds.
 */
final class SlotCalculator
{
    private const HOUR = 3600;

    private const DAY = 86400;

    /**
     * @param list<array{start: int, end: int}> $busy
     *
     * @return list<array{start: int, end: int}>
     */
    public static function freeSlots(Availability $availability, array $busy, int $now): array
    {
        $slotSeconds = $availability->slotMinutes * 60;
        $buffer = $availability->bufferMinutes * 60;
        $earliest = $now + $availability->leadTimeHours * self::HOUR;
        $horizonEnd = $now + $availability->horizonDays * self::DAY;
        $timezone = $availability->timezone;
        $step = self::stepMinutes($availability->slotMinutes, $availability->bufferMinutes);
        $first = self::localParts($now, $timezone);
        $seen = [];
        $slots = [];

        for ($offset = 0; $offset < $availability->horizonDays + 2; ++$offset) {
            // Noon UTC of the calendar day gives a stable date and weekday, whatever the switch.
            $noon = (int) gmmktime(12, 0, 0, $first['month'], $first['day'] + $offset, $first['year']);
            [$year, $month, $day, $weekday] = array_map('intval', explode('-', gmdate('Y-n-j-N', $noon)));

            foreach ($availability->windows as $window) {
                if ($window['weekday'] !== $weekday) {
                    continue;
                }
                // The window end in UTC: if it falls into the spring gap, the start of the gap counts.
                $windowEnd = self::zonedToUtc($year, $month, $day, $window['end'], $timezone)
                    ?? (self::zonedToUtc($year, $month, $day, $window['end'] + 60, $timezone) ?? PHP_INT_MAX - self::HOUR) - self::HOUR;

                for ($minute = $window['start']; $minute < $window['end']; $minute += $step) {
                    $start = self::zonedToUtc($year, $month, $day, $minute, $timezone);
                    if (null === $start) {
                        continue;
                    }
                    $slot = ['start' => $start, 'end' => $start + $slotSeconds];
                    if ($slot['end'] > $windowEnd || $start < $earliest || $slot['end'] > $horizonEnd || isset($seen[$start])) {
                        continue;
                    }

                    foreach ($busy as $entry) {
                        if ($slot['start'] < $entry['end'] + $buffer && $slot['end'] + $buffer > $entry['start']) {
                            continue 2;
                        }
                    }
                    $seen[$start] = true;
                    $slots[] = $slot;
                }
            }
        }
        usort($slots, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $slots;
    }

    /**
     * Grid step from the window start: slot plus buffer, from 30 minutes on rounded
     * up to full half hours, so 60 + 15 minutes gives 10:00, 11:30, 13:00 instead of
     * crooked times.
     */
    public static function stepMinutes(int $slotMinutes, int $bufferMinutes = 0): int
    {
        $step = $slotMinutes + $bufferMinutes;

        return $step >= 30 ? (int) ceil($step / 30) * 30 : max(1, $step);
    }

    /**
     * Wall-clock time in the time zone to Unix seconds. Times in the spring gap do
     * not exist (null); for the doubled autumn hour the earlier one wins.
     */
    public static function zonedToUtc(int $year, int $month, int $day, int $minutesOfDay, string $timezone): int|null
    {
        $hour = intdiv($minutesOfDay, 60);
        $minute = $minutesOfDay % 60;
        $wall = (int) gmmktime($hour, $minute, 0, $month, $day, $year);
        // The offset changes at most once a day: try the offsets of the day before and after.
        $candidates = array_unique([$wall - self::offsetAt($wall - self::DAY, $timezone), $wall - self::offsetAt($wall + self::DAY, $timezone)]);
        sort($candidates);

        foreach ($candidates as $candidate) {
            $parts = self::localParts($candidate, $timezone);
            if ([$parts['year'], $parts['month'], $parts['day'], $parts['hour'], $parts['minute']] === [$year, $month, $day, $hour, $minute]) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{year: int, month: int, day: int, hour: int, minute: int, weekday: int}
     */
    public static function localParts(int $timestamp, string $timezone): array
    {
        $date = (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone($timezone));
        [$year, $month, $day, $hour, $minute, $weekday] = array_map('intval', explode('-', $date->format('Y-n-j-G-i-N')));

        return ['year' => $year, 'month' => $month, 'day' => $day, 'hour' => $hour, 'minute' => $minute, 'weekday' => $weekday];
    }

    private static function offsetAt(int $timestamp, string $timezone): int
    {
        return (new \DateTimeZone($timezone))->getOffset(new \DateTimeImmutable('@'.$timestamp));
    }
}
