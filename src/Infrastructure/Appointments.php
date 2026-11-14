<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Infrastructure;

use Doctrine\DBAL\Connection;
use Nordwerk\BookingBundle\Domain\Availability;
use Nordwerk\BookingBundle\Domain\BookingResult;
use Nordwerk\BookingBundle\Domain\BookingRules;
use Nordwerk\BookingBundle\Domain\SlotCalculator;

/**
 * Appointments of the booking types. A booking runs in one transaction under a
 * lock on the booking type row: concurrent requests for the same type wait for
 * each other, so the second one sees the first appointment and gets "taken".
 */
final class Appointments
{
    /**
     * An appointment is at most this long; the busy query must look back that far.
     */
    private const LOOKBACK = 12 * 3600;

    public function __construct(
        private readonly Connection $connection,
        private readonly WebhookOutbox $webhooks,
    ) {
    }

    /**
     * Confirmed appointments of a booking type that reach into [from, to).
     *
     * @return list<array{start: int, end: int}>
     */
    public function busy(int $typeId, int $from, int $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT start, end FROM tl_booking_appointment WHERE pid = ? AND status = 'confirmed' AND start >= ? AND start < ? AND end > ?",
            [$typeId, $from - self::LOOKBACK, $to, $from],
        );

        return array_map(static fn (array $row): array => ['start' => (int) $row['start'], 'end' => (int) $row['end']], $rows);
    }

    /**
     * Books a slot that is free right now under the lock; anything else is "taken".
     *
     * @param array<string, mixed>                                                                             $type
     * @param array{name: string, email: string, phone: string, note: string, locale: string, pageUrl: string} $guest
     * @param list<array{start: int, end: int}>                                                                $external busy times from calendar subscriptions
     */
    public function book(array $type, int $start, array $guest, array $external, int $now): BookingResult
    {
        $availability = Availability::fromRow($type);

        return $this->connection->transactional(
            function () use ($type, $start, $guest, $external, $now, $availability): BookingResult {
                // Lock first; the reads below then see every appointment committed before.
                $locked = $this->connection->fetchOne("SELECT id FROM tl_booking_type WHERE id = ? AND published = '1' FOR UPDATE", [(int) $type['id']]);
                if (false === $locked) {
                    return BookingResult::refused('unavailable');
                }
                $horizonEnd = $now + ($availability->horizonDays + 1) * 86400;
                $busy = [...$this->busy((int) $type['id'], $now, $horizonEnd), ...$external];
                $slot = null;

                foreach (SlotCalculator::freeSlots($availability, $busy, $now) as $free) {
                    if ($free['start'] === $start) {
                        $slot = $free;
                        break;
                    }
                }
                if (null === $slot) {
                    return BookingResult::refused('taken');
                }
                $perEmail = (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM tl_booking_appointment WHERE pid = ? AND status = 'confirmed' AND email = ? AND end > ?",
                    [(int) $type['id'], $guest['email'], $now],
                );
                $perDay = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM tl_booking_appointment WHERE pid = ? AND createdAt > ?',
                    [(int) $type['id'], $now - 86400],
                );
                if ($perEmail >= BookingRules::MAX_PER_EMAIL || $perDay >= BookingRules::MAX_PER_DAY) {
                    return BookingResult::refused('limit');
                }
                $this->connection->insert('tl_booking_appointment', [
                    'pid' => (int) $type['id'], 'tstamp' => $now, 'status' => 'confirmed',
                    'start' => $slot['start'], 'end' => $slot['end'],
                    'title' => (string) $type['title'], 'location' => (string) $type['location'], 'timezone' => $availability->timezone,
                    'name' => $guest['name'], 'email' => $guest['email'], 'phone' => $guest['phone'], 'note' => $guest['note'],
                    'locale' => $guest['locale'], 'pageUrl' => mb_substr($guest['pageUrl'], 0, 1024),
                    'token' => bin2hex(random_bytes(32)), 'uid' => bin2hex(random_bytes(16)).'@terminbuchung',
                    'createdAt' => $now, 'consentAt' => $now,
                ]);
                $appointment = $this->find((int) $this->connection->lastInsertId());
                if (null === $appointment) {
                    throw new \RuntimeException('The appointment was not stored.');
                }
                $this->webhooks->queue($type, 'appointment.booked', $appointment, $now);

                return BookingResult::booked($appointment);
            },
        );
    }

    /**
     * Cancels a confirmed appointment once and queues the webhook. False if it was
     * not confirmed.
     *
     * @param 'guest'|'backend' $by
     */
    public function cancel(int $id, string $by, int $now): bool
    {
        return $this->connection->transactional(
            function () use ($id, $by, $now): bool {
                $changed = $this->connection->executeStatement(
                    "UPDATE tl_booking_appointment SET status = 'cancelled', cancelledAt = ?, cancelledBy = ?, tstamp = ? WHERE id = ? AND status = 'confirmed'",
                    [$now, $by, $now, $id],
                );
                $appointment = $this->find($id);
                $type = null === $appointment ? false : $this->connection->fetchAssociative('SELECT * FROM tl_booking_type WHERE id = ?', [(int) $appointment['pid']]);
                if (1 !== $changed || null === $appointment || false === $type) {
                    return false;
                }
                $this->webhooks->queue($type, 'appointment.cancelled', $appointment, $now);

                return true;
            },
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_booking_appointment WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): array|null
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_booking_appointment WHERE token = ? AND anonymizedAt = 0', [$token]);

        return false === $row ? null : $row;
    }

    /**
     * Marks a mail as sent; false if another process sent it already.
     */
    public function claimMail(int $id, string $column, int $now): bool
    {
        if (!\in_array($column, ['confirmationSentAt', 'merchantSentAt', 'cancellationSentAt'], true)) {
            throw new \InvalidArgumentException('Unknown mail column.');
        }

        return 1 === $this->connection->executeStatement("UPDATE tl_booking_appointment SET $column = ? WHERE id = ? AND $column = 0", [$now, $id]);
    }

    public function releaseMail(int $id, string $column): void
    {
        if (!\in_array($column, ['confirmationSentAt', 'merchantSentAt', 'cancellationSentAt'], true)) {
            throw new \InvalidArgumentException('Unknown mail column.');
        }
        $this->connection->executeStatement("UPDATE tl_booking_appointment SET $column = 0, mailAttempts = mailAttempts + 1 WHERE id = ?", [$id]);
    }

    /**
     * Appointments whose mails are still due (failed earlier), for the cron job.
     *
     * @return list<array<string, mixed>>
     */
    public function withPendingMails(int $now): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT * FROM tl_booking_appointment WHERE anonymizedAt = 0 AND mailAttempts < 5 AND createdAt < ? AND end > ?
             AND ((status = 'confirmed' AND (confirmationSentAt = 0 OR merchantSentAt = 0)) OR (status = 'cancelled' AND cancellationSentAt = 0))
             LIMIT 20",
            [$now - 60, $now - 86400],
        );
    }

    /**
     * Removes the personal data of appointments that ended longer ago than the type
     * keeps them.
     */
    public function anonymizeExpired(int $now): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE tl_booking_appointment a INNER JOIN tl_booking_type t ON t.id = a.pid
             SET a.name = '', a.email = '', a.phone = '', a.note = NULL, a.token = NULL, a.anonymizedAt = ?
             WHERE a.anonymizedAt = 0 AND a.end < ? - t.retentionDays * 86400",
            [$now, $now],
        );
    }
}
