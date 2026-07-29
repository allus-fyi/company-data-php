<?php

declare(strict_types=1);

namespace Allus\CompanyData;

/**
 * A claim the relying party asks for — a REQUEST FIELD.
 *
 * You describe what you need: a `$name` (the claim's identity on the wire), a field `$type`, an
 * advisory `$suggest`ion, whether it is `$required`, and whether only a `$verified` answer will
 * do. You never name one of the person's fields — THEY decide which of theirs answers it.
 *
 * `$name` is MANDATORY and must be unique within one request: everything downstream is keyed by it
 * (the stored mapping, the consent outcome, and the `values`/`attestations` maps `completeSignIn()`
 * returns). Two claims sharing a name are rejected rather than silently coalesced.
 *
 * `$verified` is accepted only where it can be honoured (§3.1b): on the OIDC flow, and only for
 * a type that can be attested (v1: `email`). Sending it on a `one_time` request is refused with
 * `invalid_request` — that leg carries no source row id, so the server could neither enforce the
 * requirement nor attest it, and an unhonourable requirement is refused rather than quietly dropped.
 */
final class Claim
{
    public function __construct(
        /** REQUIRED — the claim's identity on the wire; `values`/`attestations` are keyed by it. */
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $suggest = null,
        public readonly bool $required = false,
        /** Only a verified answer satisfies this claim. OIDC flow + verifiable types only. */
        public readonly bool $verified = false,
        public readonly ?string $label = null,
    ) {
    }
}
