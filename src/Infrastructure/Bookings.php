<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Infrastructure;

use Nordwerk\BookingBundle\Domain\Availability;
use Nordwerk\BookingBundle\Domain\BookingResult;
use Nordwerk\BookingBundle\Domain\BookingRules;
use Nordwerk\BookingBundle\Domain\SlotCalculator;

/**
 * The open booking of a booking type: free times and booking, as publicSlotsCore and
 * bookPublicCore in the core calendar. Mails and webhooks go out after the response.
 */
final class Bookings
{
    public function __construct(
        private readonly Appointments $appointments,
        private readonly FeedCache $feeds,
        private readonly AfterResponse $afterResponse,
    ) {
    }

    /**
     * @param array<string, mixed> $type
     *
     * @return list<array{start: int, end: int}>
     */
    public function freeSlots(array $type, int $now, bool $refreshFeeds = true): array
    {
        $availability = Availability::fromRow($type);
        $busy = [
            ...$this->appointments->busy((int) $type['id'], $now, $now + ($availability->horizonDays + 1) * 86400),
            ...$this->feeds->busy($type, $now, $refreshFeeds),
        ];

        return SlotCalculator::freeSlots($availability, $busy, $now);
    }

    /**
     * @param array<string, mixed> $type
     * @param array<string, mixed> $input start (ms), name, email, phone, note, privacy, _hp
     */
    public function book(array $type, array $input, string $locale, string $pageUrl, int $now): BookingResult
    {
        // A filled honeypot is a bot: it gets a yes, but no appointment and no mail.
        if ('' !== trim((string) ($input['_hp'] ?? ''))) {
            return BookingResult::booked(null);
        }
        if (true !== ($input['privacy'] ?? null) && '1' !== ($input['privacy'] ?? null) && 'on' !== ($input['privacy'] ?? null)) {
            return BookingResult::refused('consent');
        }
        $name = BookingRules::cleanLine((string) ($input['name'] ?? ''), BookingRules::NAME_MAX);
        $email = BookingRules::normalizeEmail((string) ($input['email'] ?? ''));
        $start = $input['start'] ?? null;
        if ('' === $name || null === $email || !\is_int($start) && !(\is_string($start) && ctype_digit($start))) {
            return BookingResult::refused('invalid');
        }
        // The script sends milliseconds; a slot always starts on a full second.
        $start = (int) $start;
        if (0 !== $start % 1000) {
            return BookingResult::refused('taken');
        }
        $result = $this->appointments->book(
            $type,
            intdiv($start, 1000),
            [
                'name' => $name,
                'email' => $email,
                'phone' => BookingRules::cleanLine((string) ($input['phone'] ?? ''), BookingRules::PHONE_MAX),
                'note' => BookingRules::cleanText((string) ($input['note'] ?? ''), BookingRules::NOTE_MAX),
                'locale' => 'en' === $locale ? 'en' : 'de',
                'pageUrl' => $pageUrl,
            ],
            $this->feeds->busy($type, $now, false),
            $now,
        );
        if ($result->ok && null !== $result->appointment) {
            $this->afterResponse->booked((int) $result->appointment['id']);
        }

        return $result;
    }

    public function cancel(int $appointmentId, string $by, int $now): bool
    {
        if (!\in_array($by, ['guest', 'backend'], true)) {
            throw new \InvalidArgumentException('Unknown canceller.');
        }
        $cancelled = $this->appointments->cancel($appointmentId, $by, $now);
        if ($cancelled) {
            $this->afterResponse->cancelled($appointmentId);
        }

        return $cancelled;
    }
}
