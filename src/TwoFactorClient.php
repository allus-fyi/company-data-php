<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Model\TwoFactorChallenge;
use Allus\CompanyData\Model\TwoFactorResult;

/**
 * #436 2FA-by-allme — the relying-party challenge API (spec §3), on the SERVICE's data-client credentials
 * (the same auth {@see Client} uses). Reached via {@see Client::twoFactor()}.
 *
 * A service asks a person (by `share_code`) to approve a login inside the allme app, then polls for the
 * outcome. The poll is the record: the first read of a terminal state delivers it and burns it. A webhook
 * (`2fa_challenge_completed`) is the best-effort push equivalent; the poll remains authoritative.
 */
final class TwoFactorClient
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Initiate a login-approval challenge.
     *
     * @param string      $shareCode      the person's profile share code
     * @param string      $idempotencyKey required (<=64); a repeat within the TTL returns the SAME challenge and sends no second push
     * @param string|null $context        plain text shown to the person (<=200 chars)
     */
    public function challenge(string $shareCode, string $idempotencyKey, ?string $context = null): TwoFactorChallenge
    {
        $body = $this->http->post('/api/service-2fa/challenges', [
            'share_code' => $shareCode,
            'context' => $context,
            'idempotency_key' => $idempotencyKey,
        ]);

        return TwoFactorChallenge::fromApi(is_array($body) ? $body : []);
    }

    /** Poll a challenge. While pending, `status` is "pending"; the first terminal read burns the result. */
    public function result(string $challengeId): TwoFactorResult
    {
        $body = $this->http->get('/api/service-2fa/challenges/' . rawurlencode($challengeId));

        return TwoFactorResult::fromApi(is_array($body) ? $body : []);
    }
}
