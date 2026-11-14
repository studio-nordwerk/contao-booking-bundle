<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Infrastructure;

use Nordwerk\BookingBundle\Domain\WebhookSignature;
use Nordwerk\BookingBundle\Infrastructure\WebhookOutbox;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookDeliveryTest extends DatabaseTestCase
{
    public function testSignsTheDeliveryAndRetriesADeadEndpointUntilItGivesUp(): void
    {
        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = $options;

                return new MockResponse('down', ['http_code' => 503]);
            },
        );
        // The booking itself never fails on the endpoint.
        $this->assertSame('ok', $this->book($this->bookings($client)));
        $outbox = new WebhookOutbox($this->connection, $client);
        $now = self::NOW;
        $outbox->deliverDue($now);

        foreach (WebhookOutbox::RETRY_DELAYS as $delay) {
            $outbox->deliverDue($now + $delay - 1);
            $now += $delay;
            $outbox->deliverDue($now);
        }
        $delivery = $this->connection->fetchAssociative('SELECT * FROM tl_booking_webhook WHERE pid = ?', [(int) $this->type['id']]);
        $this->assertIsArray($delivery);
        $this->assertSame('failed', $delivery['status']);
        $this->assertSame(\count(WebhookOutbox::RETRY_DELAYS) + 1, (int) $delivery['attempts']);
        $this->assertCount(\count(WebhookOutbox::RETRY_DELAYS) + 1, $requests);

        $headers = [];

        foreach ($requests[0]['headers'] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }
        $this->assertTrue(WebhookSignature::verify('geheim', $headers['webhook-id'], (int) $headers['webhook-timestamp'], $requests[0]['body'], $headers['webhook-signature'], (int) $headers['webhook-timestamp']));
        $payload = json_decode($requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('appointment.booked', $payload['type']);
        $this->assertSame('2026-11-10T10:00:00+01:00', $payload['data']['appointment']['start']);
    }

    public function testLeavesOutContactDetailsIfTheTypeSaysSo(): void
    {
        $this->type['webhookPersonalData'] = '';
        $payload = WebhookOutbox::payload(
            $this->type,
            'appointment.booked',
            [
                'id' => 1, 'status' => 'confirmed', 'start' => self::TUESDAY_10, 'end' => self::TUESDAY_10 + 1800, 'timezone' => 'Europe/Berlin',
                'title' => 'Erstgespräch', 'location' => '', 'name' => 'Erika', 'email' => 'erika@example.test', 'phone' => '', 'note' => '', 'createdAt' => self::NOW,
            ],
            self::NOW,
        );
        $this->assertDoesNotMatchRegularExpression('/Erika|example\.test/', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
