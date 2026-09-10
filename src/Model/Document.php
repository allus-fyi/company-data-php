<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\Errors\DecryptError;

/**
 * A company document the SDK created/queried (company-data side).
 *
 * value semantics mirror the connection-payload contract — keyed on
 * BROADCAST(plaintext) vs PER-PERSON(always encrypted), NOT on is_private:
 *   broadcast file   -> {file, original_name, mime_type, size}   (plaintext)
 *   per-person file  -> {"_enc_file": "enc_…json"}   (ciphertext blob, ANY is_private)
 *   broadcast json   -> the JSON object   (plaintext)
 *   per-person json  -> {"_enc":1,k,iv,d}   (ciphertext wrapper, ANY is_private;
 *                                            decrypt on demand via json())
 * is_private is device-display-only (lock vs decrypt-on-load), not the value shape.
 */
final class Document
{
    /**
     * @param array<string,mixed>|string|null $value
     * @param array<string,mixed>|null $metadata
     * @param (callable(array<string,mixed>|string): string)|null $decryptValue closure over the service key.
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $kind,
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $status,
        /** 'file' | 'json' */
        public readonly ?string $payloadKind,
        public readonly bool $isPrivate,
        public readonly array|string|null $value,
        public readonly ?array $metadata,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly bool $requiresSignature = false,
        public readonly bool $requiresAcceptance = false,
        /** SHA-256 of the unencrypted PDF bytes, lowercase hex. Null on a JSON contract. */
        public readonly ?string $plainSha256 = null,
        /** When the platform seal was applied. Null until sealed. */
        public readonly ?\DateTimeImmutable $sealedAt = null,
        /**
         * @var array<int,array<string,mixed>> contract sign/accept audit trail (company-side
         *      reads only), one entry per signature: action, method, content_sha256,
         *      plain_sha256, signer_first_name, signer_last_name, signer_name_verified, ip,
         *      user_agent, created_at.
         */
        public readonly array $signatures = [],
        /**
         * @var array<int,array<string,mixed>>|null present only on a contract-flow run-participant
         *      document: the run's ordered signature summary — one entry per participant owing an
         *      act, each `{party_key, document_id, position, status, action, acted_at}`. Null on
         *      any other document.
         */
        public readonly ?array $runSignatures = null,
        private $decryptValue = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * For a json document, return the plaintext object.
     *
     * Decryption is keyed on the value SHAPE (per-person → {@code {"_enc":1,…}}
     * wrapper), NOT on is_private: a per-person json doc (ANY is_private) is an
     * encrypted wrapper decrypted with the SDK's own private key; a broadcast json
     * doc is already plaintext and returned as-is.
     *
     * @return array<string,mixed>|mixed
     *
     * @throws DecryptError
     */
    public function json(): mixed
    {
        if ($this->payloadKind !== 'json') {
            throw new DecryptError("json() is only valid for payloadKind='json' documents");
        }
        if (is_array($this->value) && ($this->value['_enc'] ?? null) === 1) {
            if ($this->decryptValue === null) {
                throw new DecryptError('no decrypt wiring for an encrypted (per-person) document');
            }
            $plaintext = ($this->decryptValue)($this->value);
            try {
                return json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new DecryptError('decrypted document value is not valid JSON', 0, $e);
            }
        }
        return $this->value;
    }

    /**
     * @param array<string,mixed> $obj
     * @param (callable(array<string,mixed>|string): string)|null $decryptValue
     */
    public static function fromApi(array $obj, ?callable $decryptValue = null): self
    {
        /** @var array<string,mixed>|string|null $value */
        $value = $obj['value'] ?? null;
        /** @var array<string,mixed>|null $metadata */
        $metadata = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : null;

        return new self(
            id: isset($obj['id']) ? (string) $obj['id'] : null,
            kind: isset($obj['kind']) ? (string) $obj['kind'] : null,
            name: isset($obj['name']) ? (string) $obj['name'] : null,
            description: isset($obj['description']) ? (string) $obj['description'] : null,
            status: isset($obj['status']) ? (string) $obj['status'] : null,
            payloadKind: isset($obj['payload_kind']) ? (string) $obj['payload_kind'] : null,
            isPrivate: (bool) Coerce::bool($obj['is_private'] ?? null),
            value: $value,
            metadata: $metadata,
            createdAt: Coerce::dateTime($obj['created_at'] ?? null),
            updatedAt: Coerce::dateTime($obj['updated_at'] ?? null),
            requiresSignature: (bool) Coerce::bool($obj['requires_signature'] ?? null),
            requiresAcceptance: (bool) Coerce::bool($obj['requires_acceptance'] ?? null),
            plainSha256: isset($obj['plain_sha256']) ? (string) $obj['plain_sha256'] : null,
            sealedAt: Coerce::dateTime($obj['sealed_at'] ?? null),
            // Each signature entry stays an untyped array (matching every existing
            // signature field), but signer_name_verified is a boolean in the schema —
            // XML carries it as the string "false"/"true", and a caller testing that
            // raw string for truthiness reads a false verification as verified. Coerce
            // it the same way every other boolean field on this transport is coerced.
            signatures: is_array($obj['signatures'] ?? null)
                ? array_map(
                    static fn (array $s): array => array_key_exists('signer_name_verified', $s)
                        ? [...$s, 'signer_name_verified' => Coerce::bool($s['signer_name_verified'])]
                        : $s,
                    array_values(array_filter($obj['signatures'], 'is_array')),
                )
                : [],
            runSignatures: is_array($obj['run_signatures'] ?? null) ? array_values(array_filter($obj['run_signatures'], 'is_array')) : null,
            decryptValue: $decryptValue,
            raw: $obj,
        );
    }

    /**
     * Parse the {@code /documents} list response → a list of documents.
     *
     * @param array<string,mixed>|list<mixed> $body
     * @param (callable(array<string,mixed>|string): string)|null $decryptValue
     *
     * @return list<self>
     */
    public static function listFromApi(array $body, ?callable $decryptValue = null): array
    {
        if (array_is_list($body)) {
            $items = $body;
        } else {
            $items = $body['items'] ?? [];
        }
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = self::fromApi($o, $decryptValue);
            }
        }
        return $out;
    }
}
