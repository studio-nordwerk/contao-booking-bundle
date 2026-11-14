<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Controller;

use Nordwerk\BookingBundle\Domain\Availability;
use Nordwerk\BookingBundle\Domain\BookingRules;
use Nordwerk\BookingBundle\Infrastructure\Bookings;
use Nordwerk\BookingBundle\Infrastructure\BookingTypes;
use Nordwerk\BookingBundle\Infrastructure\RateLimiter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two JSON routes of a content element "Terminbuchung": free times and
 * booking. The booking type comes from the element, never from the request.
 */
final class BookingApiController
{
    public function __construct(
        private readonly BookingTypes $types,
        private readonly Bookings $bookings,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    #[Route('/_booking/{element}/slots', name: 'nordwerk_booking_slots', requirements: ['element' => '\d+'], methods: ['GET'])]
    public function slots(int $element, Request $request): JsonResponse
    {
        $now = time();
        if (!$this->rateLimiter->allow('slots', (string) $request->getClientIp(), BookingRules::SLOT_READS_PER_IP, 60, $now)) {
            return $this->json(['ok' => false, 'reason' => 'rate'], 429);
        }
        $found = $this->types->forElement($element, $now);
        if (null === $found) {
            return $this->json(['ok' => false, 'reason' => 'unavailable'], 404);
        }
        $availability = Availability::fromRow($found['type']);
        $slots = array_map(static fn (array $slot): int => $slot['start'] * 1000, $this->bookings->freeSlots($found['type'], $now));

        return $this->json(['ok' => true, 'timezone' => $availability->timezone, 'slotMinutes' => $availability->slotMinutes, 'slots' => $slots]);
    }

    #[Route('/_booking/{element}', name: 'nordwerk_booking_book', requirements: ['element' => '\d+'], methods: ['POST'])]
    public function book(int $element, Request $request): JsonResponse
    {
        $now = time();
        if (!$this->rateLimiter->allow('book', (string) $request->getClientIp(), BookingRules::BOOKINGS_PER_IP, 600, $now)) {
            return $this->json(['ok' => false, 'reason' => 'rate'], 429);
        }
        $found = $this->types->forElement($element, $now);
        if (null === $found) {
            return $this->json(['ok' => false, 'reason' => 'unavailable'], 404);
        }

        try {
            $input = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $input = null;
        }
        if (!\is_array($input)) {
            return $this->json(['ok' => false, 'reason' => 'invalid'], 400);
        }
        // The page language comes from the form; only the languages of the mails are accepted.
        $locale = \in_array($input['locale'] ?? null, ['de', 'en'], true) ? (string) $input['locale'] : 'de';
        $result = $this->bookings->book($found['type'], $input, $locale, $this->pageUrl($request), $now);
        if (!$result->ok) {
            return $this->json(['ok' => false, 'reason' => $result->reason]);
        }
        $appointment = $result->appointment;

        return $this->json(null === $appointment ? ['ok' => true] : ['ok' => true, 'start' => (int) $appointment['start'] * 1000, 'end' => (int) $appointment['end'] * 1000]);
    }

    /**
     * The page the visitor booked on (same host only), for the links in the mails.
     */
    private function pageUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $host = parse_url($referer, PHP_URL_HOST);
        $path = (string) parse_url($referer, PHP_URL_PATH);

        return \is_string($host) && $host === $request->getHost() ? $request->getSchemeAndHttpHost().$path : $request->getSchemeAndHttpHost().'/';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
