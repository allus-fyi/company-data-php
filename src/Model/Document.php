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
