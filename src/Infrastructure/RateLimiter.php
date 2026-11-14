<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * Fixed-window counter per IP address in the database. The address is only stored
 * as a keyed hash that changes with every window.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $secret,
    ) {
    }

    public function allow(string $scope, string $ip, int $limit, int $window, int $now): bool
    {
        $index = intdiv($now, $window);
        $bucket = hash_hmac('sha256', $scope.'|'.$ip.'|'.$window.'|'.$index, $this->secret);
        $this->connection->executeStatement(
            'INSERT INTO tl_booking_rate (tstamp, bucket, hits, expiresAt) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$now, $bucket, ($index + 1) * $window],
        );

        return (int) $this->connection->fetchOne('SELECT hits FROM tl_booking_rate WHERE bucket = ?', [$bucket]) <= $limit;
    }

    public function prune(int $now): void
    {
        $this->connection->executeStatement('DELETE FROM tl_booking_rate WHERE expiresAt < ?', [$now]);
    }
}
