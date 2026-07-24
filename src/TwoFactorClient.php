<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Errors\ApiError;
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
    /** @var callable(int):void */
    private $sleep;

    /** @param callable(int):void|null $sleep sleeper (seconds); injectable so waitForResult is unit-testable without real delays (matches OAuthClient). */
    public function __construct(
        private readonly HttpClient $http,
        $sleep = null,
    ) {
        $this->sleep = $sleep ?? static fn (int $s) => $s > 0 ? sleep($s) : null;
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

    /**
     * Poll {@see result()} until the status is terminal (no longer "pending") and return that first
     * terminal {@see TwoFactorResult}.
     *
     * Convenience over a manual result() loop (#481; mirrors the detached OAuthClient::pollResult
     * precedent). Because the first terminal read burns the challenge, this returns as soon as the
     * status leaves "pending" — it never re-reads a consumed result. Throws {@see ApiError} if
     * `$timeout` seconds elapse while still pending; `$interval` is the seconds between polls.
     */
    public function waitForResult(string $challengeId, int $timeout = 600, int $interval = 2): TwoFactorResult
    {
        $deadline = hrtime(true) / 1e9 + $timeout;
        while (true) {
            $res = $this->result($challengeId);
            if ($res->status !== 'pending') {
                return $res;
            }
            if (hrtime(true) / 1e9 >= $deadline) {
                throw new ApiError(0, null, "2FA challenge {$challengeId} not completed within {$timeout}s");
            }
            ($this->sleep)($interval);
        }
    }
}
