<?php

declare(strict_types=1);

namespace Texto;

use Texto\Exception\ApiException;
use Texto\Exception\AuthenticationException;
use Texto\Exception\ConnectionException;
use Texto\Exception\InsufficientCreditsException;
use Texto\Exception\InvalidRequestException;
use Texto\Exception\NotFoundException;
use Texto\Exception\PermissionException;
use Texto\Exception\RateLimitException;
use Texto\Exception\ServerException;

/**
 * Minimal cURL transport with retries for idempotent requests.
 *
 * @internal
 */
class HttpClient
{
    private const RETRYABLE = [408, 409, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries
    ) {
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null
    ): array {
        $query = array_filter($query, static fn ($value) => $value !== null);
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: texto-php/' . Texto::VERSION,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $idempotent = in_array($method, ['GET', 'PUT', 'DELETE'], true) || $idempotencyKey !== null;
        $retries = $idempotent ? $this->maxRetries : 0;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if ($attempt > 0) {
                usleep((int) min(2 ** $attempt * 250_000, 4_000_000));
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => (int) ceil($this->timeout),
                CURLOPT_HEADER => true,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            }

            $raw = curl_exec($ch);
            if ($raw === false) {
                $error = curl_error($ch);
                curl_close($ch);
                if ($attempt < $retries) {
                    continue;
                }
                throw new ConnectionException("Request to {$path} failed: {$error}");
            }

            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr((string) $raw, 0, $headerSize);
            $content = substr((string) $raw, $headerSize);
            curl_close($ch);

            $decoded = $content === '' ? [] : json_decode($content, true);
            if (!is_array($decoded)) {
                $decoded = ['error' => $content];
            }

            if ($status >= 200 && $status < 300) {
                return $decoded;
            }

            if (in_array($status, self::RETRYABLE, true) && $attempt < $retries) {
                continue;
            }

            throw self::errorFor(
                $status,
                $decoded,
                self::headerValue($rawHeaders, 'x-request-id'),
                self::headerValue($rawHeaders, 'retry-after')
            );
        }

        throw new ConnectionException("Request to {$path} failed");
    }

    /** @param array<string,mixed> $body */
    private static function errorFor(int $status, array $body, ?string $requestId, ?string $retryAfter): ApiException
    {
        $message = is_string($body['error'] ?? null)
            ? $body['error']
            : "Texto API request failed with status {$status}";
        $code = is_string($body['code'] ?? null) ? $body['code'] : null;

        return match (true) {
            $status === 401 => new AuthenticationException($message, $status, $body, $code, $requestId),
            $status === 402 => new InsufficientCreditsException($message, $status, $body, $code, $requestId),
            $status === 403 => new PermissionException($message, $status, $body, $code, $requestId),
            $status === 404 => new NotFoundException($message, $status, $body, $code, $requestId),
            $status === 429 => new RateLimitException(
                $message,
                $status,
                $body,
                $code,
                $requestId,
                is_numeric($retryAfter) ? (int) $retryAfter : null
            ),
            $status >= 500 => new ServerException($message, $status, $body, $code, $requestId),
            default => new InvalidRequestException($message, $status, $body, $code, $requestId),
        };
    }

    private static function headerValue(string $rawHeaders, string $name): ?string
    {
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }
}
