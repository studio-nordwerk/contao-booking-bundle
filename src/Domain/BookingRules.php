<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

/**
 * Limits of the open booking, as in the core calendar (calendar.ts, bookPublicCore).
 */
final class BookingRules
{
    /**
     * At most so many future bookings per mail address and booking type.
     */
    public const MAX_PER_EMAIL = 3;

    /**
     * At most so many bookings per booking type within 24 hours (protects against a
     * booked-out calendar).
     */
    public const MAX_PER_DAY = 40;

    public const NAME_MAX = 120;

    public const EMAIL_MAX = 200;

    public const PHONE_MAX = 40;

    public const NOTE_MAX = 1000;

    /**
     * Booking attempts per IP address and 10 minutes.
     */
    public const BOOKINGS_PER_IP = 10;

    /**
     * Requests for free times per IP address and minute.
     */
    public const SLOT_READS_PER_IP = 60;

    public static function normalizeEmail(string $email): string|null
    {
        $email = mb_strtolower(trim($email));
        if ('' === $email || mb_strlen($email) > self::EMAIL_MAX || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * One line of plain text: control characters and line breaks out, trimmed and cut.
     */
    public static function cleanLine(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return mb_substr((string) preg_replace('/\s+/u', ' ', $value), 0, $max);
    }

    public static function cleanText(string $value, int $max): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $value)), 0, $max);
    }
}
