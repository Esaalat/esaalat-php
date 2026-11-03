<?php

declare(strict_types=1);

namespace Esaalat;

/**
 * Server-side Esaalat client.
 *
 * ```php
 * $esaalat = Client::fromEnv();
 * $session = $esaalat->createSession(['orderId' => 'order-123', 'amount' => 5000]);
 * // browser: checkout.open({ sessionToken: $session['session_token'] })
 * $event = $esaalat->verifyWebhook($rawBody, $signature);   // 400 on failure
 * ```
 *
 * It owns the three parts a merchant backend always writes by hand: the
 * 1-hour token (cached, refreshed from its own `exp`), the idempotency key
 * (one per create, reused on every retry) and the webhook HMAC (raw body,
 * constant-time compare, replay window).
 */
final class Client
{
    private const DEFAULT_BASE_URL = 'https://api.esaalat.com/api/v1';
    private const TOKEN_SKEW_SECONDS = 60;
    private const DEFAULT_TOKEN_TTL = 3300;
    private const RETRY_BASE_SECONDS = 0.4;
    private const DEFAULT_WEBHOOK_TOLERANCE = 300;

    private ?string $token = null;
    private float $tokenExpiresAt = 0.0;

    /** @var null|callable(string,string,array<string,string>,?string,int):array{0:int,1:array<string,string>,2:string} */
    private $transport;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly ?string $webhookSecret = null,
        private readonly int $timeout = 20,
        private readonly int $maxRetries = 2,
        ?callable $transport = null
    ) {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new EsaalatException(
                'Missing API credentials: pass clientId and clientSecret, or set '
                . 'ESAALAT_CLIENT_ID and ESAALAT_CLIENT_SECRET.',
                'configuration'
            );
        }
        $this->baseUrl = rtrim($this->baseUrl, '/');
        if (!preg_match('#^https?://#i', $this->baseUrl)) {
            throw new EsaalatException(
                sprintf('baseUrl must be an http(s) URL, got "%s".', $this->baseUrl),
                'configuration'
            );
        }
        $this->transport = $transport;
    }

    public static function fromEnv(): self
    {
        return new self(
            self::env('ESAALAT_CLIENT_ID') ?? '',
            self::env('ESAALAT_CLIENT_SECRET') ?? '',
            self::env('ESAALAT_BASE_URL') ?? self::DEFAULT_BASE_URL,
            self::env('ESAALAT_WEBHOOK_SECRET')
        );
    }

    /**
     * getenv() only sees the process environment, but .env loaders populate
     * $_ENV and $_SERVER instead — Laravel's Env and Symfony's Dotenv among
     * them. Reading all three means fromEnv() works without the merchant
     * exporting variables by hand.
     */
    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * Whole riyals, as the API requires: 5000, 5000.0 and "5000" all become
     * "5000"; a fraction is refused before the request leaves your process.
     */
    public static function formatAmount(int|float|string $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value) {
                throw new EsaalatException(
                    sprintf('Amount must be a whole number of riyals, got %s.', (string) $value),
                    'configuration'
                );
            }
            return (string) (int) $value;
        }
        $text = trim($value);
        if (!preg_match('/^\d+$/', $text)) {
            throw new EsaalatException(
                sprintf('Amount must be a whole number of riyals, got "%s".', $text),
                'configuration'
            );
        }
        return $text;
    }

    /**
     * Create a hosted checkout session. The browser only needs
     * `session_token` from the response.
     *
     * @param array<string, mixed> $input amount, items, currency, orderId,
     *        wallet, notes, showTestingWallet, idempotencyKey
     * @return array<string, mixed>
     */
    public function createSession(array $input): array
    {
        $body = $this->prune([
            'amount' => isset($input['amount']) ? self::formatAmount($input['amount']) : null,
            'items' => isset($input['items'])
                ? array_map(fn (array $item): array => $this->normalizeItem($item), $input['items'])
                : null,
            'currency' => $input['currency'] ?? null,
            'external_order_id' => $input['orderId'] ?? $input['external_order_id'] ?? null,
            'wallet' => $input['wallet'] ?? null,
            'notes' => $input['notes'] ?? null,
            'show_testing_wallet' => $input['showTestingWallet'] ?? $input['show_testing_wallet'] ?? null,
        ]);

        return $this->request(
            'POST',
            '/checkout/merchant/sessions/',
            $body,
            $input['idempotencyKey'] ?? self::uuid4()
        );
    }

    /**
     * Create a payment intent (server-driven orders: marketplaces, Shopify).
     *
     * @param array<string, mixed> $input wallet, amount, items, currency,
     *        orderId, notes, idempotencyKey
     * @return array<string, mixed>
     */
    public function createIntent(array $input): array
    {
        $body = $this->prune([
            'wallet' => $input['wallet'] ?? null,
            'amount' => isset($input['amount']) ? self::formatAmount($input['amount']) : null,
            'items' => isset($input['items'])
                ? array_map(fn (array $item): array => $this->normalizeItem($item), $input['items'])
                : null,
            'currency' => $input['currency'] ?? null,
            'external_order_id' => $input['orderId'] ?? $input['external_order_id'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);

        return $this->request(
            'POST',
            '/merchant/intents/',
            $body,
            $input['idempotencyKey'] ?? self::uuid4()
        );
    }

    /** @return array<string, mixed> */
    public function retrieveIntent(string $intentId): array
    {
        return $this->request('GET', '/merchant/intents/' . rawurlencode($intentId) . '/');
    }

    /** @return array<string, mixed> */
    public function attachReference(string $intentId, string $reference): array
    {
        return $this->request(
            'PATCH',
            '/merchant/intents/' . rawurlencode($intentId) . '/',
            ['reference' => $reference]
        );
    }

    /**
     * Verify `X-Esaalat-Signature` over the raw body and return the event.
     *
     * Pass the bytes you received, not a re-serialized array: the HMAC is
     * computed over exactly what Esaalat sent. Throws `EsaalatException`
     * (`errorCode() === 'webhook'`) when the signature, its age, or the body
     * does not hold up — catch it and answer 400.
     *
     * @return array<string, mixed>
     */
    public function verifyWebhook(
        string $rawBody,
        string $signature,
        ?string $secret = null,
        int $tolerance = self::DEFAULT_WEBHOOK_TOLERANCE
    ): array {
        $secret = $secret ?? $this->webhookSecret;
        if ($secret === null || $secret === '') {
            throw new EsaalatException(
                'No webhook secret: pass one to the constructor, set '
                . 'ESAALAT_WEBHOOK_SECRET, or pass $secret per call.',
                'configuration'
            );
        }

        [$timestamp, $provided] = $this->parseSignature($signature);

        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            throw new EsaalatException(
                sprintf(
                    'Webhook timestamp is outside the %ds window (possible replay).',
                    $tolerance
                ),
                'webhook',
                400
            );
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $provided)) {
            throw new EsaalatException(
                'Webhook signature does not match. Verify against the raw '
                . "request body and this endpoint's secret.",
                'webhook',
                400
            );
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new EsaalatException('Webhook body is not JSON.', 'webhook', 400);
        }

        return $payload;
    }

    // -- internals ---------------------------------------------------------

    private function accessToken(): string
    {
        if ($this->token !== null && $this->tokenExpiresAt - self::TOKEN_SKEW_SECONDS > time()) {
            return $this->token;
        }

        $response = $this->request(
            'POST',
            '/merchant/token',
            null,
            null,
            false,
            [
                'ESAALAT-CLIENT-ID' => $this->clientId,
                'ESAALAT-CLIENT-SECRET' => $this->clientSecret,
            ]
        );

        $token = $response['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new EsaalatException('Esaalat did not return an access token.', 'api');
        }

        $this->token = $token;
        $this->tokenExpiresAt = $this->jwtExpiry($token) ?? (time() + self::DEFAULT_TOKEN_TTL);

        return $token;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $idempotencyKey = null,
        bool $authenticated = true,
        array $extraHeaders = []
    ): array {
        $url = $this->baseUrl . $path;
        $payload = $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $authorization = $authenticated ? $this->accessToken() : null;
        $refreshed = false;
        $retries = 0;

        while (true) {
            $headers = array_merge(['Accept' => 'application/json'], $extraHeaders);
            if ($payload !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            if ($authorization !== null) {
                $headers['Authorization'] = 'Bearer ' . $authorization;
            }
            if ($idempotencyKey !== null) {
                $headers['ESAALAT-IDEMPOTENCY-KEY'] = $idempotencyKey;
            }

            try {
                [$status, $responseHeaders, $raw] = $this->send($method, $url, $headers, $payload);
            } catch (EsaalatException $error) {
                if (
                    in_array($error->errorCode(), ['network', 'timeout'], true)
                    && $retries < $this->maxRetries
                ) {
                    usleep((int) round(self::RETRY_BASE_SECONDS * (2 ** $retries) * 1_000_000));
                    $retries++;
                    continue;
                }
                throw $error;
            }

            if ($status >= 200 && $status < 300) {
                if ($raw === '') {
                    return [];
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new EsaalatException(
                        'Esaalat returned a response that is not JSON.',
                        'api',
                        $status
                    );
                }
                return $decoded;
            }

            if ($status === 401 && !$refreshed && $authorization !== null) {
                $refreshed = true;
                $this->token = null;
                $authorization = $this->accessToken();
                continue;
            }

            if (($status === 429 || $status >= 500) && $retries < $this->maxRetries) {
                $delay = $this->retryAfter($responseHeaders)
                    ?? self::RETRY_BASE_SECONDS * (2 ** $retries);
                usleep((int) round($delay * 1_000_000));
                $retries++;
                continue;
            }

            throw $this->apiError($status, $raw);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body, $this->timeout);
        }

        if (function_exists('curl_init')) {
            return $this->sendWithCurl($method, $url, $headers, $body);
        }

        return $this->sendWithStreams($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function sendWithCurl(string $method, string $url, array $headers, ?string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $handle = curl_init($url);
        if ($handle === false) {
            throw new EsaalatException('Could not initialise curl.', 'configuration');
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new EsaalatException(
                sprintf('Could not reach Esaalat at %s (%s).', $url, $message),
                'network'
            );
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, $responseHeaders, (string) $raw];
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function sendWithStreams(string $method, string $url, array $headers, ?string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new EsaalatException(
                sprintf('Could not reach Esaalat at %s.', $url),
                'network'
            );
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [$status, $responseHeaders, $raw];
    }

    /** @param array<string, string> $headers */
    private function retryAfter(array $headers): ?float
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'retry-after' && is_numeric($value)) {
                return max(0.0, (float) $value);
            }
        }
        return null;
    }

    private function apiError(int $status, string $raw): EsaalatException
    {
        $errors = [];
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $items = $decoded['errors'] ?? (isset($decoded['detail']) ? [$decoded['detail']] : []);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_string($item)) {
                        $errors[] = ['code' => 'error', 'detail' => $item, 'attr' => null];
                    } elseif (is_array($item)) {
                        $errors[] = [
                            'code' => (string) ($item['code'] ?? 'error'),
                            'detail' => (string) ($item['detail'] ?? ''),
                            'attr' => isset($item['attr']) && is_string($item['attr'])
                                ? $item['attr']
                                : null,
                        ];
                    }
                }
            }
        }

        $detail = $errors[0]['detail'] ?? '';
        if ($detail === '') {
            $detail = sprintf('Esaalat rejected the request (HTTP %d).', $status);
        }

        return new EsaalatException(
            $detail,
            'api',
            $status,
            $detail,
            $errors[0]['attr'] ?? null,
            $errors
        );
    }

    /** @return array{0: int, 1: string} */
    private function parseSignature(string $signature): array
    {
        $timestamp = null;
        $provided = '';
        foreach (explode(',', $signature) as $part) {
            $segments = explode('=', trim($part), 2);
            if (count($segments) !== 2) {
                continue;
            }
            if ($segments[0] === 't' && preg_match('/^\d+$/', $segments[1]) === 1) {
                $timestamp = (int) $segments[1];
            } elseif ($segments[0] === 'v1') {
                $provided = strtolower($segments[1]);
            }
        }

        if ($timestamp === null || $provided === '') {
            throw new EsaalatException(
                'Malformed X-Esaalat-Signature header.',
                'webhook',
                400
            );
        }

        return [$timestamp, $provided];
    }

    private function jwtExpiry(string $token): ?float
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['exp']) || !is_numeric($data['exp'])) {
            return null;
        }

        return (float) $data['exp'];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        return $this->prune([
            'name' => $item['name'] ?? null,
            'description' => $item['description'] ?? null,
            'price' => isset($item['price']) ? self::formatAmount($item['price']) : null,
            'quantity' => $item['quantity'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prune(array $payload): array
    {
        return array_filter($payload, static fn ($value): bool => $value !== null);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
