<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Mail;

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Nordwerk\BookingBundle\Domain\Ics;
use Nordwerk\BookingBundle\Infrastructure\Appointments;
use Nordwerk\BookingBundle\Infrastructure\BookingTypes;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Confirmation with calendar file to the guest, notice to the business, cancellation to
 * both. Every mail is sent once: a column per mail is claimed before sending and
 * released again if the transport fails, so the cron job can retry.
 */
final class BookingMailer
{
    private string $locale = 'de';

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Appointments $appointments,
        private readonly Connection $connection,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly ContaoFramework|null $framework = null,
    ) {
    }

    public function booked(int $id): void
    {
        $this->deliver($id, 'confirmationSentAt', fn (array $a, array $t) => $this->confirmation($a, $t));
        $this->deliver($id, 'merchantSentAt', fn (array $a, array $t) => $this->merchant($a, $t));
    }

    public function cancelled(int $id): void
    {
        $this->deliver($id, 'cancellationSentAt', fn (array $a, array $t) => $this->cancellation($a, $t));
    }

    /**
     * Retries whatever is still due for an appointment (cron job).
     *
     * @param array<string, mixed> $appointment
     */
    public function retry(array $appointment): void
    {
        'cancelled' === $appointment['status'] ? $this->cancelled((int) $appointment['id']) : $this->booked((int) $appointment['id']);
    }

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): list<Email> $build
     */
    private function deliver(int $id, string $column, callable $build): void
    {
        $appointment = $this->appointments->find($id);
        $type = null === $appointment ? false : $this->connection->fetchAssociative('SELECT * FROM tl_booking_type WHERE id = ?', [(int) $appointment['pid']]);
        if (null === $appointment || false === $type || (int) $appointment['anonymizedAt'] > 0 || !$this->appointments->claimMail($id, $column, time())) {
            return;
        }
        if ('cancellationSentAt' !== $column && 'confirmed' !== $appointment['status']) {
            return;
        }
        $this->locale = 'en' === $appointment['locale'] ? 'en' : 'de';

        try {
            foreach ($build($appointment, $type) as $email) {
                if (null === $this->transport->send($email)) {
                    throw new TransportException('Mail transport rejected the message.');
                }
            }
        } catch (\Throwable $exception) {
            $this->appointments->releaseMail($id, $column);

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $appointment
     * @param array<string, mixed> $type
     *
     * @return list<Email>
     */
    private function confirmation(array $appointment, array $type): array
    {
        $when = $this->when($appointment);
        $cancelUrl = $this->cancelUrl($appointment);
        $paragraphs = [
            $this->t('Guten Tag %name%,', ['%name%' => $appointment['name']]),
            $this->t('Ihr Termin ist gebucht. Den Kalendereintrag finden Sie im Anhang.'),
        ];
        if ('' !== trim((string) $type['mailNote'])) {
            $paragraphs[] = trim((string) $type['mailNote']);
        }
        $paragraphs[] = $this->t('Falls Sie den Termin nicht wahrnehmen können, sagen Sie ihn bitte über den Link unten ab. Bei Fragen antworten Sie einfach auf diese E-Mail.');
        $subject = $this->t('Ihr Termin am %date% ist bestätigt', ['%date%' => $when['short']]);
        $email = $this->email(
            $type,
            $subject,
            [
                'heading' => $this->t('Ihr Termin ist gebucht'),
                'paragraphs' => $paragraphs,
                'details' => $this->details($appointment, $when, false),
                'link' => null === $cancelUrl ? null : ['url' => $cancelUrl, 'label' => $this->t('Termin absagen')],
            ],
        )->to(new Address((string) $appointment['email'], (string) $appointment['name']));
        $email->replyTo(new Address((string) $type['businessEmail'], (string) $type['businessName']));
        $email->attach($this->ics($appointment, $type, 'PUBLISH'), 'termin.ics', 'text/calendar; charset=utf-8; method=PUBLISH');

        return [$email];
    }

    /**
     * @param array<string, mixed> $appointment
     * @param array<string, mixed> $type
     *
     * @return list<Email>
     */
    private function merchant(array $appointment, array $type): array
    {
        $recipients = BookingTypes::emails((string) $type['notifyEmails']) ?: [(string) $type['businessEmail']];
        $when = $this->when($appointment);
        $email = $this->email(
            $type,
            $this->t('Neuer Termin: %name%, %date%', ['%name%' => $appointment['name'], '%date%' => $when['short']]),
            [
                'heading' => $this->t('Neuer Termin über die Website'),
                'paragraphs' => [$this->t('Der Termin ist bereits bestätigt; der Gast hat die Bestätigung mit Kalendereintrag bekommen. Absagen können Sie im Backend unter „Terminbuchung“.')],
                'details' => $this->details($appointment, $when, true),
                'link' => null,
            ],
        )->to(...$recipients);
        $email->replyTo(new Address((string) $appointment['email'], (string) $appointment['name']));
        $email->attach($this->ics($appointment, $type, 'PUBLISH'), 'termin.ics', 'text/calendar; charset=utf-8; method=PUBLISH');

        return [$email];
    }

    /**
     * @param array<string, mixed> $appointment
     * @param array<string, mixed> $type
     *
     * @return list<Email>
     */
    private function cancellation(array $appointment, array $type): array
    {
        $when = $this->when($appointment);
        $mails = [];
        $rebook = '' !== (string) $appointment['pageUrl'] ? ['url' => (string) $appointment['pageUrl'], 'label' => $this->t('Neuen Termin wählen')] : null;
        $guest = $this->email(
            $type,
            $this->t('Ihr Termin am %date% ist abgesagt', ['%date%' => $when['short']]),
            [
                'heading' => $this->t('Ihr Termin ist abgesagt'),
                'paragraphs' => [
                    $this->t('Guten Tag %name%,', ['%name%' => $appointment['name']]),
                    'guest' === $appointment['cancelledBy'] ? $this->t('wie gewünscht haben wir Ihren Termin abgesagt.') : $this->t('leider müssen wir Ihren Termin absagen. Bitte entschuldigen Sie.'),
                    $this->t('Gern können Sie einen neuen Termin wählen.'),
                ],
                'details' => $this->details($appointment, $when, false),
                'link' => $rebook,
            ],
        )->to(new Address((string) $appointment['email'], (string) $appointment['name']));
        $guest->replyTo(new Address((string) $type['businessEmail'], (string) $type['businessName']));
        $guest->attach($this->ics($appointment, $type, 'CANCEL'), 'absage.ics', 'text/calendar; charset=utf-8; method=CANCEL');
        $mails[] = $guest;
        if ('guest' === $appointment['cancelledBy']) {
            $recipients = BookingTypes::emails((string) $type['notifyEmails']) ?: [(string) $type['businessEmail']];
            $merchant = $this->email(
                $type,
                $this->t('Abgesagt: %name%, %date%', ['%name%' => $appointment['name'], '%date%' => $when['short']]),
                [
                    'heading' => $this->t('Ein Gast hat abgesagt'),
                    'paragraphs' => [$this->t('Die Zeit ist wieder frei und kann neu gebucht werden.')],
                    'details' => $this->details($appointment, $when, true),
                    'link' => null,
                ],
            )->to(...$recipients);
            $merchant->replyTo(new Address((string) $appointment['email'], (string) $appointment['name']));
            $mails[] = $merchant;
        }

        return $mails;
    }

    /**
     * @param array<string, mixed>                                                                                                                               $type
     * @param array{heading: string, paragraphs: list<string>, details: list<array{label: string, value: string}>, link: array{url: string, label: string}|null} $content
     */
    private function email(array $type, string $subject, array $content): Email
    {
        $variables = [...$content, 'subject' => $subject, 'business' => (string) $type['businessName'], 'locale' => $this->locale];
        $text = [$content['heading'], '', ...array_map(static fn (string $p): string => $p."\n", $content['paragraphs'])];

        foreach ($content['details'] as $detail) {
            $text[] = $detail['label'].': '.$detail['value'];
        }
        if (null !== $content['link']) {
            $text[] = '';
            $text[] = $content['link']['label'].': '.$content['link']['url'];
        }
        $text[] = '';
        $text[] = (string) $type['businessName'];

        return (new Email())
            ->from(new Address($this->sender($type), (string) $type['businessName']))
            ->subject($subject)
            ->text(implode("\n", $text)."\n")
            ->html($this->twig->render('@NordwerkBooking/mail/booking.html.twig', $variables))
        ;
    }

    /**
     * @param array<string, mixed>                             $appointment
     * @param array{long: string, short: string, time: string} $when
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(array $appointment, array $when, bool $contact): array
    {
        $details = [
            ['label' => $this->t('Termin'), 'value' => (string) $appointment['title']],
            ['label' => $this->t('Wann'), 'value' => $when['long']],
        ];
        if ('' !== (string) $appointment['location']) {
            $details[] = ['label' => $this->t('Ort'), 'value' => (string) $appointment['location']];
        }
        if ($contact) {
            $details[] = ['label' => $this->t('Name'), 'value' => (string) $appointment['name']];
            $details[] = ['label' => $this->t('E-Mail'), 'value' => (string) $appointment['email']];
            if ('' !== (string) $appointment['phone']) {
                $details[] = ['label' => $this->t('Telefon'), 'value' => (string) $appointment['phone']];
            }
            if ('' !== trim((string) $appointment['note'])) {
                $details[] = ['label' => $this->t('Anliegen'), 'value' => trim((string) $appointment['note'])];
            }
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $appointment
     *
     * @return array{long: string, short: string, time: string}
     */
    public function when(array $appointment, string|null $locale = null): array
    {
        $locale ??= $this->locale;
        $zone = new \DateTimeZone((string) $appointment['timezone'] ?: 'Europe/Berlin');
        $start = (new \DateTimeImmutable('@'.(int) $appointment['start']))->setTimezone($zone);
        $end = (new \DateTimeImmutable('@'.(int) $appointment['end']))->setTimezone($zone);
        $date = new \IntlDateFormatter('en' === $locale ? 'en_GB' : 'de_DE', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, $zone, null, 'en' === $locale ? 'EEEE, d MMMM y' : 'EEEE, d. MMMM y');
        $short = new \IntlDateFormatter('en' === $locale ? 'en_GB' : 'de_DE', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, 'en' === $locale ? 'd MMM, HH:mm' : 'd.M., HH:mm \'Uhr\'');
        $time = $start->format('H:i').'–'.$end->format('H:i');
        $long = $date->format($start).', '.$time.('en' === $locale ? '' : ' Uhr').' ('.$zone->getName().')';

        return ['long' => $long, 'short' => (string) $short->format($start), 'time' => $time];
    }

    /**
     * @param array<string, mixed> $appointment
     * @param array<string, mixed> $type
     */
    private function ics(array $appointment, array $type, string $method): string
    {
        $cancelled = 'CANCEL' === $method;

        return Ics::build(
            uid: (string) $appointment['uid'],
            start: (int) $appointment['start'],
            end: (int) $appointment['end'],
            title: (string) $appointment['title'].' · '.(string) $type['businessName'],
            location: (string) $appointment['location'],
            description: trim((string) $type['mailNote']),
            organizerName: (string) $type['businessName'],
            organizerEmail: (string) $type['businessEmail'],
            method: $cancelled ? 'CANCEL' : 'PUBLISH',
            sequence: $cancelled ? 1 : 0,
        );
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function cancelUrl(array $appointment): string|null
    {
        $page = (string) $appointment['pageUrl'];
        $origin = 1 === preg_match('~^(https?://[^/]+)~i', $page, $match) ? $match[1] : null;
        if (null === $origin || '' === (string) $appointment['token']) {
            return null;
        }

        return $origin.$this->urls->generate('nordwerk_booking_cancel', ['token' => (string) $appointment['token']]);
    }

    /**
     * The address of the installation (Contao settings) as sender, so the mail passes
     * SPF; the business address is the reply address. Without one the business
     * address sends.
     *
     * @param array<string, mixed> $type
     */
    private function sender(array $type): string
    {
        try {
            $this->framework?->initialize();
            $admin = (string) $this->framework?->getAdapter(Config::class)->get('adminEmail');
        } catch (\Throwable) {
            $admin = '';
        }

        return false !== filter_var($admin, FILTER_VALIDATE_EMAIL) ? $admin : (string) $type['businessEmail'];
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function t(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, 'messages', $this->locale);
    }
}
