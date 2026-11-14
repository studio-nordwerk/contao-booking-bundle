<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Domain;

use Nordwerk\BookingBundle\Domain\Availability;
use Nordwerk\BookingBundle\Domain\SlotCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Expectations from the core calendar (apps/web/convex/lib/calendarSlots.test.ts).
 */
final class SlotCalculatorTest extends TestCase
{
    private const TZ = 'Europe/Berlin';

    /**
     * Mon 12.10.2026, 08:00 Berlin (summer time, UTC+2).
     */
    private const MONDAY_MORNING = 1791784800;

    public function testConvertsWallClockWithTheOffsetOfThatDay(): void
    {
        // 24.10.2026 still summer time (UTC+2), 25.10.2026 from 03:00 winter time (UTC+1).
        $this->assertSame(self::utc(7, 0, 0, 10, 24, 2026), SlotCalculator::zonedToUtc(2026, 10, 24, 9 * 60, self::TZ));
        $this->assertSame(self::utc(8, 0, 0, 10, 25, 2026), SlotCalculator::zonedToUtc(2026, 10, 25, 9 * 60, self::TZ));
    }

    public function testTakesTheEarlierOfTheDoubledAutumnHour(): void
    {
        $this->assertSame(self::utc(0, 30, 0, 10, 25, 2026), SlotCalculator::zonedToUtc(2026, 10, 25, 2 * 60 + 30, self::TZ));
    }

    public function testSpringGapTimesDoNotExist(): void
    {
        $this->assertNull(SlotCalculator::zonedToUtc(2026, 3, 29, 2 * 60 + 30, self::TZ));
    }

    public function testRespectsTheLeadTime(): void
    {
        $this->assertSame(self::MONDAY_MORNING, self::utc(6, 0, 0, 10, 12, 2026));
        $slots = SlotCalculator::freeSlots($this->availability(), [], self::MONDAY_MORNING);
        // 24 hours lead time: earliest Tue 13.10. 08:00, so the first slot is Tue 09:00.
        $this->assertSame('2026-10-13 09:00', $this->wall($slots[0]['start']));
    }

    public function testPutsSlotsOnAGridFromTheWindowStart(): void
    {
        $slots = SlotCalculator::freeSlots($this->availability(leadTime: 0, horizon: 2, windows: [[1, '10:00', '18:00']]), [], self::utc(8, 20, 0, 10, 12, 2026));
        $this->assertSame(['11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00'], array_map(fn (array $s): string => substr($this->wall($s['start']), 11), $slots));
    }

    public function testBufferGivesNoCrookedTimes(): void
    {
        $this->assertSame(90, SlotCalculator::stepMinutes(60, 15));
        $this->assertSame(60, SlotCalculator::stepMinutes(45, 15));
        $this->assertSame(15, SlotCalculator::stepMinutes(15));
        $slots = SlotCalculator::freeSlots($this->availability(leadTime: 0, buffer: 15, windows: [[2, '10:00', '18:00']]), [], self::MONDAY_MORNING);
        $this->assertSame(['10:00', '11:30', '13:00', '14:30', '16:00'], $this->times($slots, '2026-10-13'));
    }

    public function testRespectsTheHorizon(): void
    {
        $slots = SlotCalculator::freeSlots($this->availability(leadTime: 0, horizon: 3), [], self::MONDAY_MORNING);
        // Three days from Mon 08:00, so until Thu 08:00: Mon, Tue and Wed 09–11.
        $days = array_map(fn (array $s): string => substr($this->wall($s['start']), 0, 10), $slots);
        $this->assertSame([...array_fill(0, 3, '2026-10-12'), ...array_fill(0, 3, '2026-10-13'), ...array_fill(0, 3, '2026-10-14')], $days);
    }

    public function testLeavesOutBusyTimes(): void
    {
        $busy = [['start' => self::utc(7, 0, 0, 10, 13, 2026), 'end' => self::utc(8, 0, 0, 10, 13, 2026)]];
        $times = $this->times(SlotCalculator::freeSlots($this->availability(leadTime: 0), $busy, self::MONDAY_MORNING), '2026-10-13');
        $this->assertNotContains('09:00', $times);
        $this->assertContains('10:00', $times);
    }

