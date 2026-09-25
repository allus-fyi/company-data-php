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
 * The shape comes from the type's RESOLVED definition in the served registry
 * ({@see FieldTypes}) — its storage lane and its primitive — so a type added to the
 * registry types itself from the day it is a row:
 *   storage lane photo/document → a lazy BinaryHandle
 *   primitive composite         → array (the decrypted plaintext is a JSON object → parsed)
 *   primitive multilist         → array (a JSON array of option strings)
 *   primitive date              → DateTimeImmutable (falls back to the raw string)
 *   everything else             → string
 * The reserved type key `plugin` is typed first, before the registry: a {@see PluginValue}.
 */
final class ValueTyping
{
    /**
     * @param array<string,mixed> $obj         one hardened {value|value_url, live, updatedAt} entry.
     * @param callable(): FieldTypes $fieldTypes the served registry, taken as a CALLABLE: resolving a
     *        slug is what heals the registry, so a factory handed the registry itself would hold the one
     *        from BEFORE the heal and type the very value that triggered it against rows without its type.
     * @param callable(array<string,mixed>|string): string $decryptValue closure over the service key.
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch slot file fetch.
     *
     * @return string|array<string,mixed>|\DateTimeImmutable|BinaryHandle|PluginValue|null
     *
     * @throws DecryptError
     * @throws \Allus\CompanyData\Errors\ValidationError a plugin value that is not a finished plugin answer
     */
    public static function typed(
        array $obj,
        ?string $fieldType,
        callable $fieldTypes,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): string|array|\DateTimeImmutable|BinaryHandle|PluginValue|null {
        $ftype = strtolower($fieldType ?? '');

        // The type key `plugin` is reserved and never a registry row: a plugin answer is a
        // self-describing JSON object, typed here before the registry is consulted.
        if ($ftype === 'plugin') {
            if (!array_key_exists('value', $obj) || $obj['value'] === null) {
                return null;
            }
            /** @var array<string,mixed>|string $cipher */
            $cipher = $obj['value'];

            return PluginValue::parse($decryptValue($cipher));
        }

        $registry = $fieldTypes();
        $definition = $registry->resolve($ftype);

        // Binary → a lazy handle over the slot value_url (no eager fetch/decrypt).
        if ($registry->isBinary($ftype) || array_key_exists('value_url', $obj)) {
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

        if ($definition['input'] === 'composite' || $definition['input'] === 'multilist') {
            try {
                $parsed = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new DecryptError("structured value for type '{$ftype}' is not valid JSON", 0, $e);
            }
            return is_array($parsed) ? $parsed : ['value' => $parsed];
        }

        if ($definition['input'] === 'date') {
            $d = Coerce::date($plaintext);
            return $d ?? $plaintext;
        }

        // Every other primitive, and a type the registry does not carry, is the plaintext string.
        return $plaintext;
    }
}
