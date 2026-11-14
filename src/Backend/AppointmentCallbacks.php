<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Backend;

use Contao\DataContainer;
use Contao\StringUtil;
use Nordwerk\BookingBundle\Infrastructure\Appointments;
use Nordwerk\BookingBundle\Infrastructure\Bookings;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AppointmentCallbacks
{
    public function __construct(
        private readonly Appointments $appointments,
        private readonly Bookings $bookings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Cancelling sends the mail to the guest and the webhook. A cancelled appointment
     * cannot be confirmed again: the time may have been booked by someone else meanwhile.
     */
    public function saveStatus(mixed $value, DataContainer $dc): string
    {
        $current = $this->appointments->find((int) $dc->id);
        if (null === $current || $value === $current['status']) {
            return (string) $value;
        }
        if ('cancelled' !== $value) {
            throw new \InvalidArgumentException($this->translator->trans('Ein abgesagter Termin lässt sich nicht wieder bestätigen. Bitte einen neuen Termin buchen lassen.', [], 'messages'));
        }
        $this->bookings->cancel((int) $dc->id, 'backend', time());

        return 'cancelled';
    }

    /**
     * @param array<string, mixed> $row
     */
    public function label(array $row): string
    {
        // In the time zone of the booking type, like the guest saw it.
        $zone = new \DateTimeZone((string) $row['timezone'] ?: 'Europe/Berlin');
        $start = (new \DateTimeImmutable('@'.(int) $row['start']))->setTimezone($zone);
        $end = (new \DateTimeImmutable('@'.(int) $row['end']))->setTimezone($zone);
        $time = $start->format('d.m.Y H:i').'–'.$end->format('H:i');
        $who = (int) $row['anonymizedAt'] > 0 ? $this->translator->trans('(anonymisiert)', [], 'messages') : StringUtil::specialchars((string) $row['name'].' <'.$row['email'].'>');
        $state = 'cancelled' === $row['status'] ? ' <span class="tl_red">'.$this->translator->trans('abgesagt', [], 'messages').'</span>' : '';

        return '<strong>'.$time.'</strong> '.$who.$state;
    }
}
