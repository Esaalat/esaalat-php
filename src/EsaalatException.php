<?php

declare(strict_types=1);

namespace Esaalat;

/**
 * Every failure the client raises.
 *
 * The code says whose fault it is in the caller's terms: `configuration`
 * (a key, a URL, a missing webhook secret), `network` / `timeout` (retry
 * later), `api` (Esaalat rejected the request — status and attr say what) or
 * `webhook` (a signature that does not verify).
 */
final class EsaalatException extends \RuntimeException
{
    /**
     * @param array<int, array{code: string, detail: string, attr: ?string}> $errors
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = 'api',
        private readonly ?int $status = null,
        private readonly ?string $detail = null,
        private readonly ?string $attr = null,
        private readonly array $errors = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    /** The request field the API blamed, e.g. `wallet` or `amount`. */
    public function attr(): ?string
    {
        return $this->attr;
    }

    /** @return array<int, array{code: string, detail: string, attr: ?string}> */
    public function errors(): array
    {
        return $this->errors;
    }
}
