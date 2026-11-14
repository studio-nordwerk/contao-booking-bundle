<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nordwerk\BookingBundle\Infrastructure\AfterResponse;
use Nordwerk\BookingBundle\Infrastructure\Appointments;
use Nordwerk\BookingBundle\Infrastructure\Bookings;
use Nordwerk\BookingBundle\Infrastructure\FeedCache;
use Nordwerk\BookingBundle\Infrastructure\WebhookOutbox;
use Nordwerk\BookingBundle\Mail\BookingMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Runs against the development database (DATABASE_URL) and removes its own
 * records. Booking type as in core's booking test: Tuesdays 10 to 12, 30 minutes,
 * 24 hours lead time, 14 days ahead. Now is Monday 09.11.2026, 09:00 Berlin: four
 * slots on 10.11. and 17.11.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const NOW = 1794211200;

    protected const TUESDAY_10 = 1794301200;

    protected const HALF_HOUR = 1800;

    protected Connection $connection;

    /**
     * @var array<string, mixed>
     */
    protected array $type;

    /**
     * @return array<string, mixed>
     */
    public static function connectionParams(): array
    {
        $database = parse_url((string) getenv('DATABASE_URL'));
        if (!\is_array($database) || !isset($database['host'], $database['path'], $database['user'], $database['pass'])) {
            self::markTestSkipped('DATABASE_URL is missing.');
        }

        return [
            'driver' => 'pdo_mysql', 'host' => $database['host'], 'port' => $database['port'] ?? 3306,
            'dbname' => ltrim((string) $database['path'], '/'), 'user' => rawurldecode((string) $database['user']),
            'password' => rawurldecode((string) $database['pass']), 'charset' => 'utf8mb4',
        ];
    }

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(self::connectionParams());
        $this->connection->insert('tl_booking_type', [
            'tstamp' => self::NOW, 'title' => 'Test '.bin2hex(random_bytes(4)), 'businessName' => 'Praxis', 'businessEmail' => 'praxis@example.test',
            'duration' => 30, 'buffer' => 0, 'leadTime' => 24, 'horizon' => 14, 'timezone' => 'Europe/Berlin',
            'tuesday' => '10:00–12:00', 'webhooks' => 'http://webhook.invalid/hook', 'webhookSecret' => 'geheim', 'published' => '1',
        ]);
        $type = $this->connection->fetchAssociative('SELECT * FROM tl_booking_type WHERE id = ?', [(int) $this->connection->lastInsertId()]);
        $this->assertIsArray($type);
        $this->type = $type;
    }

    protected function tearDown(): void
    {
        foreach (['tl_booking_appointment', 'tl_booking_webhook', 'tl_booking_feed'] as $table) {
            $this->connection->delete($table, ['pid' => (int) $this->type['id']]);
        }
        $this->connection->delete('tl_booking_type', ['id' => (int) $this->type['id']]);
    }

    protected function bookings(HttpClientInterface|null $httpClient = null): Bookings
    {
        $httpClient ??= new MockHttpClient();
        $outbox = new WebhookOutbox($this->connection, $httpClient);
        $appointments = new Appointments($this->connection, $outbox);
        $mailer = new BookingMailer(new NullTransport(), $appointments, $this->connection, $this->createStub(TranslatorInterface::class), new Environment(new ArrayLoader()), $this->createStub(UrlGeneratorInterface::class));

        return new Bookings($appointments, new FeedCache($this->connection, $httpClient), new AfterResponse($mailer, $outbox));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function book(Bookings $bookings, array $overrides = []): string
    {
        $result = $bookings->book(
            $this->type,
            [
                'start' => self::TUESDAY_10 * 1000, 'name' => 'Erika Muster', 'email' => 'erika@example.test',
                'privacy' => true, '_hp' => '', ...$overrides,
            ],
            'de',
            'https://praxis.example/termin',
            self::NOW,
        );

        return $result->ok ? 'ok' : $result->reason;
    }

    protected function appointmentCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_booking_appointment WHERE pid = ? AND status = 'confirmed'", [(int) $this->type['id']]);
    }
}
