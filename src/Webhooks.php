<?php

declare(strict_types=1);

namespace Texto;

/** Helpers for verifying and parsing Texto webhooks. */
class Webhooks
{
    /**
     * Verify the `X-Texto-Signature` header against the raw request body.
     *
     * @param string $payload   Raw request body exactly as received.
     * @param string|null $signature Value of the `X-Texto-Signature` header.
     * @param string $secret    Signing secret shown when the webhook secret was rotated.
     */
    public static function verifySignature(string $payload, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }
        $provided = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Verify (when a secret is supplied) and decode a webhook body.
     *
     * @return array<string,mixed>
     *
     * @throws \RuntimeException when the signature does not match.
     */
    public static function parseEvent(string $payload, ?string $signature, ?string $secret): array
    {
        if ($secret !== null && $secret !== '' && !self::verifySignature($payload, $signature, $secret)) {
            throw new \RuntimeException('Texto webhook signature verification failed');
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $event */
    public static function isInboundEvent(array $event): bool
    {
        return ($event['event'] ?? null) === 'message.inbound';
    }
}
