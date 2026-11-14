<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Infrastructure;

use Symfony\Component\Process\Process;

/**
 * Two visitors book the same slot at the same moment: both requests wait on the
 * lock of the booking type, exactly one gets the appointment, the other "taken".
 */
final class ConcurrentBookingTest extends DatabaseTestCase
{
    public function testConcurrentRequestsForTheSameSlotBookItOnce(): void
    {
        $params = json_encode(self::connectionParams(), JSON_THROW_ON_ERROR);
        $code = 'require "app/vendor/autoload.php";'
            .' $c = \Doctrine\DBAL\DriverManager::getConnection(json_decode($argv[1], true));'
            .' $a = new \Nordwerk\BookingBundle\Infrastructure\Appointments($c, new \Nordwerk\BookingBundle\Infrastructure\WebhookOutbox($c, new \Symfony\Component\HttpClient\MockHttpClient()));'
            .' $t = $c->fetchAssociative("SELECT * FROM tl_booking_type WHERE id = ?", [(int) $argv[2]]);'
            .' $r = $a->book($t, (int) $argv[3], ["name" => "Gast", "email" => $argv[4], "phone" => "", "note" => "", "locale" => "de", "pageUrl" => ""], [], (int) $argv[5]);'
            .' echo $r->ok ? "ok" : $r->reason;';
        $processes = [];
        $this->connection->beginTransaction();

        try {
            $this->connection->fetchOne('SELECT id FROM tl_booking_type WHERE id = ? FOR UPDATE', [(int) $this->type['id']]);

            foreach (['a@example.test', 'b@example.test'] as $email) {
                $process = new Process([PHP_BINARY, '-r', $code, $params, (string) $this->type['id'], (string) self::TUESDAY_10, $email, (string) self::NOW], \dirname(__DIR__, 3));
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;

            do {
                $waiting = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE 'SELECT id FROM tl_booking_type WHERE id = ? AND published%' OR INFO LIKE 'SELECT id FROM tl_booking_type WHERE id = % AND published%'");
                if (2 === $waiting) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->connection->commit();
            $this->assertSame(2, $waiting, 'Both requests must wait on the booking type lock.');
            $outcomes = [];

            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $outcomes[] = $process->getOutput();
            }
            sort($outcomes);
            $this->assertSame(['ok', 'taken'], $outcomes);
            $this->assertSame(1, $this->appointmentCount());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            foreach ($processes as $process) {
                $process->stop();
            }
        }
    }
}
