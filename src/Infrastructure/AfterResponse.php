<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Infrastructure;

use Nordwerk\BookingBundle\Mail\BookingMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Mails and webhooks of a booking or cancellation, sent after the response went
 * out (kernel.terminate), so a slow mail server or endpoint never delays the
 * visitor. What fails here is retried by the cron job.
 */
final class AfterResponse implements ResetInterface
{
    /**
     * @var list<array{0: 'booked'|'cancelled', 1: int}>
     */
    private array $tasks = [];

    public function __construct(
        private readonly BookingMailer $mailer,
        private readonly WebhookOutbox $webhooks,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function booked(int $appointmentId): void
    {
        $this->tasks[] = ['booked', $appointmentId];
    }

    public function cancelled(int $appointmentId): void
    {
        $this->tasks[] = ['cancelled', $appointmentId];
    }

    #[AsEventListener]
    public function onTerminate(TerminateEvent $event): void
    {
        $this->run();
    }

    public function run(): void
    {
        $tasks = $this->tasks;
        $this->tasks = [];

        foreach ($tasks as [$kind, $id]) {
            try {
                'booked' === $kind ? $this->mailer->booked($id) : $this->mailer->cancelled($id);
            } catch (\Throwable $exception) {
                $this->logger?->error('Booking mail failed; the cron job retries it.', ['appointment' => $id, 'error' => $exception->getMessage()]);
            }
        }
        if ([] !== $tasks) {
            $this->webhooks->deliverDue(time());
        }
    }

    public function reset(): void
    {
        $this->tasks = [];
    }
}
