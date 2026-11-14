<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Nordwerk\BookingBundle\Infrastructure\Appointments;
use Nordwerk\BookingBundle\Infrastructure\FeedCache;
use Nordwerk\BookingBundle\Infrastructure\RateLimiter;
use Nordwerk\BookingBundle\Infrastructure\WebhookOutbox;
use Nordwerk\BookingBundle\Mail\BookingMailer;
use Psr\Log\LoggerInterface;

/**
 * Every minute (Contao cron): retries webhooks and mails, refreshes stale
 * calendar subscriptions, removes expired personal data and old counters.
 */
#[AsCronJob('minutely')]
final class BookingCron
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WebhookOutbox $webhooks,
        private readonly Appointments $appointments,
        private readonly BookingMailer $mailer,
        private readonly FeedCache $feeds,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = time();
        $this->webhooks->deliverDue($now);

        foreach ($this->appointments->withPendingMails($now) as $appointment) {
            try {
                $this->mailer->retry($appointment);
            } catch (\Throwable $exception) {
                $this->logger?->error('Booking mail retry failed.', ['appointment' => (int) $appointment['id'], 'error' => $exception->getMessage()]);
            }
        }

        foreach ($this->connection->fetchAllAssociative("SELECT * FROM tl_booking_type WHERE published = '1' AND feeds IS NOT NULL AND feeds <> ''") as $type) {
            $this->feeds->refresh($type, $now);
        }
        $this->appointments->anonymizeExpired($now);
        $this->webhooks->prune($now);
        $this->rateLimiter->prune($now);
    }
}
