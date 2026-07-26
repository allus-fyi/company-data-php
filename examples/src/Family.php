<?php

declare(strict_types=1);

namespace Allus\Examples;

/**
 * The contract every scenario-family handler implements so the shared {@see Server} can dispatch to it
 * generically by scenario id (spec §3 / #494). A family owns ONLY its scenario handlers — the SDK calls;
 * the HTTP routing, run store, config files, bundle fetch and port guard all live in the shared
 * scaffolding, never here.
 *
 * Identity additionally exposes enroll()/callback() and company-data exposes webhook() — those are
 * family-specific public paths the Server routes directly to the concrete handler.
 */
interface Family
{
    /**
     * This family's scenarios for GET /api/meta, in display order.
     *
     * @return list<array{id:int|string,kind:string}>
     */
    public function scenarios(): array;

    /**
     * POST /api/scenarios/{id}/config — persist the browser's setup values to a canonical SDK config file.
     *
     * @param array<string,mixed> $in the decoded request body
     */
    public function config(string $id, array $in): Response;

    /** POST /api/scenarios/{id}/start — build the SDK from the persisted config file and begin the run. */
    public function start(string $id): Response;

    /**
     * GET /api/runs/{runId} — advance a pending run one short cycle (if the family drives one) and render
     * its current outcome. The Server routes here by the run's recorded family.
     *
     * @param array<string,mixed> $run the persisted run state
     */
    public function run(string $runId, array $run): Response;
}
