<?php

declare(strict_types=1);

namespace Texto;

/**
 * Client for the Texto SMS API.
 *
 * ```php
 * $texto = new Texto\Texto(getenv('TEXTO_API_KEY'));
 * $texto->send('+61400000000', 'Hello');
 * ```
 */
class Texto
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.texto.com.au';

    private HttpClient $http;

    /**
     * @param string $apiKey  Your Texto API key, beginning `txt_`.
     * @param string $baseUrl Override only to point at a mock server or a corporate
     *                        proxy during testing. Production is the Texto API.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?HttpClient $http = null
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('A Texto API key is required.');
        }
        $this->http = $http ?? new HttpClient($apiKey, rtrim($baseUrl, '/'), $timeout, $maxRetries);
    }

    /**
     * Perform a raw request. Exposed for endpoints not yet wrapped.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->http->request($method, $path, $query, $body);
    }

    // ── status ──────────────────────────────────────────────────────

    /** @return array<string,mixed> Public health check. Does not consume credits. */
    public function status(): array
    {
        return $this->request('GET', '/status');
    }

    // ── messaging ───────────────────────────────────────────────────

    /**
     * Send a single SMS.
     *
     * Include `{{OptOutLink}}` in the message to insert a unique per-recipient
     * opt-out link (`texto.au/xxxxxx`, 15 characters). It always shortens,
     * independent of link tracking, and is the recommended opt-out mechanism
     * for Sender ID sends, where recipients cannot reply STOP.
     *
     * @return array<string,mixed>
     */
    public function send(
        string $to,
        string $message,
        ?string $sender = null,
        ?bool $linkTracking = null,
        ?string $campaign = null
    ): array {
        return $this->request('POST', '/send', [], self::clean([
            'to' => $to,
            'message' => $message,
            'sender' => $sender,
            'link_tracking' => $linkTracking,
            'campaign' => $campaign,
        ]));
    }

    /**
     * Send one message to up to 1,000 recipients, with optional merge data.
     *
     * Merge fields use `{{key}}` syntax; `{{SendingNumber}}` is always
     * available. Include `{{OptOutLink}}` to insert a unique per-recipient
     * opt-out link (always shortened to 15 characters) — recommended for
     * Sender ID sends, where recipients cannot reply STOP.
     *
     * @param array<int,string|array<string,mixed>> $recipients
     *
     * @return array<string,mixed>
     */
    public function sendBatch(
        array $recipients,
        string $message,
        ?string $sender = null,
        ?bool $linkTracking = null,
        ?string $campaign = null
    ): array {
        return $this->request('POST', '/send-batch', [], self::clean([
            'recipients' => $recipients,
            'message' => $message,
            'sender' => $sender,
            'link_tracking' => $linkTracking,
            'campaign' => $campaign,
        ]));
    }

    /** @return array<string,mixed> Message and its delivery receipt. */
    public function getMessage(string $messageId): array
    {
        return $this->request('GET', '/message/' . rawurlencode($messageId));
    }

    /** @return array<string,mixed> Campaign with its per-message results. */
    public function getCampaign(string $campaignId, ?int $limit = null, ?int $offset = null): array
    {
        return $this->request('GET', '/campaign/' . rawurlencode($campaignId), [
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    // ── inbox and opt-outs ──────────────────────────────────────────

    /** @return array<string,mixed> Inbound (reply) messages. */
    public function inbox(
        ?int $limit = null,
        ?int $offset = null,
        ?string $from = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        return $this->request('GET', '/inbox', [
            'limit' => $limit,
            'offset' => $offset,
            'from' => $from,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    /** @return array<int,array<string,mixed>> Numbers that have opted out. */
    public function optouts(): array
    {
        return $this->request('GET', '/optouts')['optouts'] ?? [];
    }

    // ── credits ─────────────────────────────────────────────────────

    /** Current credit balance for the calling account. */
    public function balance(): int
    {
        return (int) ($this->request('GET', '/balance')['credits'] ?? 0);
    }

    /** Credit balance for a sub-account. */
    public function accountBalance(string $accountId): int
    {
        return (int) ($this->request('GET', '/account/' . rawurlencode($accountId) . '/balance')['credits'] ?? 0);
    }

    /** @return array<string,mixed> Move credits down to a sub-account. */
    public function allocateCredits(string $accountId, int $amount): array
    {
        return $this->request(
            'POST',
            '/account/' . rawurlencode($accountId) . '/credits/allocate',
            [],
            ['amount' => $amount]
        );
    }

    /** @return array<string,mixed> Pull credits back from a sub-account. */
    public function recallCredits(string $accountId, int $amount): array
    {
        return $this->request(
            'POST',
            '/account/' . rawurlencode($accountId) . '/credits/recall',
            [],
            ['amount' => $amount]
        );
    }

    // ── accounts ────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> The calling account and its sub-accounts. */
    public function listAccounts(): array
    {
        return $this->request('GET', '/accounts')['accounts'] ?? [];
    }

    /**
     * Create a sub-account. `email` is required unless managed by parent.
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function createAccount(string $businessName, array $options = []): array
    {
        return $this->request('POST', '/accounts', [], self::clean(array_merge([
            'business_name' => $businessName,
        ], $options)));
    }

    /** @return array<string,mixed> Full detail for an account. */
    public function getAccount(string $accountId): array
    {
        return $this->request('GET', '/account/' . rawurlencode($accountId))['account'] ?? [];
    }

    /**
     * Update a sub-account.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,mixed>
     */
    public function updateAccount(string $accountId, array $fields): array
    {
        return $this->request('PATCH', '/account/' . rawurlencode($accountId), [], self::clean($fields));
    }

    /** @return array<string,mixed> Schedule a sub-account for deletion. */
    public function deleteAccount(string $accountId): array
    {
        return $this->request('DELETE', '/account/' . rawurlencode($accountId));
    }

    // ── users and access ────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> Team members on the calling account. */
    public function listTeam(): array
    {
        return $this->request('GET', '/team')['users'] ?? [];
    }

    /** @return array<int,array<string,mixed>> Team members on a specific account. */
    public function listAccountUsers(string $accountId): array
    {
        return $this->request('GET', '/account/' . rawurlencode($accountId) . '/users')['users'] ?? [];
    }

    /**
     * Invite a person to an account.
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function inviteUser(string $accountId, string $email, array $options = []): array
    {
        return $this->request(
            'POST',
            '/account/' . rawurlencode($accountId) . '/users',
            [],
            self::clean(array_merge(['email' => $email], $options))
        );
    }

    /** @return array<string,mixed> Remove a team member by member row id. */
    public function removeUser(string $accountId, string $memberId): array
    {
        return $this->request(
            'DELETE',
            '/account/' . rawurlencode($accountId) . '/users/' . rawurlencode($memberId)
        );
    }

    /** @return array<string,mixed> Parent-management and inherited-team settings. */
    public function getAccess(string $accountId): array
    {
        return $this->request('GET', '/account/' . rawurlencode($accountId) . '/access');
    }

    /**
     * Update parent-management and inherited-team settings.
     *
     * @param array<string,mixed> $settings
     *
     * @return array<string,mixed>
     */
    public function setAccess(string $accountId, array $settings): array
    {
        return $this->request('PUT', '/account/' . rawurlencode($accountId) . '/access', [], self::clean($settings));
    }

    // ── API keys ────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> API keys on an account. Values are never returned. */
    public function listKeys(string $accountId): array
    {
        return $this->request('GET', '/account/' . rawurlencode($accountId) . '/keys')['keys'] ?? [];
    }

    /** @return array<string,mixed> Create an API key. The value is returned once. */
    public function createKey(string $accountId, string $name): array
    {
        return $this->request('POST', '/account/' . rawurlencode($accountId) . '/key', [], ['name' => $name]);
    }

    /** @return array<string,mixed> Revoke an API key. */
    public function revokeKey(string $accountId, string $keyId): array
    {
        return $this->request('DELETE', '/account/' . rawurlencode($accountId) . '/key/' . rawurlencode($keyId));
    }

    // ── numbers ─────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> Numbers owned by the calling account. */
    public function listNumbers(): array
    {
        return $this->request('GET', '/numbers')['numbers'] ?? [];
    }

    /** @return array<int,array<string,mixed>> Numbers across the account and its sub-accounts. */
    public function listGroupNumbers(): array
    {
        return $this->request('GET', '/numbers/group')['numbers'] ?? [];
    }

    /** @return array<int,array<string,mixed>> Numbers assigned to a specific account. */
    public function listAccountNumbers(string $accountId): array
    {
        return $this->request('GET', '/account/' . rawurlencode($accountId) . '/numbers')['numbers'] ?? [];
    }

    /** @return array<string,mixed> Numbers currently available to purchase. */
    public function availableNumbers(?string $country = null, ?int $limit = null, ?int $offset = null): array
    {
        return $this->request('GET', '/numbers/available', [
            'country' => $country,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @return array<string,mixed> Purchase a dedicated number. Charges the saved card. */
    public function purchaseNumber(?string $number = null, ?string $label = null, ?string $country = null): array
    {
        return $this->request('POST', '/numbers/purchase', [], self::clean([
            'number' => $number,
            'label' => $label,
            'country' => $country,
        ]));
    }

    /**
     * Assign parent-owned numbers to a sub-account.
     *
     * @param string|array<int,string> $numbers
     *
     * @return array<string,mixed>
     */
    public function assignNumbers(string $accountId, string|array $numbers): array
    {
        return $this->request(
            'POST',
            '/account/' . rawurlencode($accountId) . '/numbers/assign',
            [],
            ['numbers' => $numbers]
        );
    }

    /**
     * Recall numbers from a sub-account.
     *
     * @param string|array<int,string> $numbers
     *
     * @return array<string,mixed>
     */
    public function recallNumbers(string $accountId, string|array $numbers): array
    {
        return $this->request(
            'POST',
            '/account/' . rawurlencode($accountId) . '/numbers/recall',
            [],
            ['numbers' => $numbers]
        );
    }

    // ── webhooks ────────────────────────────────────────────────────

    /** @return array<string,mixed> Configured delivery receipt and inbound webhooks. */
    public function getWebhooks(): array
    {
        return $this->request('GET', '/webhooks');
    }

    /** @return array<string,mixed> Create or update the delivery receipt webhook. */
    public function setDeliveryWebhook(string $url, ?bool $enabled = null): array
    {
        return $this->request('PUT', '/webhooks/delivery', [], self::clean([
            'url' => $url,
            'enabled' => $enabled,
        ]))['delivery_receipt'] ?? [];
    }

    /** @return array<string,mixed> Create or update the inbound message webhook. */
    public function setInboundWebhook(string $url, ?bool $enabled = null): array
    {
        return $this->request('PUT', '/webhooks/inbound', [], self::clean([
            'url' => $url,
            'enabled' => $enabled,
        ]))['inbound'] ?? [];
    }

    /** Rotate the delivery receipt signing secret. Returned once. */
    public function rotateDeliverySecret(): string
    {
        return (string) ($this->request('POST', '/webhooks/delivery')['secret'] ?? '');
    }

    /** Rotate the inbound signing secret. Returned once. */
    public function rotateInboundSecret(): string
    {
        return (string) ($this->request('POST', '/webhooks/inbound')['secret'] ?? '');
    }

    // ── reporting ───────────────────────────────────────────────────

    /**
     * Report for the calling account.
     *
     * @param array<string,mixed> $filters from, to, direction, status, country, campaign_id, keyword, number
     *
     * @return array<string,mixed>
     */
    public function report(array $filters = []): array
    {
        return $this->request('GET', '/report', $filters);
    }

    /**
     * Report for a specific account in the hierarchy.
     *
     * @param array<string,mixed> $filters
     *
     * @return array<string,mixed>
     */
    public function accountReport(string $accountId, array $filters = []): array
    {
        return $this->request('GET', '/account/' . rawurlencode($accountId) . '/report', $filters);
    }

    /**
     * Report across the calling account and all its sub-accounts.
     *
     * @return array<string,mixed>
     */
    public function groupReport(?string $from = null, ?string $to = null): array
    {
        return $this->request('GET', '/report/group', ['from' => $from, 'to' => $to]);
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private static function clean(array $data): array
    {
        return array_filter($data, static fn ($value) => $value !== null);
    }
}
