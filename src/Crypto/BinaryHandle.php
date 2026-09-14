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
 * {@see save()} GET that URL and return the FILE BYTES; {@see pages()} and
 * {@see metadata()} expose the rest of the envelope. The caller never has to know
 * which of the three response shapes arrived.
 *
 * THERE ARE THREE SHAPES, AND WHICH ONE ARRIVES IS NOT THE COMPANY'S CHOICE. The person's own
 * privacy setting and the TYPE of the field they answered with decide it, either can change at
 * any time, and nothing in the API announces it in advance:
 *
 * - **private source** → {@code application/json} {@code {"encrypted":true,"value":<wrapper>}}.
 *   The wrapper decrypts to a JSON envelope STRING (photo:
 *   {@code {"full":"data:...","thumb":...}}; single-file document:
 *   {@code {"file":"data:...",...}}; multi-page document:
 *   {@code {"pages":[{"file":"data:...",...}],...}}) — NOT raw bytes.
 * - **non-private source whose type stores pages or declares entries** →
 *   {@code application/json} {@code {"encrypted":false,"value":"<envelope>"}}. The same envelope
 *   string, in the clear. There is nothing to decrypt.
 * - **every other non-private source** → the file's own {@code Content-Type} and the body IS the
 *   file. A handle built this way needs no service key at all.
 *
 * Photos resolve to the {@code full} representation. There is no variant selection.
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
 *
 * {@see bytes()}, {@see pages()} and {@see metadata()} share ONE lazy fetch: whichever is called
 * first performs it, and every later call answers from the parsed envelope.
 */
final class BinaryHandle
{
    /** Envelope keys that hold the primary binary data URI, in priority order. */
    private const DATA_URI_KEYS = ['full', 'file'];

    /**
     * Envelope members that describe the envelope itself rather than the type's own declared
     * entries — everything NOT in this list is metadata.
     */
    private const ENVELOPE_MEMBERS = ['pages', 'file', 'full', 'thumb', 'original_name', 'mime_type', 'size'];

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
     * A MULTI-PAGE envelope has no single primary file and throws: use {@see pages()}.
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
     * The platform's {@code X-Allus-Content-Sha256} — the digest of the SERVED ARTIFACT.
     *
     * Which artifact that is follows the response arm: the raw bytes when the answer arrived as
     * bytes, and the served {@code value} string on either JSON arm — the ciphertext wrapper for a
     * private source, the plaintext envelope for a non-private one. It is NOT "the sha256 of what
     * {@see bytes()} returns": on an envelope carrying pages {@see bytes()} throws, and on an
     * envelope carrying one file it returns the decoded payload rather than the envelope string.
     *
     * A consumer can record it and later show that its archived copy has not drifted. {@code null}
     * until something has been fetched, and on a handle built from an envelope that was never
     * fetched through this class.
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
     * Photo envelope -> the {@code full} data-URI payload; single-file document envelope ->
     * the {@code file} data-URI payload. A MULTI-PAGE envelope has no single primary file, so it
     * throws rather than handing back the first page as though it were the whole document.
     *
     * @throws DecryptError on a malformed envelope.
     */
    public static function parseEnvelopeBytes(string $envelopeJson): string
    {
        $envelope = self::parseEnvelope($envelopeJson);

        $dataUri = null;
        foreach (self::DATA_URI_KEYS as $key) {
            if (isset($envelope[$key]) && is_string($envelope[$key])) {
                $dataUri = $envelope[$key];
                break;
            }
        }
        if ($dataUri === null) {
            if (isset($envelope['pages']) && is_array($envelope['pages']) && $envelope['pages'] !== []) {
                throw new DecryptError('multi-page envelope: use pages');
            }
            throw new DecryptError("binary envelope has no 'full'/'file' data-URI payload");
        }

        return self::decodeDataUri($dataUri);
    }

