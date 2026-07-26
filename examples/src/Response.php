<?php

declare(strict_types=1);

namespace Allus\Examples;

/**
 * A tiny value object a family handler returns instead of emitting HTTP itself. The shared {@see Server}
 * owns the actual output (headers/status/body), so the family handlers stay pure "SDK call → result"
 * code with no HTTP plumbing — the whole point of the shared scaffolding.
 */
final class Response
{
    /** @param array<string,mixed>|null $json */
    private function __construct(
        public readonly int $status,
        public readonly string $kind,          // 'json' | 'text' | 'redirect'
        public readonly ?array $json = null,
        public readonly string $text = '',
        public readonly string $location = '',
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, 'json', json: $data);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, 'text', text: $body);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, 'redirect', location: $location);
    }
}