    public function testKeepsTheBufferToBusyTimesAndBetweenSlots(): void
    {
        $busy = [['start' => self::utc(7, 0, 0, 10, 13, 2026), 'end' => self::utc(8, 0, 0, 10, 13, 2026)]];
        $slots = SlotCalculator::freeSlots($this->availability(leadTime: 0, buffer: 30, horizon: 3), $busy, self::MONDAY_MORNING);
        // 09:00–10:00 busy plus 30 minutes buffer: 09:00 drops out, 10:30 (ends
        // 11:30) stays.
        $this->assertSame(['10:30'], $this->times($slots, '2026-10-13'));
        // 60 + 30 minutes = 90 minute grid: 09:00, 10:30.
        $this->assertSame(['09:00', '10:30'], $this->times($slots, '2026-10-12'));
    }

    public function testStaysWithTheWallClockAcrossTheAutumnSwitch(): void
    {
        $slots = SlotCalculator::freeSlots($this->availability(leadTime: 0, horizon: 7, windows: array_map(static fn (int $d): array => [$d, '09:00', '10:00'], range(1, 7))), [], self::utc(22, 0, 0, 10, 22, 2026));
        $byDay = [];

        foreach ($slots as $slot) {
            $byDay[substr($this->wall($slot['start']), 0, 10)] = $slot['start'];
        }
        $this->assertSame(self::utc(7, 0, 0, 10, 24, 2026), $byDay['2026-10-24']);
        $this->assertSame(self::utc(8, 0, 0, 10, 25, 2026), $byDay['2026-10-25']);
        $this->assertSame(self::utc(8, 0, 0, 10, 26, 2026), $byDay['2026-10-26']);
    }

    public function testSkipsTheMissingSpringHour(): void
    {
        $slots = SlotCalculator::freeSlots($this->availability(leadTime: 0, horizon: 2, windows: [[7, '01:00', '05:00']]), [], self::utc(12, 0, 0, 3, 28, 2026));
        $this->assertSame(['2026-03-29 01:00', '2026-03-29 03:00', '2026-03-29 04:00'], array_map(fn (array $s): string => $this->wall($s['start']), $slots));
    }

    public function testOffersNoTwoHourSlotInTheOneRealHourOfASpringWindow(): void
    {
        $now = self::utc(12, 0, 0, 3, 28, 2026);
        $this->assertSame([], SlotCalculator::freeSlots($this->availability(slot: 120, leadTime: 0, horizon: 2, windows: [[7, '01:00', '03:00']]), [], $now));
        $hour = SlotCalculator::freeSlots($this->availability(slot: 60, leadTime: 0, horizon: 2, windows: [[7, '01:00', '03:00']]), [], $now);
        $this->assertSame(['2026-03-29 01:00'], array_map(fn (array $s): string => $this->wall($s['start']), $hour));
    }

    public function testFitsATwoHourSlotIntoTheThreeRealHoursOfAnAutumnWindow(): void
    {
        $slots = SlotCalculator::freeSlots($this->availability(slot: 120, leadTime: 0, horizon: 2, windows: [[7, '02:00', '04:00']]), [], self::utc(12, 0, 0, 10, 24, 2026));
        $this->assertNotSame([], $slots);

        foreach ($slots as $slot) {
            $this->assertSame(7200, $slot['end'] - $slot['start']);
        }
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}>|null $windows
     */
    private function availability(int $slot = 60, int $buffer = 0, int $leadTime = 24, int $horizon = 14, array|null $windows = null): Availability
    {
        $windows ??= array_map(static fn (int $d): array => [$d, '09:00', '12:00'], range(1, 5));
        $minutes = static fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

        return new Availability(self::TZ, $slot, $buffer, $leadTime, $horizon, array_map(static fn (array $w): array => ['weekday' => $w[0], 'start' => $minutes($w[1]), 'end' => $minutes($w[2])], $windows));
    }

    private function wall(int $timestamp): string
    {
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d H:i');
    }

    /**
     * @param list<array{start: int, end: int}> $slots
     *
     * @return list<string>
     */
    private function times(array $slots, string $day): array
    {
        $walls = array_map(fn (array $s): string => $this->wall($s['start']), $slots);

        return array_values(array_map(static fn (string $w): string => substr($w, 11), array_filter($walls, static fn (string $w): bool => str_starts_with($w, $day))));
    }

    private static function utc(int $hour, int $minute, int $second, int $month, int $day, int $year): int
    {
        return (int) gmmktime($hour, $minute, $second, $month, $day, $year);
    }
}
