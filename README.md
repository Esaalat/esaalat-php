# esaalat/esaalat-php

**Server-side [Esaalat](https://esaalat.com) client for PHP.** It does the three
things a merchant backend always writes by hand — the 1-hour token, the
idempotency key, and the webhook HMAC — so an integration is three calls
instead of forty lines of `curl`.

- **Locked checkout sessions** — create the session on your server, give the
  browser only the token.
- **Payment intents** — create one server-side for platform orders, attach the
  buyer's reference, read status.
- **Signed webhooks** — verify `X-Esaalat-Signature` over the raw body, with a
  replay window.
- **No dependencies** — PHP 8.1+, curl or plain streams, nothing else.

## Install

```bash
composer require esaalat/esaalat-php
```

## Quick start

```php
use Esaalat\Client;
use Esaalat\EsaalatException;

// Reads ESAALAT_CLIENT_ID, ESAALAT_CLIENT_SECRET and ESAALAT_WEBHOOK_SECRET.
$esaalat = Client::fromEnv();

// 1. Your server decides the amount; the browser gets only the token.
$session = $esaalat->createSession([
    'orderId' => 'order-123',
    'amount'  => 5000,          // whole riyals
]);
// $session['session_token'] → checkout.open({ sessionToken }) in the browser
// $session['portal_url']    → or send the buyer straight to this page

// 2. Fulfil on the signed webhook, never on the buyer's page.
try {
    $event = $esaalat->verifyWebhook(
        file_get_contents('php://input'),                  // the RAW body
        $_SERVER['HTTP_X_ESAALAT_SIGNATURE'] ?? ''
    );
} catch (EsaalatException $error) {
    http_response_code(400);
    exit;
}

if ($event['event'] === 'payment.verified') {
    markOrderPaid($event['order_id']);                     // idempotent on intent_id
}
http_response_code(200);
```

Also available: `createIntent([...])`, `retrieveIntent($id)`,
`attachReference($id, $reference)` and `Client::formatAmount($value)`.

## What it handles for you

| | |
|---|---|
| **Token** | Issued once per client, cached, refreshed a minute before the JWT's own `exp`. |
| **Idempotency** | A UUID per create call, reused on every retry — a 429 or a timeout can never double-book an order. |
| **Retries** | `429`, `5xx` and network failures, honouring `Retry-After`. |
| **Errors** | `EsaalatException` with `errorCode()`, `status()`, `detail()`, `attr()` and `errors()`. |
| **Webhooks** | `hash_equals`, optional ±5 min window, a `webhook`-coded exception you answer with 400. |

## Configuration

| Argument | Environment | Default |
|---|---|---|
| `$clientId` | `ESAALAT_CLIENT_ID` | — (required) |
| `$clientSecret` | `ESAALAT_CLIENT_SECRET` | — (required) |
| `$webhookSecret` | `ESAALAT_WEBHOOK_SECRET` | — (needed by `verifyWebhook`) |
| `$baseUrl` | `ESAALAT_BASE_URL` | `https://api.esaalat.com/api/v1` |
| `$timeout` | — | `20` seconds |
| `$maxRetries` | — | `2` |

`verifyWebhook()` also takes a per-call `$secret`, because each webhook endpoint
on the dashboard has its own signing secret.

`fromEnv()` reads the process environment and `$_ENV`/`$_SERVER`, so a value
loaded from a `.env` file by Laravel or Symfony is picked up without exporting
anything by hand.

## Notes

- The client secret is a **server** secret. Never call this from a browser,
  never put it in a template variable; the browser only needs the session
  token.
- Amounts are whole Yemeni riyals: `Client::formatAmount(5000.5)` refuses
  before the request leaves your process.
- The webhook body must be the **raw** input: `json_encode($_POST)` will not
  match the signature.

## Development

```bash
php tests/smoke.php
```

## License

MIT © Esaalat
