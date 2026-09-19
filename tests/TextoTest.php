<?php

declare(strict_types=1);

namespace Texto\Tests;

use PHPUnit\Framework\TestCase;
use Texto\Webhooks;

class TextoTest extends TestCase
{
    public function testConstructorRejectsEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \Texto\Texto('');
    }

    public function testVerifySignature(): void
    {
        $body = json_encode(['event' => 'message.inbound']);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'secret');

        $this->assertTrue(Webhooks::verifySignature($body, $signature, 'secret'));
        $this->assertFalse(Webhooks::verifySignature($body, $signature, 'other'));
        $this->assertFalse(Webhooks::verifySignature($body, null, 'secret'));
    }

    public function testParseEventRejectsBadSignature(): void
    {
        $this->expectException(\RuntimeException::class);
        Webhooks::parseEvent('{}', 'sha256=deadbeef', 'secret');
    }

    public function testIsInboundEvent(): void
    {
        $this->assertTrue(Webhooks::isInboundEvent(['event' => 'message.inbound']));
        $this->assertFalse(Webhooks::isInboundEvent(['message' => []]));
    }
}
