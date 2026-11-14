<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Infrastructure;

use Nordwerk\BookingBundle\Domain\BookingRules;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The rules where a mistake hurts: double booking, times outside the availability,
 * limits per address, consent. Expectations from core's booking.convex.test.ts.
 */
final class BookingTest extends DatabaseTestCase
{
    public function testOffersExactlyTheSlotsOfTheAvailabilityInTheBusinessTimeZone(): void
    {
        $slots = $this->bookings()->freeSlots($this->type, self::NOW);
        $this->assertCount(8, $slots);
        $this->assertSame(array_map(static fn (int $n): int => self::TUESDAY_10 + $n * self::HALF_HOUR, [0, 1, 2, 3]), array_column(\array_slice($slots, 0, 4), 'start'));
    }

    public function testBooksASlotOnlyOnce(): void
    {
        $bookings = $this->bookings();
        $this->assertSame('ok', $this->book($bookings));
        $this->assertSame('taken', $this->book($bookings, ['email' => 'max@example.test']));
        $this->assertSame(1, $this->appointmentCount());
        $this->assertNotContains(self::TUESDAY_10, array_column($bookings->freeSlots($this->type, self::NOW), 'start'));
    }

    public function testBooksNothingOutsideTheOfferedTimes(): void
    {
        $bookings = $this->bookings();

        foreach ([
            self::TUESDAY_10 + 4 * self::HALF_HOUR, // 12:00, after the window
            self::TUESDAY_10 + 600, // 10:10, no slot start
            self::TUESDAY_10 + 86400, // Wednesday
            self::NOW - self::HALF_HOUR, // past
            self::TUESDAY_10 + 21 * 86400, // behind the horizon
        ] as $start) {
            $this->assertSame('taken', $this->book($bookings, ['start' => $start * 1000]));
        }
        $this->assertSame('taken', $this->book($bookings, ['start' => self::TUESDAY_10 * 1000 + 1]));
        $this->assertSame(0, $this->appointmentCount());
    }

    public function testKeepsTimesFreeThatACalendarSubscriptionMarksBusy(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:".gmdate('Ymd\THis\Z', self::TUESDAY_10 - 900)."\r\nDTEND:".gmdate('Ymd\THis\Z', self::TUESDAY_10 + 900)."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->type['feeds'] = 'https://calendar.example/feed.ics';
        $bookings = $this->bookings(new MockHttpClient(new MockResponse($ics)));
        $this->assertNotContains(self::TUESDAY_10, array_column($bookings->freeSlots($this->type, self::NOW), 'start'));
        $this->assertSame('taken', $this->book($bookings));
        $this->assertSame('ok', $this->book($bookings, ['start' => (self::TUESDAY_10 + self::HALF_HOUR) * 1000]));
    }

    public function testAnUnreachableFeedBlocksNothingAndKeepsItsLastBusyTimes(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nDTSTART:".gmdate('Ymd\THis\Z', self::TUESDAY_10)."\r\nDTEND:".gmdate('Ymd\THis\Z', self::TUESDAY_10 + 900)."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->type['feeds'] = 'https://calendar.example/feed.ics';
        $this->assertCount(7, $this->bookings(new MockHttpClient(new MockResponse($ics)))->freeSlots($this->type, self::NOW));
        // Six minutes later the feed is down: the last busy times stay, the error is recorded.
        $down = $this->bookings(new MockHttpClient(new MockResponse('', ['error' => 'Connection refused'])));
        $this->assertCount(7, $down->freeSlots($this->type, self::NOW + 360));
        $this->assertNotSame('', (string) $this->connection->fetchOne('SELECT error FROM tl_booking_feed WHERE pid = ?', [(int) $this->type['id']]));
    }

    public function testRequiresConsentANameAndAValidAddress(): void
    {
        $bookings = $this->bookings();
        $this->assertSame('consent', $this->book($bookings, ['privacy' => false]));
        $this->assertSame('invalid', $this->book($bookings, ['name' => '   ']));
        $this->assertSame('invalid', $this->book($bookings, ['email' => 'keine-adresse']));
        $this->assertSame(0, $this->appointmentCount());
    }

    public function testADetectedBotGetsAYesButNoAppointmentAndNoWebhook(): void
    {
        $this->assertSame('ok', $this->book($this->bookings(), ['_hp' => 'https://spam.example']));
        $this->assertSame(0, $this->appointmentCount());
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_booking_webhook WHERE pid = ?', [(int) $this->type['id']]));
    }

    public function testLimitsFutureBookingsPerAddress(): void
    {
        $bookings = $this->bookings();

        for ($n = 0; $n < BookingRules::MAX_PER_EMAIL; ++$n) {
            $this->assertSame('ok', $this->book($bookings, ['start' => (self::TUESDAY_10 + $n * self::HALF_HOUR) * 1000]));
        }
        $next = (self::TUESDAY_10 + BookingRules::MAX_PER_EMAIL * self::HALF_HOUR) * 1000;
        // The spelling of the address must not get round the limit.
        $this->assertSame('limit', $this->book($bookings, ['start' => $next, 'email' => ' Erika@Example.test ']));
        $this->assertSame('ok', $this->book($bookings, ['start' => $next, 'email' => 'max@example.test']));
    }

    public function testACancelledTimeIsFreeAgainAndQueuesTheWebhook(): void
    {
        $bookings = $this->bookings();
        $this->assertSame('ok', $this->book($bookings));
        $id = (int) $this->connection->fetchOne('SELECT id FROM tl_booking_appointment WHERE pid = ?', [(int) $this->type['id']]);
        $this->assertTrue($bookings->cancel($id, 'guest', self::NOW));
        $this->assertFalse($bookings->cancel($id, 'guest', self::NOW), 'A second cancellation changes nothing.');
        $this->assertContains(self::TUESDAY_10, array_column($bookings->freeSlots($this->type, self::NOW), 'start'));
        $events = $this->connection->fetchFirstColumn('SELECT event FROM tl_booking_webhook WHERE pid = ? ORDER BY id', [(int) $this->type['id']]);
        $this->assertSame(['appointment.booked', 'appointment.cancelled'], $events);
    }
}
