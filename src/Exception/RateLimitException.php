<?php

declare(strict_types=1);

namespace Texto\Exception;

/** The API is rate limiting the caller (429). */
class RateLimitException extends ApiException
{
    /** @param array<string,mixed>|null $body */
    public function __construct(
        string $message,
        int $status,
        ?array $body = null,
        ?string $errorCode = null,
        ?string $requestId = null,
        public readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $status, $body, $errorCode, $requestId);
    }
}
