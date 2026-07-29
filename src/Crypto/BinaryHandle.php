<?php

declare(strict_types=1);

namespace Allus\CompanyData\Crypto;

use Allus\CompanyData\Errors\DecryptError;
use Allus\CompanyData\Util\AtomicFile;

/**
 * Lazy handle for a binary (photo/document) value.
 *
 * A binary answer is stored server-side as a file, exposed in the hardened API as
 * a slot-keyed {@code value_url} (never the source field). {@see bytes()} and
 * {@see save()} GET that URL and return the FILE BYTES either way — the caller
 * never has to know which of the two response shapes arrived.
 *
 * THERE ARE TWO SHAPES, AND WHICH ONE ARRIVES IS THE PERSON'S CHOICE, NOT THE
 * COMPANY'S. Whether the person's source field is private decides it, they can change it at
 * any time, and nothing in the API announces it in advance:
 *
 * - **private source** → {@code application/json} {@code {"encrypted":true,"value":<wrapper>}}.
 *   The wrapper decrypts to a JSON envelope STRING (photo:
 *   {@code {"full":"data:...","thumb":...}}; document: {@code {"file":"data:...",...}}) — NOT raw
 *   bytes — whose primary data-URI payload ({@code full} for photos, {@code file} for documents)
 *   base64-decodes to the file.
 * - **plaintext source** → the file's own {@code Content-Type} and the body IS the file. There is
 *   nothing to decrypt, and a handle built this way needs no service key at all.
 *
 * Photos resolve to the {@code full} representation. There is no variant selection: one slot has one
 * byte sequence and therefore one digest.
 *
 * The fetch + decrypt are supplied by the client as plain callables:
 *
 * - {@code valueUrl} + {@code fetch} — {@code fetch(valueUrl)} returns a
 *   {@see BinaryFetchResult} saying which shape arrived (the client classifies it on the response's
 *   {@code Content-Type}; the body is never sniffed).
 * - {@code decrypt} — {@code decrypt(wrapper)} returns the decrypted envelope
 *   string (a closure over the loaded service private key, so no key is ever
 *   passed to this handle — config-only key handling). Only ever called for the encrypted shape.
 *
 * When the decrypted envelope is already in hand, a handle can also be built
 * directly from {@code envelopeJson} (no fetch).
 */
final class BinaryHandle
{
    /** Envelope keys that hold the primary binary data URI, in priority order. */
    private const DATA_URI_KEYS = ['full', 'file'];

    private ?string $envelopeJson;

    /** Plaintext file bytes, once a plaintext-shaped response has been fetched. */
    private ?string $plainBytes = null;

    private ?string $contentType = null;

    private ?string $contentSha256 = null;

    /** @var (callable(string): BinaryFetchResult)|null */
    private $fetch;

    /** @var (callable(array<string,mixed>|string): string)|null */
    private $decrypt;

    /**
     * @param callable(string): BinaryFetchResult|null $fetch
     * @param callable(array<string,mixed>|string): string|null $decrypt
     */
    public function __construct(
        ?string $envelopeJson = null,
        private readonly ?string $valueUrl = null,
        ?callable $fetch = null,
        ?callable $decrypt = null,
    ) {
        $this->envelopeJson = $envelopeJson;
        $this->fetch = $fetch;
        $this->decrypt = $decrypt;
    }

    /** The slot-keyed file URL this handle fetches from (opaque to callers). */
    public function valueUrl(): ?string
    {
        return $this->valueUrl;
    }

    /**
     * Fetch (if needed), decrypt, and return the decoded primary file bytes.
     *
     * @throws DecryptError
     */
    public function bytes(): string
    {
        if ($this->plainBytes !== null) {
            return $this->plainBytes;
        }
        if ($this->envelopeJson === null) {
            $this->fetchOnce();
            if ($this->plainBytes !== null) {
                return $this->plainBytes;
            }
        }

        return self::parseEnvelopeBytes($this->resolveEnvelope());
    }

