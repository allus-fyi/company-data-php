<?php

declare(strict_types=1);

namespace Allus\CompanyData\Crypto;

/**
 * One response from a company-facing binary file endpoint, in the shape a {@see BinaryHandle} needs.
 *
 * The route has THREE 200 shapes and the company cannot predict which it will get, because the
 * answer depends on the person's own privacy setting and on the TYPE of the field they answered
 * with, neither of which the company chooses:
 *
 * - **encrypted** — {@code application/json}, {@code {"encrypted":true,"value":<wrapper>}}. The
 *   wrapper decrypts to the binary ENVELOPE string.
 * - **envelope** — {@code application/json}, {@code {"encrypted":false,"value":"<envelope>"}}. The
 *   plaintext envelope string itself, for a non-private source whose type stores more than one file
 *   or declares metadata entries. Nothing to decrypt.
 * - **plaintext bytes** — the file's own {@code Content-Type} (e.g. {@code image/jpeg},
 *   {@code application/pdf}) and the body IS the file bytes.
 *
 * The bytes shape is told apart from the two JSON ones on the response's {@code Content-Type}, never
 * guessed from the body: a plaintext answer's first byte is whatever the file starts with, and a PDF
 * or a JPEG that happened to begin with a brace would be indistinguishable from a wrapper by
 * sniffing. Inside a JSON body it is {@code encrypted} that decides; a JSON body that does not carry
 * {@code encrypted: false} with a string {@code value} is the wrapper arm, which is what the
 * bare-wrapper routes (a company's own contract copy, its run slot file) answer with.
 *
 * {@see $contentSha256} is the platform's {@code X-Allus-Content-Sha256} — the sha256 of the SERVED
 * ARTIFACT: the raw bytes on the bytes shape, the served {@code value} string on either JSON shape —
 * so a consumer can record what it received and later prove its archived copy has not drifted.
 */
final class BinaryFetchResult
{
    /**
     * @param array<string,mixed>|string|null $wrapper  the {@code {"_enc":1,…}} wrapper (encrypted shape)
     * @param string|null $bytes    the file bytes themselves (plaintext-bytes shape)
     * @param string|null $envelope the plaintext envelope string (envelope shape)
     */
    public function __construct(
        public readonly bool $encrypted,
        public readonly array|string|null $wrapper = null,
        public readonly ?string $bytes = null,
        public readonly ?string $contentType = null,
        public readonly ?string $contentSha256 = null,
        public readonly ?string $envelope = null,
    ) {
    }
}
