<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures;

use Nette\Http\IResponse;

/**
 * Nette\Http\Response is final and PHP's header() does nothing visible on CLI.
 */
final class RecordingResponse implements IResponse
{
    public int $code = self::S200_OK;

    // Used by the container of nette/http and by Nette\Http\Session
    public bool $cookieSecure = false;
    public string $cookiePath = '/';
    public string $cookieDomain = '';

    /** @var array<string, string> */
    public array $headers = [];

    public function reset(): void
    {
        $this->code = self::S200_OK;
        $this->headers = [];
    }

    public function setCode(int $code, ?string $reason = null): static
    {
        $this->code = $code;
        return $this;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = $value;
        return $this;
    }

    public function addHeader(string $name, string $value): static
    {
        return $this->setHeader($name, $value);
    }

    public function setContentType(string $type, ?string $charset = null): static
    {
        return $this->setHeader('content-type', $type . ($charset ? "; charset=$charset" : ''));
    }

    public function redirect(string $url, int $code = self::S302_Found): void
    {
    }

    public function setExpiration(?string $expire): static
    {
        return $this;
    }

    public function isSent(): bool
    {
        return false;
    }

    public function getHeader(string $header): ?string
    {
        return $this->headers[strtolower($header)] ?? null;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function setCookie(
        string $name,
        string $value,
        string|int|\DateTimeInterface|null $expire,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
    ): static {
        return $this;
    }

    public function deleteCookie(
        string $name,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
    ): void {
    }
}
