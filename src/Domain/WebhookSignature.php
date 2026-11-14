<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Domain;

/**
 * Signature after Standard Webhooks (standardwebhooks.com): HMAC-SHA256 over
 * "<webhook-id>.<webhook-timestamp>.<body>", sent base64 encoded as
 * "v1,<signature>" in the header webhook-signature. A secret "whsec_<base64>" is
 * decoded, any other secret is used as it is, so receivers with existing
 * libraries and hand-written checks both work.
 */
final class WebhookSignature
{
    public static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, self::key($secret), true));
    }

    public static function verify(string $secret, string $id, int $timestamp, string $body, string $header, int $now, int $tolerance = 300): bool
    {
        if (abs($now - $timestamp) > $tolerance) {
            return false;
        }
        $expected = self::sign($secret, $id, $timestamp, $body);

        foreach (explode(' ', $header) as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function generateSecret(): string
    {
        return 'whsec_'.base64_encode(random_bytes(24));
    }

    private static function key(string $secret): string
    {
        if (str_starts_with($secret, 'whsec_')) {
            $decoded = base64_decode(substr($secret, 6), true);
            if (false !== $decoded && '' !== $decoded) {
                return $decoded;
            }
        }

        return $secret;
    }
}
