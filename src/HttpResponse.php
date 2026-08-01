<?php

declare(strict_types=1);

namespace formflow;

use ArrayAccess;
use LogicException;

/** @implements ArrayAccess<string, mixed> */
final class HttpResponse implements ArrayAccess
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly ?string $redirect = null,
        public readonly array $headers = []
    ) {
    }

    public static function html(int $status, string $body): self
    {
        return new self($status, $body);
    }

    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body, ?string $redirect = null): self
    {
        return new self($status, $body, $redirect);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', $location);
    }

    /** @param array{status: int, body: mixed, redirect?: string|null, headers?: array<string, string>} $response */
    public static function fromArray(array $response): self
    {
        return new self(
            (int) $response['status'],
            $response['body'],
            $response['redirect'] ?? null,
            is_array($response['headers'] ?? null) ? $response['headers'] : []
        );
    }

    /** @param array<string, string> $headers */
    public static function download(int $status, string $body, array $headers): self
    {
        return new self($status, $body, null, $headers);
    }

    /** @return array{status: int, body: mixed, redirect: string|null, headers: array<string, string>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'body' => $this->body,
            'redirect' => $this->redirect,
            'headers' => $this->headers,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['status', 'body', 'redirect', 'headers'], true);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('HttpResponse is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('HttpResponse is immutable.');
    }
}
