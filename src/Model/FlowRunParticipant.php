<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * One participant's row on a run's `participants[]` — the durable participant set.
 * {@see $documents} holds the participant's own copy of every output document the run produced,
 * ordered by signing-line position (unlisted last); empty before generation. One account may hold
 * TWO of these (two owner parties, or one customer bound to two party keys) — never collapse this
 * to a single row by user id.
 */
final class FlowRunParticipant
{
    /**
     * @param list<FlowRunParticipantDocument> $documents
     */
    public function __construct(
        public readonly ?string $partyKey,
        public readonly ?string $personUserId,
        public readonly ?string $connectionId,
        public readonly array $documents = [],
    ) {
    }

    public static function fromApi(array $obj): self
    {
        $documents = [];
        foreach (is_array($obj['documents'] ?? null) ? $obj['documents'] : [] as $d) {
            if (is_array($d)) {
                $documents[] = FlowRunParticipantDocument::fromApi($d);
            }
        }
        return new self(
            partyKey: isset($obj['party_key']) ? (string) $obj['party_key'] : null,
            personUserId: isset($obj['person_user_id']) ? (string) $obj['person_user_id'] : null,
            connectionId: isset($obj['connection_id']) ? (string) $obj['connection_id'] : null,
            documents: $documents,
        );
    }
}
