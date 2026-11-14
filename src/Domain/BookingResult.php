<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

/**
 * Outcome of a booking. Expected refusals are results, not errors: unavailable
 * (type off or no windows), invalid (name or address), consent, taken (not an
 * offered slot right now), limit (per address or day), rate (too many requests
 * from one IP).
 */
final readonly class BookingResult
{
    /**
     * @param array<string, mixed>|null $appointment
     */
    private function __construct(
        public bool $ok,
        public string $reason = '',
        public array|null $appointment = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $appointment null for a detected bot: it gets a yes but no appointment
     */
    public static function booked(array|null $appointment): self
    {
        return new self(true, '', $appointment);
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }
}
