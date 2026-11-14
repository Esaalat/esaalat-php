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

$client->createIntent(['wallet' => 'wallet-1', 'amount' => 5000, 'notesForm' => 'form-1']);
$intentCreate = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/merchant/intents/')));
check(
    'notes form sent on create',
    (json_decode((string) $intentCreate[0]['body'], true)['notes_form'] ?? null) === 'form-1'
);

$client->createIntent(['wallet' => 'wallet-1', 'amount' => 5000, 'payee' => 'person-a']);
$intentCreate = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/merchant/intents/')));
check(
    'payee sent on intent',
    (json_decode((string) $intentCreate[1]['body'], true)['payee'] ?? null) === 'person-a'
);

$client->listNotesForms(['search' => 'delivery details', 'pageSize' => 50]);
$formCalls = array_values(array_filter($calls, static fn (array $call): bool => str_contains($call['url'], '/merchant/notes-forms/?')));
check(
    'forms listed with query',
    str_ends_with($formCalls[0]['url'], '/merchant/notes-forms/?search=delivery+details&page_size=50')
);

$payee = [
    'id' => 'payee-1',
    'external_id' => 'person-a',
    'name' => 'Person A',
    'code' => 'person-a',
    'currency' => 'YER',
    'wallets' => [['provider' => 'KURAIMI', 'account_number' => '771234567']],
    'is_active' => true,
];
$payeeResponses = [
    [200, [], json_encode(['access_token' => 'token-abc'])],
    [201, [], json_encode($payee)],
    [200, [], json_encode(['count' => 1, 'next' => null, 'previous' => null, 'results' => [$payee]])],
    [200, [], json_encode($payee)],
    [200, [], json_encode(['deactivated' => true, 'id' => 'payee-1'])],
    [204, [], ''],
];
$payeeCalls = [];
$payeeTransport = static function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$payeeResponses, &$payeeCalls): array {
    $payeeCalls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    $response = count($payeeResponses) > 1 ? array_shift($payeeResponses) : $payeeResponses[0];
    return $response;
};
$payeeClient = new Client('cid', 'secret', 'https://api.esaalat.com/api/v1', 'whsec_test', 20, 2, $payeeTransport);

$createdPayee = $payeeClient->createPayee([
    'externalId' => 'person-a',
    'name' => 'Person A',
    'wallets' => [['provider' => 'kuraimi', 'accountNumber' => '771234567']],
]);
check('payee created', ($createdPayee['external_id'] ?? null) === 'person-a');
check(
    'payee body uses external id and snake-case wallet',
    json_decode((string) $payeeCalls[1]['body'], true) === [
        'external_id' => 'person-a',
        'name' => 'Person A',
        'wallets' => [['provider' => 'kuraimi', 'account_number' => '771234567']],
    ]
);

$payeeClient->listPayees(['search' => 'person a', 'pageSize' => 25]);
check(
    'payees listed with query',
    str_ends_with($payeeCalls[2]['url'], '/merchant/payees/?search=person+a&page_size=25')
);

$payeeClient->updatePayee('person-a', ['wallets' => [['provider' => 'JAIB', 'accountNumber' => '770000000']]]);
check(
    'payee updated without changing id',
    $payeeCalls[3]['method'] === 'PATCH'
    && !isset(json_decode((string) $payeeCalls[3]['body'], true)['external_id'])
);

$removed = $payeeClient->deletePayee('person-a');
check('payee with history deactivated', $removed === ['deleted' => false, 'deactivated' => true, 'id' => 'payee-1']);
$hardRemoved = $payeeClient->deletePayee('person-a');
check('unused payee deleted', $hardRemoved === ['deleted' => true, 'deactivated' => false]);

try {
    $payeeClient->updatePayee('person-a', ['externalId' => 'person-b']);
    check('external id change refused', false);
} catch (EsaalatException $error) {
    check('external id change refused', $error->errorCode() === 'configuration');
}

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
