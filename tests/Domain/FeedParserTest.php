<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Domain;

use Nordwerk\BookingBundle\Domain\FeedParser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    public function testReadsTimedAllDayAndRecurringEventsButNotFreeOrCancelledOnes(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//DE',
            'BEGIN:VEVENT', 'UID:a', 'DTSTART;TZID=Europe/Berlin:20261110T100000', 'DTEND;TZID=Europe/Berlin:20261110T110000', 'SUMMARY:Privat', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:b', 'DTSTART;VALUE=DATE:20261111', 'DTEND;VALUE=DATE:20261112', 'SUMMARY:Urlaub', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:c', 'DTSTART:20261109T130000Z', 'DTEND:20261109T140000Z', 'RRULE:FREQ=WEEKLY;COUNT=3', 'SUMMARY:Jour fixe', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:d', 'DTSTART:20261112T080000Z', 'DTEND:20261112T090000Z', 'TRANSP:TRANSPARENT', 'END:VEVENT',
            'BEGIN:VEVENT', 'UID:e', 'DTSTART:20261112T100000Z', 'DTEND:20261112T110000Z', 'STATUS:CANCELLED', 'END:VEVENT',
            'END:VCALENDAR', '',
        ]);
        $busy = FeedParser::busy($ics, self::utc(0, 0, 0, 11, 9, 2026), self::utc(0, 0, 0, 11, 30, 2026), 'Europe/Berlin');

        $this->assertSame(
            [
                ['start' => self::utc(13, 0, 0, 11, 9, 2026), 'end' => self::utc(14, 0, 0, 11, 9, 2026)],
                ['start' => self::utc(9, 0, 0, 11, 10, 2026), 'end' => self::utc(10, 0, 0, 11, 10, 2026)],
                // All day in Berlin: 11.11. 00:00 to 12.11. 00:00 local time.
                ['start' => self::utc(23, 0, 0, 11, 10, 2026), 'end' => self::utc(23, 0, 0, 11, 11, 2026)],
                ['start' => self::utc(13, 0, 0, 11, 16, 2026), 'end' => self::utc(14, 0, 0, 11, 16, 2026)],
                ['start' => self::utc(13, 0, 0, 11, 23, 2026), 'end' => self::utc(14, 0, 0, 11, 23, 2026)],
            ],
            $busy,
        );
    }

    public function testRejectsAnythingThatIsNoCalendar(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        FeedParser::busy('<html>Login</html>', 0, 1, 'Europe/Berlin');
    }

    private static function utc(int $hour, int $minute, int $second, int $month, int $day, int $year): int
    {
        return (int) gmmktime($hour, $minute, $second, $month, $day, $year);
    }
}
