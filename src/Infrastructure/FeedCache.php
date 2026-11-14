<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nordwerk\BookingBundle\Domain\Availability;
use Nordwerk\BookingBundle\Domain\FeedParser;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Busy times from calendar subscriptions, cached per booking type and address. A
 * feed that cannot be reached keeps its last busy times and records the error for
 * the back end; it never blocks free times or a booking.
 */
final class FeedCache
{
    /**
     * Fetch a feed again after this many seconds.
     */
    public const TTL = 300;

    private const TIMEOUT = 4;

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $type
     *
     * @return list<array{start: int, end: int}>
     */
    public function busy(array $type, int $now, bool $refresh): array
    {
        $urls = BookingTypes::urls((string) $type['feeds']);
        if ([] === $urls) {
            return [];
        }
        if ($refresh) {
            $this->refresh($type, $now);
        }
        $busy = [];

        foreach ($this->rows((int) $type['id'], $urls) as $row) {
            $ranges = json_decode((string) $row['busy'], true);

            foreach (\is_array($ranges) ? $ranges : [] as $range) {
                if (\is_array($range) && isset($range[0], $range[1])) {
                    $busy[] = ['start' => (int) $range[0], 'end' => (int) $range[1]];
                }
            }
        }

        return $busy;
    }

    /**
     * Fetches the feeds of a type whose cache is older than the TTL (or all with $force).
     *
     * @param array<string, mixed> $type
     */
    public function refresh(array $type, int $now, bool $force = false): void
    {
        $availability = Availability::fromRow($type);
        $from = $now - 86400;
        $to = $now + ($availability->horizonDays + 2) * 86400;
        $cached = [];

        foreach ($this->rows((int) $type['id'], BookingTypes::urls((string) $type['feeds'])) as $row) {
            $cached[(string) $row['urlHash']] = $row;
        }

        foreach (BookingTypes::urls((string) $type['feeds']) as $url) {
            $hash = hash('sha256', $url);
            $row = $cached[$hash] ?? null;
            if (!$force && null !== $row && (int) $row['checkedAt'] > $now - self::TTL) {
                continue;
            }
            $values = ['tstamp' => $now, 'checkedAt' => $now];

            try {
                $response = $this->httpClient->request(
                    'GET',
                    preg_replace('~^webcal://~i', 'https://', $url) ?? $url,
                    [
                        'timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT * 2,
                        'headers' => ['Accept' => 'text/calendar, */*;q=0.5', 'User-Agent' => 'Contao-Terminbuchung/1.0'],
                    ],
                );
                if (200 !== $response->getStatusCode()) {
                    throw new \RuntimeException('HTTP '.$response->getStatusCode());
                }
                $ranges = FeedParser::busy($response->getContent(), $from, $to, $availability->timezone);
                $values += ['lastSuccessAt' => $now, 'error' => '', 'busy' => json_encode(array_map(static fn (array $range): array => [$range['start'], $range['end']], $ranges), JSON_THROW_ON_ERROR)];
            } catch (\Throwable $exception) {
                $message = $exception instanceof \UnexpectedValueException ? 'Keine gültigen Kalenderdaten' : $exception->getMessage();
                $values['error'] = mb_substr($message, 0, 255);
                $this->logger?->warning('Booking calendar feed could not be read.', ['type' => (int) $type['id'], 'error' => $values['error']]);
            }
            if (null === $row) {
                $this->connection->insert('tl_booking_feed', ['pid' => (int) $type['id'], 'url' => $url, 'urlHash' => $hash, 'busy' => '[]', ...$values]);
            } else {
                $this->connection->update('tl_booking_feed', $values, ['id' => (int) $row['id']]);
            }
        }
    }

    /**
     * @param list<string> $urls
     *
     * @return list<array<string, mixed>>
     */
    public function rows(int $typeId, array $urls): array
    {
        if ([] === $urls) {
            return [];
        }
        $hashes = array_map(static fn (string $url): string => hash('sha256', $url), $urls);

        return $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_booking_feed WHERE pid = ? AND urlHash IN (?)',
            [$typeId, $hashes],
            [ParameterType::INTEGER, ArrayParameterType::STRING],
        );
    }

    /**
     * True if a feed of the type currently fails.
     *
     * @param array<string, mixed> $type
     */
    public function hasError(array $type): bool
    {
        foreach ($this->rows((int) $type['id'], BookingTypes::urls((string) $type['feeds'])) as $row) {
            if ('' !== (string) $row['error']) {
                return true;
            }
        }

        return false;
    }
}
