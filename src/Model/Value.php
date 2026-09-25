<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\Crypto\BinaryHandle;

/**
 * A single answer for one of YOUR request slots.
 *
 * {@see $value} is the typed plaintext (string / array / DateTimeImmutable / lazy
 * {@see BinaryHandle}); {@see $live} = the person chose "keep connected"
 * (auto-updates) vs a one-time snapshot; {@see $updatedAt} = when this answer last
 * changed. Both ride on the Value (per-answer), not the definition.
 */
final class Value
{
    /**
     * @param string|array<string,mixed>|\DateTimeImmutable|BinaryHandle|PluginValue|null $value
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string|array|\DateTimeImmutable|BinaryHandle|PluginValue|null $value,
        public readonly bool $live,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        /** True iff the hash recomputes over the plaintext AND the verification has not lapsed. */
        public readonly bool $verified = false,
        /**
         * When the person's answering field was verified; null when the value carries no
         * verification. A stamp, not a promise about today — read it with {@see $verified}.
         */
        public readonly ?\DateTimeImmutable $verifiedAt = null,
        /**
         * When that verification lapses (a document-backed verification dies with the document);
         * null = it does not lapse. Past → {@see $verified} reads false.
         */
        public readonly ?\DateTimeImmutable $verifiedExpiresAt = null,
        /**
         * HOW allme bound this value: `email_code` | `sms_code` | `sumsub_id` | `sumsub_address`;
         * WHO established the proof: `allme` | `sumsub`; and the id to quote back to allme in a
         * dispute.
         *
         * All three arrive together or not at all — a value bound before the proof log existed
         * carries the four verification keys and none of these, so all three read null. They are
         * readable whatever {@see $verified} says; that boolean stays the only trust decision.
         */
        public readonly ?string $verifiedMethod = null,
        public readonly ?string $verifiedProvider = null,
        public readonly ?string $verificationId = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a typed Value from one hardened {value|value_url, live, updatedAt} entry.
     *
     * @param array<string,mixed> $obj
     * @param callable(): FieldTypes $fieldTypes the served registry, taken as a CALLABLE so the
     *        rows a slug's resolution just healed in govern that same value.
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     */
    public static function fromApi(
        array $obj,
        ?string $fieldType,
        callable $fieldTypes,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): self {
        $live = (bool) Coerce::bool($obj['live'] ?? null);
        $updatedAt = Coerce::dateTime($obj['updatedAt'] ?? ($obj['updated_at'] ?? null));
        $typed = ValueTyping::typed($obj, $fieldType, $fieldTypes, $decryptValue, $binaryFetch);
        return new self(
            value: $typed,
            live: $live,
            updatedAt: $updatedAt,
            verified: self::verifiedFrom($obj, $typed),
            verifiedAt: Coerce::dateTime($obj['verified_at'] ?? null),
            verifiedExpiresAt: Coerce::dateTime($obj['verified_expires_at'] ?? null),
            verifiedMethod: self::optString($obj['verified_method'] ?? null),
            verifiedProvider: self::optString($obj['verified_provider'] ?? null),
            verificationId: self::optString($obj['verification_id'] ?? null),
            raw: $obj,
        );
    }

    /**
     * Recompute the verified flag from the just-decrypted plaintext (text values only).
     *
     * Two conditions, both required: the hash recomputes over the exact plaintext, AND the
     * verification has not lapsed ({@code verified_expires_at} absent or still in the future). A
     * document-backed verification lapses when the document itself expires, so a stale binding
     * reads false here without any lookup.
     *
     * @param array<string,mixed> $obj
     */
    public static function verifiedFrom(array $obj, mixed $plaintext): bool
    {
        if (!is_string($plaintext)) {
            return false;
        }
        $vhash = isset($obj['verified_hash']) ? (string) $obj['verified_hash'] : '';
        $vsalt = isset($obj['verified_salt']) ? (string) $obj['verified_salt'] : '';
        if ($vhash === '' || $vsalt === '') {
            return false;
        }
        if (Coerce::expiryPassed($obj['verified_expires_at'] ?? null)) {
            return false;
        }
        return \Allus\CompanyData\Crypto\Crypto::hashMatches($vsalt, $vhash, $plaintext);
    }

    /**
     * One additive string member, parse-permissively: absent or non-string reads as null.
     *
     * Shared with {@see Change} so the two models read the three proof members the same way — a
     * second coercion here would be free to disagree with it about what an absent member means.
     */
    public static function optString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
