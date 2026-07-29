<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * 2FA-by-allme — a login-approval challenge returned by TwoFactorClient::challenge() (spec §3).
 */
final class TwoFactorChallenge
{
    public function __construct(
        /** Opaque challenge id — pass to TwoFactorClient::result(). */
        public readonly string $challengeId,
        /** Always "pending" on creation. */
        public readonly string $status,
        /** ISO-8601 expiry (5-minute TTL). */
        public readonly string $expiresAt,
        /**
         * Present only when the service has number matching on — the two digits to DISPLAY on your login
         * page. The person types them back into the allme app; the SERVER adjudicates them.
         */
        public readonly ?string $matchingDigits,
    ) {
    }

    /** @param array<string,mixed> $obj */
    public static function fromApi(array $obj): self
    {
        return new self(
            challengeId: (string) ($obj['challenge_id'] ?? ''),
            status: (string) ($obj['status'] ?? ''),
            expiresAt: (string) ($obj['expires_at'] ?? ''),
            matchingDigits: isset($obj['matching_digits']) && $obj['matching_digits'] !== null
                ? (string) $obj['matching_digits'] : null,
        );
    }
}
