<?php

declare(strict_types=1);

namespace Allus\IdentityExample;

/**
 * PKCE (RFC 7636) verifier + S256 challenge. Pure local crypto — no network, no platform HTTP.
 * The SDK takes the code_challenge into {@see \Allus\CompanyData\OAuthClient::authorizeUrl()} and the
 * code_verifier into {@see \Allus\CompanyData\OAuthClient::completeSignIn()}; the demo generates the pair.
 */
final class Pkce
{
    /** @return array{verifier:string,challenge:string} */
    public static function generate(): array
    {
        $verifier = self::b64url(random_bytes(32));
        $challenge = self::b64url(hash('sha256', $verifier, true));
        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
