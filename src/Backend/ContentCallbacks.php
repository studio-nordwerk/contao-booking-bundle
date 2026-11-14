<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Backend;

use Doctrine\DBAL\Connection;

final class ContentCallbacks
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string>
     */
    public function bookingTypes(): array
    {
        $options = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, published FROM tl_booking_type ORDER BY title') as $row) {
            $options[(int) $row['id']] = (string) $row['title'].('1' === (string) $row['published'] ? '' : ' (nicht veröffentlicht)');
        }

        return $options;
    }
}
