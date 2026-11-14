<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

/**
 * Calendar file after RFC 5545, as in the core calendar (lib/ics.ts): CRLF line
 * endings, folding at 75 octets, escaped text, all times in UTC.
 */
final class Ics
{
    /**
     * @param 'PUBLISH'|'CANCEL' $method
     */
    public static function build(string $uid, int $start, int $end, string $title, string $location = '', string $description = '', string $organizerName = '', string $organizerEmail = '', string $method = 'PUBLISH', int|null $stamp = null, int $sequence = 0): string
    {
        $cancelled = 'CANCEL' === $method;
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Nordwerk//Terminbuchung//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            'UID:'.preg_replace('/[\r\n]/', '', $uid),
            'SEQUENCE:'.$sequence,
            'DTSTAMP:'.self::date($stamp ?? time()),
            'DTSTART:'.self::date($start),
            'DTEND:'.self::date($end),
            'SUMMARY:'.self::text($title),
        ];
        if ('' !== $location) {
            $lines[] = 'LOCATION:'.self::text($location);
        }
        if ('' !== $description) {
            $lines[] = 'DESCRIPTION:'.self::text($description);
        }
        if ('' !== $organizerEmail) {
            $name = '' !== $organizerName ? ';CN="'.str_replace(['"', "\r", "\n"], '', $organizerName).'"' : '';
            $lines[] = 'ORGANIZER'.$name.':mailto:'.preg_replace('/[\r\n\s]/', '', $organizerEmail);
        }
        array_push($lines, 'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED'), 'END:VEVENT', 'END:VCALENDAR');

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    public static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    public static function date(int $timestamp): string
    {
        return gmdate('Ymd\THis\Z', $timestamp);
    }

    public static function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (\strlen($current) + \strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
                // A continuation line starts with a space (one octet).
                $limit = 74;
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
