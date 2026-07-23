<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * #436 2FA-by-allme — the outcome of TwoFactorClient::result() (spec §3). The poll is the record: the
 * first read of a terminal state delivers it and burns it (a later read is "gone").
 */
final class TwoFactorResult
{
    public function __construct(
        /** "pending" | "approved" | "denied" | "expired" | "revoked" | "gone". */
        public readonly string $status,
        /** Set while pending. */
        public readonly ?string $expiresAt,
        /** Set on a terminal outcome. */
        public readonly ?string $completedAt,
    ) {
    }

    /** @param array<string,mixed> $obj */
    public static function fromApi(array $obj): self
    {
        return new self(
            status: (string) ($obj['status'] ?? ''),
            expiresAt: isset($obj['expires_at']) ? (string) $obj['expires_at'] : null,
            completedAt: isset($obj['completed_at']) ? (string) $obj['completed_at'] : null,
        );
    }
}
