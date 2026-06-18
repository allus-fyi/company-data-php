<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\Crypto\BinaryHandle;
use Allus\CompanyData\Errors\DecryptError;

/**
 * Decrypts + coerces one hardened value entry to its typed PHP form.
 *
 * Shared by {@see Value} and {@see Change} so a feed event and a connection value
 * produce identical typed values (incl. the same lazy {@see BinaryHandle} for
 * binaries).
 *
 * Decryption is config-driven: the factory takes a
 * {@code decryptValue} callable (a closure over the loaded service private key)
 * and, for binaries, a {@code binaryFetch} callable — never a key/secret argument.
 *
 * Type → PHP value:
 *   email/phone/url/text  → string
 *   address/bank/creditcard → array (the decrypted plaintext is a JSON object → parsed)
 *   date/date_of_birth    → DateTimeImmutable (falls back to the raw string)
 *   photo/document/legal_document → a lazy BinaryHandle
 */
final class ValueTyping
{
    /**
     * @param array<string,mixed> $obj         one hardened {value|value_url, live, updatedAt} entry.
     * @param callable(array<string,mixed>|string): string $decryptValue closure over the service key.
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch slot file fetch.
     *
     * @return string|array<string,mixed>|\DateTimeImmutable|BinaryHandle|null
     *
     * @throws DecryptError
     */
    public static function typed(
        array $obj,
        ?string $fieldType,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): string|array|\DateTimeImmutable|BinaryHandle|null {
        $ftype = strtolower($fieldType ?? '');

        // Binary → a lazy handle over the slot value_url (no eager fetch/decrypt).
        if (in_array($ftype, FieldTypes::BINARY, true) || array_key_exists('value_url', $obj)) {
            $valueUrl = $obj['value_url'] ?? null;
            if ($valueUrl === null) {
                // Binary type but no url (e.g. unanswered) → an empty handle.
                return new BinaryHandle(envelopeJson: null);
            }
            return new BinaryHandle(
                valueUrl: (string) $valueUrl,
                fetch: $binaryFetch,
                decrypt: $decryptValue,
            );
        }

        // Non-binary → decrypt the ciphertext wrapper to plaintext.
        if (!array_key_exists('value', $obj) || $obj['value'] === null) {
            return null;
        }
        /** @var array<string,mixed>|string $ciphertext */
        $ciphertext = $obj['value'];
        $plaintext = $decryptValue($ciphertext);

        if (in_array($ftype, FieldTypes::STRUCTURED, true)) {
            try {
                $parsed = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new DecryptError("structured value for type '{$ftype}' is not valid JSON", 0, $e);
            }
            return is_array($parsed) ? $parsed : ['value' => $parsed];
        }

        if (in_array($ftype, FieldTypes::DATE, true)) {
            $d = Coerce::date($plaintext);
            return $d ?? $plaintext;
        }

        // text/email/phone/url and anything unknown → the plaintext string.
        return $plaintext;
    }
}
