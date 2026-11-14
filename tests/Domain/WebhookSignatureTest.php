<?php

declare(strict_types=1);

namespace Nordwerk\BookingBundle\Tests\Domain;

use Nordwerk\BookingBundle\Domain\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function testMatchesTheStandardWebhooksTestVector(): void
    {
        $this->assertSame(
            'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
            WebhookSignature::sign('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 'msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, '{"test": 2432232314}'),
        );
    }

    public function testRejectsChangedBodiesOldTimestampsAndOtherSecrets(): void
    {
        $secret = WebhookSignature::generateSecret();
        $now = 1_800_000_000;
        $header = WebhookSignature::sign($secret, 'msg_1', $now, '{"a":1}');
        $this->assertTrue(WebhookSignature::verify($secret, 'msg_1', $now, '{"a":1}', 'v1,ungueltig '.$header, $now + 10));
        $this->assertFalse(WebhookSignature::verify($secret, 'msg_1', $now, '{"a":2}', $header, $now));
        $this->assertFalse(WebhookSignature::verify($secret, 'msg_2', $now, '{"a":1}', $header, $now));
        $this->assertFalse(WebhookSignature::verify($secret, 'msg_1', $now, '{"a":1}', $header, $now + 3600));
        $this->assertFalse(WebhookSignature::verify(WebhookSignature::generateSecret(), 'msg_1', $now, '{"a":1}', $header, $now));
    }

    public function testUsesASecretWithoutPrefixAsItIs(): void
    {
        $this->assertSame('v1,'.base64_encode(hash_hmac('sha256', 'id.1.body', 'geheim', true)), WebhookSignature::sign('geheim', 'id', 1, 'body'));
    }
}
