# Texto PHP SDK

Official PHP SDK for the [Texto](https://texto.com.au) SMS API.

## Requirements

- PHP 8.0 or newer
- `ext-curl` and `ext-json`

## Install

```bash
composer require texto/sdk
```

## Quick start

```php
<?php
require 'vendor/autoload.php';

$texto = new Texto\Texto(getenv('TEXTO_API_KEY'));

$result = $texto->send('+61400000000', 'Hello from Texto');
echo $result['message_id'];

echo $texto->balance(), " credits remaining\n";
```

## Sending to many recipients

```php
// Tip: include {{OptOutLink}} in the message to add a unique per-recipient
// opt-out link (texto.au/xxxxxx, 15 chars, always shortened) — recommended
// for Sender ID sends, where recipients can't reply STOP.
$texto->sendBatch(
    [
        ['to' => '+61400000000', 'name' => 'Ada'],
        ['to' => '+61400000001', 'name' => 'Grace'],
    ],
    'Hi {name}, your appointment is tomorrow.'
);
```

Plain strings work too when you are not using merge fields.

## Errors

```php
use Texto\Exception\InsufficientCreditsException;
use Texto\Exception\ApiException;

try {
    $texto->send('+61400000000', 'Hello');
} catch (InsufficientCreditsException $e) {
    echo "Need {$e->creditsRequired()} credits, have {$e->creditsAvailable()}";
} catch (ApiException $e) {
    echo "{$e->status}: {$e->getMessage()}";
}
```

## Sub-accounts

```php
$account = $texto->createAccount('Acme Pty Ltd', ['email' => 'ops@acme.com.au']);
$texto->allocateCredits($account['account']['id'], 500);
$texto->listAccounts();
```

## Webhooks

```php
use Texto\Webhooks;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_TEXTO_SIGNATURE'] ?? null;

$event = Webhooks::parseEvent($payload, $signature, getenv('TEXTO_WEBHOOK_SECRET'));

if (Webhooks::isInboundEvent($event)) {
    // handle the reply
}
```

## Configuration

```php
$texto = new Texto\Texto(
    apiKey: getenv('TEXTO_API_KEY'),
    baseUrl: 'https://api.texto.com.au', // only change for a mock server or test proxy
    timeout: 30.0,
    maxRetries: 2
);
```

Idempotent requests are retried automatically with backoff.

## Support

- Docs: https://texto.com.au/developers
- Email: support@texto.com.au

MIT licensed. Copyright (c) 2026 Floop Pty Ltd trading as Texto.
