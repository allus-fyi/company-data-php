<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * One participant's row on a run's `participants[]` (flows.html §5a/§9 item 12) — the durable
 * participant set, additively carrying its place in the leaf PDF rule's ordered signing plan.
 * One account may hold TWO of these (two owner parties, or one customer bound to two party
 * keys) — never collapse this to a single row by user id.
 */
final class FlowRunParticipant
{
    public function __construct(
        public readonly ?string $partyKey,
        public readonly ?string $personUserId,
        public readonly ?string $connectionId,
        public readonly ?string $documentId,
        public readonly ?string $documentStatus,
        public readonly bool $requiresSignature,
        public readonly bool $requiresAcceptance,
        /** 1-based place in the signing plan; null for a party the plan does not name. */
        public readonly ?int $position,
        /** 'signed' | 'accepted' | null — null until this participant's document has acted. */
        public readonly ?string $action,
        public readonly ?string $actedAt,
    ) {
    }

    public static function fromApi(array $obj): self
    {
        return new self(
            partyKey: isset($obj['party_key']) ? (string) $obj['party_key'] : null,
            personUserId: isset($obj['person_user_id']) ? (string) $obj['person_user_id'] : null,
            connectionId: isset($obj['connection_id']) ? (string) $obj['connection_id'] : null,
            documentId: isset($obj['document_id']) ? (string) $obj['document_id'] : null,
            documentStatus: isset($obj['document_status']) ? (string) $obj['document_status'] : null,
            requiresSignature: (bool) ($obj['requires_signature'] ?? false),
            requiresAcceptance: (bool) ($obj['requires_acceptance'] ?? false),
            position: isset($obj['position']) ? (int) $obj['position'] : null,
            action: isset($obj['action']) ? (string) $obj['action'] : null,
            actedAt: isset($obj['acted_at']) ? (string) $obj['acted_at'] : null,
        );
    }
}
