<?php

declare(strict_types=1);

namespace Allus\CompanyData\Crypto;

use Allus\CompanyData\Errors\DecryptError;
use Allus\CompanyData\Util\AtomicFile;

/**
 * Lazy handle for a binary (photo/document) value.
 *
 * A binary answer is stored server-side as a file, exposed in the hardened API as
 * a slot-keyed {@code value_url} (never the source field). On {@see bytes()} /
 * {@see save()} the handle GETs that URL, receives the {@code {"_enc":1,...}}
 * wrapper, runs the same decrypt as text → a JSON envelope STRING (photo:
 * {@code {"full":"data:...","thumb":...}}; document:
 * {@code {"file":"data:...",...}}) — NOT raw bytes — then parses the envelope and
 * base64-decodes the primary data-URI payload ({@code full} for photos,
 * {@code file} for documents) into the file bytes.
 *
 * The fetch + decrypt are supplied by the client as plain callables:
 *
 * - {@code valueUrl} + {@code fetch} — {@code fetch(valueUrl)} returns the
 *   encrypted wrapper (array or its JSON string), the way the slot file endpoint
 *   serves {@code {"encrypted":true,"value":<wrapper>}} (the client passes a
 *   callback that does the GET + unwraps to the inner wrapper).
 * - {@code decrypt} — {@code decrypt(wrapper)} returns the decrypted envelope
 *   string (a closure over the loaded service private key, so no key is ever
 *   passed to this handle — config-only key handling).
 *
 * When the decrypted envelope is already in hand, a handle can also be built
 * directly from {@code envelopeJson} (no fetch).
 */
final class BinaryHandle
{
    /** Envelope keys that hold the primary binary data URI, in priority order. */
    private const DATA_URI_KEYS = ['full', 'file'];

    private ?string $envelopeJson;

    /** @var (callable(string): (array<string,mixed>|string))|null */
    private $fetch;

    /** @var (callable(array<string,mixed>|string): string)|null */
    private $decrypt;

    /**
     * @param callable(string): (array<string,mixed>|string)|null $fetch
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
        return self::parseEnvelopeBytes($this->resolveEnvelope());
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
        if ($this->fetch === null || $this->decrypt === null || $this->valueUrl === null) {
            throw new DecryptError(
                'BinaryHandle has no envelope and no fetch/decrypt wiring '
                . '(build it with envelopeJson, or valueUrl + fetch + decrypt)'
            );
        }
        $wrapper = ($this->fetch)($this->valueUrl);
        $envelopeJson = ($this->decrypt)($wrapper);
        // Cache so repeated bytes()/save() don't re-fetch.
        $this->envelopeJson = $envelopeJson;
        return $envelopeJson;
    }
}