    /**
     * The envelope's pages, in envelope order — an empty list for a single-file envelope.
     *
     * Lazy exactly as {@see bytes()} is: the first call of {@see bytes()}, {@see pages()} or
     * {@see metadata()} performs the one fetch and optional decrypt, and every later call answers
     * from the parsed envelope. A handle built from an envelope string needs no fetch. A
     * plaintext-BYTES answer carries no envelope, so it has no pages.
     *
     * @return list<BinaryPage>
     * @throws DecryptError on a failed fetch or decrypt, or a malformed envelope
     */
    public function pages(): array
    {
        $envelope = $this->envelopeOrNull();
        if ($envelope === null || !isset($envelope['pages']) || !is_array($envelope['pages'])) {
            return [];
        }

        $out = [];
        foreach ($envelope['pages'] as $page) {
            if (!is_array($page) || !isset($page['file']) || !is_string($page['file'])) {
                throw new DecryptError('binary envelope page has no data-URI payload');
            }
            $out[] = new BinaryPage(
                isset($page['label']) && is_string($page['label']) ? $page['label'] : null,
                isset($page['original_name']) && is_string($page['original_name']) ? $page['original_name'] : null,
                isset($page['mime_type']) && is_string($page['mime_type']) ? $page['mime_type'] : null,
                self::decodeDataUri($page['file']),
            );
        }

        return $out;
    }

    /**
     * Every declared entry the envelope carries, as a plain map.
     *
     * Keys are every string-keyed envelope member other than the envelope's own ({@code pages},
     * {@code file}, {@code full}, {@code thumb}, {@code original_name}, {@code mime_type},
     * {@code size}); values are the stored string, or {@code null} for an entry the person left
     * unset. {@code name} — the holder name an ID provider extracted — is a member like any other
     * and appears here.
     *
     * **The map carries no ordering guarantee.** A consumer that needs the type's declared order
     * reads the envelope string itself.
     *
     * Empty for a photo, for a plain document that declares no entries, and for a plaintext-BYTES
     * answer. Lazy exactly as {@see pages()} is.
     *
     * @return array<string,?string>
     * @throws DecryptError on a failed fetch or decrypt, or a malformed envelope
     */
    public function metadata(): array
    {
        $envelope = $this->envelopeOrNull();
        if ($envelope === null) {
            return [];
        }

        $out = [];
        foreach ($envelope as $key => $value) {
            if (!is_string($key) || in_array($key, self::ENVELOPE_MEMBERS, true)) {
                continue;
            }
            $out[$key] = is_string($value) ? $value : null;
        }

        return $out;
    }

    /**
     * The ONE envelope parser both JSON arms go through.
     *
     * @return array<string,mixed>
     * @throws DecryptError on anything that is not a JSON object
     */
    private static function parseEnvelope(string $envelopeJson): array
    {
        try {
            $envelope = json_decode($envelopeJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DecryptError('binary envelope is not valid JSON', 0, $e);
        }
        if (!is_array($envelope)) {
            throw new DecryptError('binary envelope must be a JSON object');
        }

        return $envelope;
    }

    /**
     * {@code data:<mime>;base64,<payload>} -> the decoded payload.
     *
     * @throws DecryptError
     */
    private static function decodeDataUri(string $dataUri): string
    {
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
     * The parsed envelope, fetching+decrypting on first use. {@code null} when the answer is
     * plaintext BYTES, which carries no envelope at all.
     *
     * @return array<string,mixed>|null
     * @throws DecryptError
     */
    private function envelopeOrNull(): ?array
    {
        if ($this->envelopeJson === null) {
            $this->fetchOnce();
            if ($this->envelopeJson === null) {
                return null;
            }
        }

        return self::parseEnvelope($this->envelopeJson);
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
            // built without one fail on exactly the answers that do not need it. The envelope arm is
            // plaintext too — the same envelope string the wrapper arm decrypts to — so both JSON
            // arms converge here.
            if ($result->envelope !== null) {
                $this->envelopeJson = $result->envelope;
                return;
            }
            $this->plainBytes = $result->bytes ?? '';
            return;
        }
        if ($this->decrypt === null) {
            throw new DecryptError('binary answer is encrypted but this handle has no decrypt wiring');
        }
        $this->envelopeJson = ($this->decrypt)($result->wrapper ?? '');
    }
}
