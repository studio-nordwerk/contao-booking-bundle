<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Infrastructure;

use Doctrine\DBAL\Connection;
use Nordwerk\BookingBundle\Domain\WebhookSignature;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Webhooks "appointment.booked" and "appointment.cancelled": queued in the
 * booking transaction, sent after the response and retried by the cron job. A
 * dead endpoint never blocks a booking. Deliveries keep their id across retries
 * (Standard Webhooks).
 */
final class WebhookOutbox
{
    /**
     * Seconds before attempt 2, 3, … ; after the last one the delivery counts as failed.
     */
    public const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    private const TIMEOUT = 5;

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $type
     * @param array<string, mixed> $appointment
     *
     * @return list<int> ids of the queued deliveries
     */
    public function queue(array $type, string $event, array $appointment, int $now): array
    {
        $payload = self::payload($type, $event, $appointment, $now);
        $ids = [];

        foreach (BookingTypes::urls((string) $type['webhooks']) as $url) {
            $this->connection->insert('tl_booking_webhook', [
                'pid' => (int) $type['id'], 'tstamp' => $now, 'appointmentId' => (int) ($appointment['id'] ?? 0),
                'event' => $event, 'url' => $url, 'deliveryId' => 'msg_'.bin2hex(random_bytes(12)),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending', 'nextAttemptAt' => $now, 'createdAt' => $now,
            ]);
            $ids[] = (int) $this->connection->lastInsertId();
        }

        return $ids;
    }

    /**
     * A test event with an invented appointment, for the button in the back end.
     *
     * @param array<string, mixed> $type
     *
     * @return list<int>
     */
    public function queueTest(array $type, int $now): array
    {
        $start = $now + 2 * 86400 - $now % 3600;

        return $this->queue(
            $type,
            'test',
            [
                'id' => 0, 'status' => 'confirmed', 'start' => $start, 'end' => $start + (int) $type['duration'] * 60,
                'title' => (string) $type['title'], 'location' => (string) $type['location'], 'timezone' => (string) $type['timezone'],
                'name' => 'Erika Mustermann', 'email' => 'erika@example.com', 'phone' => '', 'note' => 'Testereignis aus dem Contao-Backend', 'createdAt' => $now,
            ],
            $now,
        );
    }

    /**
     * @param array<string, mixed> $type
     * @param array<string, mixed> $appointment
     *
     * @return array<string, mixed>
     */
    public static function payload(array $type, string $event, array $appointment, int $now): array
    {
        $timezone = new \DateTimeZone((string) ($appointment['timezone'] ?: 'Europe/Berlin'));
        $time = static fn (int $timestamp): string => (new \DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format(DATE_ATOM);
        $data = [
            'id' => (int) $appointment['id'],
            'status' => (string) $appointment['status'],
            'start' => $time((int) $appointment['start']),
            'end' => $time((int) $appointment['end']),
            'timezone' => $timezone->getName(),
            'title' => (string) $appointment['title'],
            'location' => (string) $appointment['location'],
            'bookingType' => ['id' => (int) $type['id'], 'title' => (string) $type['title']],
            'createdAt' => $time((int) ($appointment['createdAt'] ?? $now)),
        ];
        if ('cancelled' === $appointment['status']) {
            $data['cancelledBy'] = (string) ($appointment['cancelledBy'] ?? '');
        }
        if ('1' === (string) $type['webhookPersonalData']) {
            $data['attendee'] = [
                'name' => (string) $appointment['name'], 'email' => (string) $appointment['email'],
                'phone' => (string) $appointment['phone'], 'note' => (string) $appointment['note'],
            ];
        }

        return ['type' => $event, 'timestamp' => $time($now), 'data' => ['appointment' => $data]];
    }

    /**
     * Sends due deliveries (all due ones, or only the given ids if they are due).
     *
     * @param list<int>|null $ids
     */
    public function deliverDue(int $now, array|null $ids = null, int $limit = 20): void
    {
        if ([] === $ids) {
            return;
        }
        $query = $this->connection->createQueryBuilder()->select('*')->from('tl_booking_webhook')
            ->where("status = 'pending'")->andWhere('nextAttemptAt <= :now')->setParameter('now', $now)
            ->orderBy('id')->setMaxResults($limit)
        ;
        if (null !== $ids) {
            $query->andWhere('id IN ('.implode(',', array_map('intval', $ids)).')');
        }

        foreach ($query->fetchAllAssociative() as $delivery) {
            // Claim: another process (cron, second request) skips it for the next minute.
            $claimed = $this->connection->executeStatement(
                "UPDATE tl_booking_webhook SET nextAttemptAt = ? WHERE id = ? AND status = 'pending' AND nextAttemptAt = ?",
                [$now + 60, (int) $delivery['id'], (int) $delivery['nextAttemptAt']],
            );
            if (1 === $claimed) {
                $this->send($delivery, $now);
            }
        }
    }

    /**
     * @param array<string, mixed> $delivery
     */
    private function send(array $delivery, int $now): void
    {
        $secret = (string) $this->connection->fetchOne('SELECT webhookSecret FROM tl_booking_type WHERE id = ?', [(int) $delivery['pid']]);
        $body = (string) $delivery['payload'];
        $code = 0;
        $error = '';

        try {
            $response = $this->httpClient->request(
                'POST',
                (string) $delivery['url'],
                [
                    'body' => $body,
                    'timeout' => self::TIMEOUT,
                    'max_duration' => self::TIMEOUT * 2,
                    'max_redirects' => 0,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'Contao-Terminbuchung/1.0',
                        'webhook-id' => (string) $delivery['deliveryId'],
                        'webhook-timestamp' => (string) $now,
                        'webhook-signature' => WebhookSignature::sign($secret, (string) $delivery['deliveryId'], $now, $body),
                    ],
                ],
            );
            $code = $response->getStatusCode();
        } catch (\Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 255);
        }
        $attempts = (int) $delivery['attempts'] + 1;
        $delivered = $code >= 200 && $code < 300;
        $delay = self::RETRY_DELAYS[$attempts - 1] ?? null;
        if (!$delivered && '' === $error) {
            $error = 'HTTP '.$code;
        }
        $this->connection->update(
            'tl_booking_webhook',
            [
                'tstamp' => $now, 'attempts' => $attempts, 'lastAttemptAt' => $now, 'responseCode' => $code, 'error' => $delivered ? '' : $error,
                'status' => $delivered ? 'delivered' : (null === $delay ? 'failed' : 'pending'),
                'nextAttemptAt' => $delivered || null === $delay ? 0 : $now + $delay,
            ],
            ['id' => (int) $delivery['id']],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function log(int $typeId, int $limit = 10): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM tl_booking_webhook WHERE pid = ? ORDER BY id DESC LIMIT '.max(1, $limit), [$typeId]);
    }

    /**
     * Keeps the log short: finished deliveries older than 30 days go.
     */
    public function prune(int $now): void
    {
        $this->connection->executeStatement("DELETE FROM tl_booking_webhook WHERE status <> 'pending' AND tstamp < ?", [$now - 30 * 86400]);
    }
}
