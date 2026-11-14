<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

/**
 * The rules of one booking type, as in the core calendar (calendarSlots.ts):
 * wall-clock windows in the business time zone, slot length, buffer behind every
 * appointment, lead time and horizon.
 */
final readonly class Availability
{
    /**
     * @param list<array{weekday: int, start: int, end: int}> $windows minutes since midnight, weekday 1 = Monday
     */
    public function __construct(
        public string $timezone,
        public int $slotMinutes,
        public int $bufferMinutes,
        public int $leadTimeHours,
        public int $horizonDays,
        public array $windows,
    ) {
    }

    /**
     * @param array<string, mixed> $row tl_booking_type
     */
    public static function fromRow(array $row): self
    {
        $timezone = (string) ($row['timezone'] ?? '');

        return new self(
            self::isValidTimezone($timezone) ? $timezone : 'Europe/Berlin',
            max(5, (int) ($row['duration'] ?? 30)),
            max(0, (int) ($row['buffer'] ?? 0)),
            max(0, (int) ($row['leadTime'] ?? 24)),
            min(366, max(1, (int) ($row['horizon'] ?? 14))),
            WeeklyWindows::fromRow($row),
        );
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return \in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }
}
