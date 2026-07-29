<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\Crypto\BinaryHandle;

/**
 * A change feed / webhook event.
 *
 * {@see $id} is the stable server change-row id (the pump dedupes on it after a
 * crash/replay); {@see $at} is the change time (there is NO separate
 * updatedAt on a change). {@see $shareCode} is the person's profile share code,
 * present on every event (may be null). {@see $slug}/{@see $value}/{@see $live}
 * are present only on {@code field_updated} (connection/consent events carry no
 * slot/value). {@see $documentId}/{@see $status}/{@see $note} are set on
 * {@code document_status_changed}.
 */
final class Change
{
    /**
     * @param string|array<string,mixed>|\DateTimeImmutable|BinaryHandle|null $value
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $event,
        public readonly ?string $personId,
        /** The person's profile share code (every event; may be null). */
        public readonly ?string $shareCode = null,
        /** The customer's TYPE: "person"|"company" (B2B); null on older API. */
        public readonly ?string $customerType = null,
        public readonly ?string $slug = null,
        public readonly string|array|\DateTimeImmutable|BinaryHandle|null $value = null,
        public readonly ?bool $live = null,
        /** Set on document_status_changed. */
        public readonly ?string $documentId = null,
        /** Set on document_status_changed. */
        public readonly ?string $status = null,
        /** Set on document_status_changed for a contract: signed | accepted | cancelled. */
        public readonly ?string $action = null,
        /** Set on document_status_changed: the person's optional cancellation note. */
        public readonly ?string $note = null,
        /** Set on a signature: biometric | twofa | email | custodian. */
        public readonly ?string $method = null,
        /** Set on a signature: SHA-256 of the signed content. */
        public readonly ?string $contentSha256 = null,
        /** Set on a signature: ISO timestamp the signature was recorded. */
        public readonly ?string $signedAt = null,
        /** Set on a cancelled document_status_changed: ISO date the cancellation takes effect. */
        public readonly ?string $cancelEffectiveDate = null,
        /** Set on connection_request_accepted | connection_request_rejected. */
        public readonly ?string $requestId = null,
        /** Set on key_rotated — SHA-256 fingerprint of the person's NEW public key. */
        public readonly ?string $publicKeySha256 = null,
        public readonly bool $verified = false,
        public readonly ?\DateTimeImmutable $at = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * Build a Change from one hardened changes-feed / webhook event object.
     *
     * @param array<string,mixed> $obj
     * @param callable(string): ?string $typeForSlug
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     */
    public static function fromApi(
        array $obj,
        callable $typeForSlug,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): self {
        $slug = isset($obj['slug']) ? (string) $obj['slug'] : null;
        $event = isset($obj['event']) ? (string) $obj['event'] : null;
        $live = array_key_exists('live', $obj) ? Coerce::bool($obj['live']) : null;

        $value = null;
        if ($event === 'field_updated' && $slug !== null) {
            // Reuse the Value typing path so feed + connection produce identical
            // typed values (incl. the same lazy BinaryHandle for binaries).
            if (array_key_exists('value', $obj) || array_key_exists('value_url', $obj)) {
                $value = ValueTyping::typed($obj, $typeForSlug($slug), $decryptValue, $binaryFetch);
            }
        }

        $personId = $obj['person_user_id'] ?? ($obj['person_id'] ?? null);

        return new self(
            id: isset($obj['id']) ? (string) $obj['id'] : null,
            event: $event,
            personId: $personId !== null ? (string) $personId : null,
            shareCode: isset($obj['share_code']) ? (string) $obj['share_code'] : null,
            customerType: isset($obj['customer_type']) ? (string) $obj['customer_type'] : null,
            slug: $slug,
            value: $value,
            live: $live,
            documentId: isset($obj['document_id']) ? (string) $obj['document_id'] : null,
            // 2fa_challenge_completed carries the outcome in `status` (approved|denied|revoked);
            // its challenge_id/completed_at stay in $raw. The poll is the record (spec §3).
            status: (($event === 'document_status_changed' || $event === '2fa_challenge_completed') && isset($obj['status'])) ? (string) $obj['status'] : null,
            action: ($event === 'document_status_changed' && isset($obj['action'])) ? (string) $obj['action'] : null,
            note: ($event === 'document_status_changed' && isset($obj['note'])) ? (string) $obj['note'] : null,
            method: ($event === 'document_status_changed' && isset($obj['method'])) ? (string) $obj['method'] : null,
            contentSha256: ($event === 'document_status_changed' && isset($obj['content_sha256'])) ? (string) $obj['content_sha256'] : null,
            signedAt: ($event === 'document_status_changed' && isset($obj['signed_at'])) ? (string) $obj['signed_at'] : null,
            cancelEffectiveDate: ($event === 'document_status_changed' && isset($obj['cancel_effective_date'])) ? (string) $obj['cancel_effective_date'] : null,
            requestId: (in_array($event, ['connection_request_accepted', 'connection_request_rejected'], true)
                && isset($obj['request_id'])) ? (string) $obj['request_id'] : null,
            publicKeySha256: ($event === 'key_rotated' && isset($obj['public_key_sha256']))
                ? (string) $obj['public_key_sha256'] : null,
            verified: Value::verifiedFrom($obj, $value),
            at: Coerce::dateTime($obj['at'] ?? null),
            raw: $obj,
        );
    }

    /**
     * Parse the {@code /changes} response → a list of typed Change events.
     *
     * @param array<string,mixed>|list<mixed> $body
     * @param callable(string): ?string $typeForSlug
     * @param callable(array<string,mixed>|string): string $decryptValue
     * @param (callable(string): (array<string,mixed>|string))|null $binaryFetch
     *
     * @return list<self>
     */
    public static function listFromApi(
        array $body,
        callable $typeForSlug,
        callable $decryptValue,
        ?callable $binaryFetch = null,
    ): array {
        if (array_is_list($body)) {
            $items = $body;
        } else {
            $items = $body['changes'] ?? [];
        }
        $out = [];
        foreach ($items as $o) {
            if (is_array($o)) {
                $out[] = self::fromApi($o, $typeForSlug, $decryptValue, $binaryFetch);
            }
        }
        return $out;
    }
}