    /**
     * The platform's {@code X-Allus-Content-Sha256} for the bytes this handle fetched — the sha256 of
     * exactly what {@see bytes()} returns, so a consumer can record it and later show that its archived
     * copy has not drifted. {@code null} until something has been fetched, and on a handle built from an
     * envelope that was never fetched through this class.
     *
     * It is the platform's word, not a signature: it proves agreement with the platform's record, not
     * anything to a third party who doubts that record.
     */
    public function contentSha256(): ?string
    {
        return $this->contentSha256;
    }

    /** The response {@code Content-Type} the bytes arrived with, once fetched. */
    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * Write the decoded file bytes to {@code $path}; return the number of bytes
     * written.
     *
     * Crash-safe (matching the buffer's atomic-write discipline): the
     * bytes are written to a temp file in the same directory, fsync'd, and
     * atomically renamed into place — so a crash mid-write never leaves a
     * truncated output file (the destination is either the old file, or the
     * complete new one).
     *
     * @throws DecryptError
     */
    public function save(string $path): int
    {
        $data = $this->bytes();
        AtomicFile::write($path, $data);
        return strlen($data);
    }

    /**
     * Turn a decrypted binary envelope STRING into the primary file bytes.
     *
     * Photo envelope -> the {@code full} data-URI payload; document envelope ->
     * the {@code file} data-URI payload.
     *
     * @throws DecryptError on a malformed envelope.
     */
    public static function parseEnvelopeBytes(string $envelopeJson): string
    {
        try {
            $envelope = json_decode($envelopeJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DecryptError('binary envelope is not valid JSON', 0, $e);
        }
        if (!is_array($envelope)) {
            throw new DecryptError('binary envelope must be a JSON object');
        }

        $dataUri = null;
        foreach (self::DATA_URI_KEYS as $key) {
            if (isset($envelope[$key]) && is_string($envelope[$key])) {
                $dataUri = $envelope[$key];
                break;
            }
        }
        if ($dataUri === null) {
            throw new DecryptError("binary envelope has no 'full'/'file' data-URI payload");
        }

        // data:<mime>;base64,<payload>
        $marker = 'base64,';
        $idx = strpos($dataUri, $marker);
        if ($idx === false) {
            throw new DecryptError('binary data URI is not base64-encoded');
        }
        $payload = substr($dataUri, $idx + strlen($marker));
        $decoded = base64_decode($payload, strict: true);
        if ($decoded === false) {
            throw new DecryptError('binary data-URI payload is not valid base64');
        }
        return $decoded;
    }

    /**
     * Return the decrypted envelope string, fetching+decrypting on first use.
     *
     * @throws DecryptError
     */
    private function resolveEnvelope(): string
    {
        if ($this->envelopeJson !== null) {
            return $this->envelopeJson;
        }
        $this->fetchOnce();
        if ($this->envelopeJson === null) {
            throw new DecryptError('binary answer arrived as plaintext bytes; use bytes()/save()');
        }
        return $this->envelopeJson;
    }

    /**
     * Fetch once and record which shape arrived. Idempotent: the result is cached on the handle so
     * repeated {@see bytes()}/{@see save()} calls do not re-fetch, and so a plaintext answer's digest
     * survives for {@see contentSha256()}.
     *
     * @throws DecryptError
     */
    private function fetchOnce(): void
    {
        if ($this->plainBytes !== null || $this->envelopeJson !== null) {
            return;
        }
        if ($this->fetch === null || $this->valueUrl === null) {
            throw new DecryptError(
                'BinaryHandle has no envelope and no fetch wiring '
                . '(build it with envelopeJson, or valueUrl + fetch + decrypt)'
            );
        }
        $result = ($this->fetch)($this->valueUrl);
        $this->contentType = $result->contentType;
        $this->contentSha256 = $result->contentSha256;

        if (!$result->encrypted) {
            // A plaintext answer needs no service key. Requiring `decrypt` here would make a handle
            // built without one fail on exactly the answers that do not need it.
            $this->plainBytes = $result->bytes ?? '';
            return;
        }
        if ($this->decrypt === null) {
            throw new DecryptError('binary answer is encrypted but this handle has no decrypt wiring');
        }
        $this->envelopeJson = ($this->decrypt)($result->wrapper ?? '');
    }
}
