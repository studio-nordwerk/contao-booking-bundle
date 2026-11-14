<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Infrastructure;

use Doctrine\DBAL\Connection;

final class BookingTypes
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_booking_type WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * The booking type of a visible content element "Terminbuchung", if both are public.
     *
     * @return array{element: array<string, mixed>, type: array<string, mixed>}|null
     */
    public function forElement(int $elementId, int $now): array|null
    {
        $element = $this->connection->fetchAssociative(
            "SELECT * FROM tl_content WHERE id = ? AND type = 'appointment_booking' AND invisible = '' AND (start = '' OR start <= ?) AND (stop = '' OR stop > ?)",
            [$elementId, $now, $now],
        );
        if (false === $element) {
            return null;
        }
        $type = $this->find((int) $element['bookingType']);

        return null !== $type && '1' === (string) $type['published'] ? ['element' => $element, 'type' => $type] : null;
    }

    /**
     * One address per line (http, https or webcal), duplicates removed.
     *
     * @return list<string>
     */
    public static function urls(string|null $value): array
    {
        $urls = [];

        foreach (preg_split('/\s+/', trim((string) $value)) ?: [] as $url) {
            if ('' !== $url && 1 === preg_match('~^(https?|webcal)://~i', $url)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<string>
     */
    public static function emails(string|null $value): array
    {
        $emails = [];

        foreach (preg_split('/[\s,;]+/', trim((string) $value)) ?: [] as $email) {
            if (false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }
}
