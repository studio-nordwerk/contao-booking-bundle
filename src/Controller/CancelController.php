<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Controller;

use Nordwerk\BookingBundle\Infrastructure\Appointments;
use Nordwerk\BookingBundle\Infrastructure\Bookings;
use Nordwerk\BookingBundle\Mail\BookingMailer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Cancel link from the confirmation mail. The link only shows the appointment;
 * the cancellation needs the button (POST), so link previews in mail programs
 * cancel nothing.
 */
final class CancelController
{
    public function __construct(
        private readonly Appointments $appointments,
        private readonly Bookings $bookings,
        private readonly BookingMailer $mailer,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/_booking/absage/{token}', name: 'nordwerk_booking_cancel', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        $appointment = $this->appointments->findByToken($token);
        $now = time();
        $state = 'unknown';
        if (null !== $appointment) {
            $state = 'cancelled' === $appointment['status'] ? 'cancelled' : ((int) $appointment['start'] <= $now ? 'past' : 'open');
            if ('open' === $state && $request->isMethod('POST')) {
                $state = $this->bookings->cancel((int) $appointment['id'], 'guest', $now) ? 'done' : 'cancelled';
            }
        }
        $locale = 'en' === ($appointment['locale'] ?? 'de') ? 'en' : 'de';
        $request->setLocale($locale);
        $response = new Response($this->twig->render('@NordwerkBooking/cancel.html.twig', [
            'state' => $state,
            'appointment' => $appointment,
            'when' => null === $appointment ? null : $this->mailer->when($appointment, $locale),
            'locale' => $locale,
        ]), null === $appointment ? 404 : 200);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
