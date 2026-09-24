<?php

declare(strict_types=1);

namespace Texto\Exception;

/** Thrown when the API returns a non-2xx response. */
class ApiException extends TextoException
{
    /** @param array<string,mixed>|null $body */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?array $body = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null
    ) {
        parent::__construct($message, $status);
    }
}
