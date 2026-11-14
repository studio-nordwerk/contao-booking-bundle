<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Busy times from a calendar subscription (Outlook, Google, iCloud): every event
 * in the range, recurring events expanded, all-day events in the business time
 * zone. Transparent ("free") and cancelled events do not block.
 */
final class FeedParser
{
    /**
     * @return list<array{start: int, end: int}>
     *
     * @throws \UnexpectedValueException if the text is no calendar
     */
    public static function busy(string $ics, int $from, int $to, string $timezone): array
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable $exception) {
            throw new \UnexpectedValueException('No calendar data.', 0, $exception);
        }
        if (!$calendar instanceof VCalendar) {
            throw new \UnexpectedValueException('No calendar data.');
        }
        $zone = new \DateTimeZone($timezone);
        $expanded = $calendar->expand(new \DateTimeImmutable('@'.$from), new \DateTimeImmutable('@'.$to), $zone);
        $busy = [];

        foreach ($expanded->select('VEVENT') as $event) {
            if (!$event instanceof VEvent || !isset($event->DTSTART)) {
                continue;
            }
            if ('TRANSPARENT' === strtoupper((string) ($event->TRANSP ?? '')) || 'CANCELLED' === strtoupper((string) ($event->STATUS ?? ''))) {
                continue;
            }
            $start = $event->DTSTART->getDateTime($zone);
            if (isset($event->DTEND)) {
                $end = $event->DTEND->getDateTime($zone);
            } elseif (isset($event->DURATION)) {
                $end = $start->add($event->DURATION->getDateInterval());
            } else {
                // RFC 5545: a date without end lasts one day, a date-time without end is a moment.
                $end = $event->DTSTART->hasTime() ? $start : $start->modify('+1 day');
            }
            $range = ['start' => $start->getTimestamp(), 'end' => max($start->getTimestamp(), $end->getTimestamp())];
            if ($range['end'] > $from && $range['start'] < $to) {
                $busy[] = $range;
            }
        }
        usort($busy, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $busy;
    }
}
