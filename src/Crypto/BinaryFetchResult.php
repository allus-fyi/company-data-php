<?php

declare(strict_types=1);

namespace Allus\CompanyData\Crypto;

/**
 * One response from a company-facing binary file endpoint, in the shape a {@see BinaryHandle} needs.
 *
 * #590 — the route has TWO 200 shapes and the company cannot predict which it will get, because the
 * answer depends on whether the person's source field is private, which is theirs to change:
 *
 * - **encrypted** — {@code application/json}, {@code {"encrypted":true,"value":<wrapper>}}. The
 *   wrapper decrypts to the binary ENVELOPE string, from which the file bytes are extracted.
 * - **plaintext** — the file's own {@code Content-Type} (e.g. {@code image/jpeg},
 *   {@code application/pdf}) and the body IS the file bytes. Nothing to decrypt.
 *
 * The distinction is made on the response's {@code Content-Type}, never guessed from the body: a
 * plaintext answer's first byte is whatever the file starts with, and a PDF or a JPEG that happened to
 * begin with a brace would be indistinguishable from a wrapper by sniffing.
 *
 * {@see $contentSha256} is the platform's {@code X-Allus-Content-Sha256} — the sha256 of exactly these
 * bytes, present on both shapes — so a consumer can record what it received and later prove its
 * archived copy has not drifted.
 */
final class BinaryFetchResult
{
    /**
     * @param array<string,mixed>|string|null $wrapper the {@code {"_enc":1,…}} wrapper (encrypted shape)
     * @param string|null $bytes the file bytes themselves (plaintext shape)
     */
    public function __construct(
        public readonly bool $encrypted,
        public readonly array|string|null $wrapper = null,
        public readonly ?string $bytes = null,
        public readonly ?string $contentType = null,
        public readonly ?string $contentSha256 = null,
    ) {
    }
}
