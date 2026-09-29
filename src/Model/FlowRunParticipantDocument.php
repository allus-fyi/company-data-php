<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * One of a participant's own documents on a run — one per output document the leaf produced for
 * that participant. {@see $position} is the step's 1-based place in the run's ONE signing line;
 * null for a party the output's signer list does not name (its copy is `active` from the start,
 * owing nothing).
 */
final class FlowRunParticipantDocument
{
    public function __construct(
        public readonly ?string $outputKey,
        public readonly ?string $name,
        public readonly ?string $documentId,
        public readonly ?string $documentStatus,
        public readonly bool $requiresSignature,
        public readonly bool $requiresAcceptance,
        public readonly ?int $position,
        /** 'signed' | 'accepted' | null — null until this document has been acted on. */
        public readonly ?string $action,
        public readonly ?string $actedAt,
    ) {
    }

    public static function fromApi(array $obj): self
    {
        return new self(
            outputKey: isset($obj['output_key']) ? (string) $obj['output_key'] : null,
            name: isset($obj['name']) ? (string) $obj['name'] : null,
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
