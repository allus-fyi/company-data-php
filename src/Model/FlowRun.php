<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * A contract-flow run (company-data side).
 *
 * The company is one of the two bound parties. {@see $bindings} maps each party key to the bound
 * user_id (the company's own is {@see $companyUserId}); {@see $answers} are the per-party encrypted
 * answer copies (the company reads the rows whose {@code for_user_id == companyUserId}, decryptable
 * with the service private key); {@see $definition} is the pinned flow-version graph (nodes, edges,
 * parties, output_mode).
 *
 * {@see $answers} is the raw list of {@code {slug, for_user_id, value}} rows; the client decrypts
 * the company's copies on demand.
 */
final class FlowRun
{
    /**
     * @param array<string,string>            $bindings
     * @param array<string,mixed>             $definition
     * @param list<array<string,mixed>>       $answers
     * @param array<string,mixed>             $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $flowId,
        public readonly mixed $flowVersion,
        public readonly ?string $serviceId,
        public readonly ?string $connectionId,
        public readonly ?string $companyUserId,
        public readonly array $bindings,
        public readonly ?string $status,
        public readonly ?string $currentNode,
        public readonly ?string $documentId,
        public readonly ?string $outputMode,
        public readonly array $definition,
        public readonly array $answers,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly array $raw = [],
    ) {
    }

    /** The party key the company is bound to ({@code bindings[key] === companyUserId}). */
    public function companyPartyKey(): ?string
    {
        if ($this->companyUserId === null) {
            return null;
        }
        foreach ($this->bindings as $key => $uid) {
            if ($uid === $this->companyUserId) {
                return $key;
            }
        }
        return null;
    }

    /** The company's bound user_id — its answer copies use this for_user_id. */
    public function serviceUserId(): ?string
    {
        return $this->companyUserId;
    }

    /**
     * @param array<string,mixed> $obj
     */
    public static function fromApi(array $obj): self
    {
        $definition = is_array($obj['definition'] ?? null)
            ? $obj['definition']
            : [
                'nodes' => $obj['nodes'] ?? null,
                'edges' => $obj['edges'] ?? null,
                'parties' => $obj['parties'] ?? null,
                'output_mode' => $obj['output_mode'] ?? null,
            ];

        $bindings = [];
        if (is_array($obj['bindings'] ?? null)) {
            foreach ($obj['bindings'] as $k => $v) {
                $bindings[(string) $k] = $v === null ? '' : (string) $v;
            }
        }

        $answers = [];
        if (is_array($obj['answers'] ?? null)) {
            foreach ($obj['answers'] as $a) {
                if (is_array($a)) {
                    $answers[] = $a;
                }
            }
        }

        $outputMode = isset($obj['output_mode']) ? (string) $obj['output_mode'] : null;
        if ($outputMode === null || $outputMode === '') {
            $outputMode = isset($definition['output_mode']) ? (string) $definition['output_mode'] : null;
        }

        return new self(
            id: isset($obj['id']) ? (string) $obj['id'] : null,
            flowId: isset($obj['flow_id']) ? (string) $obj['flow_id'] : null,
            flowVersion: $obj['flow_version'] ?? null,
            serviceId: isset($obj['service_id']) ? (string) $obj['service_id'] : null,
            connectionId: isset($obj['connection_id']) ? (string) $obj['connection_id'] : null,
            companyUserId: isset($obj['company_user_id']) ? (string) $obj['company_user_id'] : null,
            bindings: $bindings,
            status: isset($obj['status']) ? (string) $obj['status'] : null,
            currentNode: isset($obj['current_node']) ? (string) $obj['current_node'] : null,
            documentId: isset($obj['document_id']) ? (string) $obj['document_id'] : null,
            outputMode: $outputMode,
            definition: $definition,
            answers: $answers,
            createdAt: Coerce::dateTime($obj['created_at'] ?? null),
            updatedAt: Coerce::dateTime($obj['updated_at'] ?? null),
            raw: $obj,
        );
    }
}
