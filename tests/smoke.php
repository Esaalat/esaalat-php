<?php

declare(strict_types=1);

/**
 * Dependency-free smoke test: php sdk/php/tests/smoke.php
 *
 * Covers the behaviour a merchant actually depends on — one token, a stable
 * idempotency key across a retry, the amount as a whole-number string, and
 * webhook verification — without needing PHPUnit or the network.
 */

require __DIR__ . '/../src/EsaalatException.php';
require __DIR__ . '/../src/Client.php';

use Esaalat\Client;
use Esaalat\EsaalatException;

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    if ($condition) {
        echo "ok   " . $label . PHP_EOL;
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL " . $label . PHP_EOL);
}

$responses = [
    [200, [], json_encode(['access_token' => 'token-abc'])],
    [429, ['retry-after' => '0'], '{}'],
    [201, [], json_encode(['session_token' => 's-1'])],
    [201, [], json_encode(['session_token' => 's-2'])],
];
$calls = [];

$transport = static function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$responses, &$calls): array {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    $response = count($responses) > 1 ? array_shift($responses) : $responses[0];

    return $response;
};

$client = new Client('cid', 'secret', 'https://api.esaalat.com/api/v1', 'whsec_test', 20, 2, $transport);

$session = $client->createSession(['orderId' => 'order-123', 'amount' => 5000]);
check('session returned', ($session['session_token'] ?? null) === 's-1');

$tokenCalls = array_values(array_filter($calls, static fn (array $call): bool => str_ends_with($call['url'], '/merchant/token')));
check('one token request', count($tokenCalls) === 1);

$creates = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/sessions/')));
check('retried after 429', count($creates) === 2);
check(
    'same idempotency key on retry',
    $creates[0]['headers']['ESAALAT-IDEMPOTENCY-KEY'] === $creates[1]['headers']['ESAALAT-IDEMPOTENCY-KEY']
);
check('bearer token attached', ($creates[0]['headers']['Authorization'] ?? '') === 'Bearer token-abc');
check(
    'amount and order id as strings',
    json_decode((string) $creates[0]['body'], true) === ['amount' => '5000', 'external_order_id' => 'order-123']
);

$client->createSession(['orderId' => 'order-124', 'amount' => 6000]);
$tokenCalls = array_values(array_filter($calls, static fn (array $call): bool => str_ends_with($call['url'], '/merchant/token')));
check('token cached across calls', count($tokenCalls) === 1);

$body = json_encode(['event' => 'payment.verified', 'intent_id' => 'i1']);
$timestamp = time();
$signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_test');
$event = $client->verifyWebhook((string) $body, $signature);
check('webhook verified', ($event['event'] ?? null) === 'payment.verified');

$tampered = str_replace('i1', 'i2', (string) $body);
try {
    $client->verifyWebhook($tampered, $signature);
    check('tampered body rejected', false);
} catch (EsaalatException $error) {
    check('tampered body rejected', $error->errorCode() === 'webhook');
}

try {
    Client::formatAmount(5000.5);
    check('fractional amount rejected', false);
} catch (EsaalatException $error) {
    check('fractional amount rejected', $error->errorCode() === 'configuration');
}

if ($failures > 0) {
    fwrite(STDERR, sprintf('%d check(s) failed.' . PHP_EOL, $failures));
    exit(1);
}

echo 'All PHP smoke checks passed.' . PHP_EOL;
