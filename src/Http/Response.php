<?php

declare(strict_types=1);

namespace Allus\CompanyData\Http;

/**
 * A minimal HTTP response value object the {@see Transport} returns.
 *
 * Keeping our own tiny type (rather than PSR-7) keeps the SDK dependency-free at
 * the transport boundary and makes the {@see HttpClient} trivially testable with
 * a fake transport.
 */
final class Response
{
    /**
     * @param array<string,string> $headers case-insensitively looked up via {@see header()}.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** Case-insensitive header lookup. */
    public function header(string $name): ?string
    {
        $target = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $target) {
                return $value;
            }
        }
        return null;
    }
}
