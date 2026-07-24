<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Claim;
use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\OAuthClient;
use Allus\CompanyData\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/** "Sign in with allme" RP OAuth client tests (#195). Ports test_oauth.py. */
final class OAuthTest extends TestCase
{
    private const VECTOR = __DIR__ . '/../testdata/decryption-vector.json';

    private function idwCfg(?string $pem = null, ?string $pass = null): Config
    {
        return new Config(
            apiUrl: 'https://api.allme.fyi',
            oauthClientId: 'idw_abc123',
            oauthRedirectUri: 'https://shop.example/cb',
            oauthPrivateKey: $pem,
            oauthKeyPassphrase: $pass,
        );
    }

    /** @return array{0:string,1:array<string,string>} */
    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $q);
        /** @var array<string,string> $q */
        return [$parts['scheme'] . '://' . $parts['host'] . $parts['path'], $q];
    }

    public function testIdwConfigRequiresClientAndRedirect(): void
    {
        $p = tempnam(sys_get_temp_dir(), 'idw') . '.json';
        file_put_contents($p, json_encode(['api_url' => 'https://api.allme.fyi']));
        $this->expectException(ConfigError::class);
        Config::fromIdwFile($p);
    }

    public function testAuthorizeUrlSigninGolden(): void
    {
        $c = new OAuthClient($this->idwCfg(), new FakeTransport());
        [$base, $q] = $this->parseUrl($c->authorizeUrl('signin', state: 'st1'));
        $this->assertSame('https://web.allme.fyi/auth', $base);
        $this->assertSame('idw_abc123', $q['client_id']);
        $this->assertSame('https://shop.example/cb', $q['redirect_uri']);
        $this->assertSame('signin', $q['mode']);
        $this->assertSame('redirect', $q['response_mode']);
        $this->assertSame('st1', $q['state']);
        $this->assertArrayNotHasKey('claims', $q);
    }

    public function testAuthorizeUrlPkceAndDetached(): void
    {
        $c = new OAuthClient($this->idwCfg(), new FakeTransport());
        [, $q] = $this->parseUrl($c->authorizeUrl('signin', responseMode: 'detached', codeChallenge: 'CH'));
        $this->assertSame('detached', $q['response_mode']);
        $this->assertSame('CH', $q['code_challenge']);
        $this->assertSame('S256', $q['code_challenge_method']);
    }

    public function testAuthorizeUrlClaimValidation(): void
    {
        $c = new OAuthClient($this->idwCfg(), new FakeTransport());
        $claims = [
            new Claim('email', suggest: 'email_personal'),
            new Claim('photo'),
            new Claim('phone', required: true),
            new Claim(''),
        ];
        [, $q] = $this->parseUrl($c->authorizeUrl('one_time', claims: $claims));
        $parsed = json_decode($q['claims'], true);
        $this->assertSame(['email', 'phone'], array_column($parsed, 'type'));
        $this->assertSame('email_personal', $parsed[0]['suggest']);
        $this->assertTrue($parsed[1]['required']);
    }

    public function testAuthorizeUrlCaps15(): void
    {
        $c = new OAuthClient($this->idwCfg(), new FakeTransport());
        $claims = array_fill(0, 30, new Claim('text'));
        [, $q] = $this->parseUrl($c->authorizeUrl('one_time', claims: $claims));
        $this->assertCount(15, json_decode($q['claims'], true));
    }

    public function testAuthorizeUrlInvalidMode(): void
    {
        $c = new OAuthClient($this->idwCfg(), new FakeTransport());
        $this->expectException(ConfigError::class);
        $c->authorizeUrl('bogus');
    }

    public function testExchangeAndUserinfo(): void
    {
        $t = new FakeTransport();
        $t->postResponses[] = FakeTransport::json(200, ['access_token' => 'AT', 'mode' => 'signin']);
        $t->getResponses[] = FakeTransport::json(200, [
            'sub' => 'u1', 'share_code' => 'AB12CD', 'display_name' => 'Alice', 'mode' => 'signin', 'two_factor' => false,
        ]);
        $c = new OAuthClient($this->idwCfg(), $t);
        $tok = $c->exchangeCode('CODE', 'V');
        $this->assertSame('AT', $tok['access_token']);
        $this->assertSame('authorization_code', $t->posts[0]['form']['grant_type']);
        $this->assertSame('V', $t->posts[0]['form']['code_verifier']);
        $info = $c->userinfo('AT');
        $this->assertSame('Alice', $info['display_name']);
    }

    public function testCompleteSignInDecrypts(): void
    {
        $vec = json_decode((string) file_get_contents(self::VECTOR), true);
        $pem = tempnam(sys_get_temp_dir(), 'pem');
        file_put_contents($pem, $vec['encrypted_private_key_pem']);
        $t = new FakeTransport();
        $t->postResponses[] = FakeTransport::json(200, ['access_token' => 'AT', 'mode' => 'one_time']);
        $t->getResponses[] = FakeTransport::json(200, [
            'sub' => 'u1', 'display_name' => 'Alice', 'mode' => 'one_time', 'two_factor' => true,
            'values' => ['email_personal' => $vec['text']['wrapper']],
        ]);
        $c = new OAuthClient($this->idwCfg($pem, $vec['passphrase']), $t);
        $res = $c->completeSignIn('CODE', 'V');
        $this->assertSame('one_time', $res['mode']);
        $this->assertTrue($res['two_factor']);
        $this->assertSame('Alice', $res['user']['display_name']);
        $this->assertSame($vec['text']['plaintext'], $res['values']['email_personal']);
    }

    public function testPollResultPendingThenCode(): void
    {
        $t = new FakeTransport();
        $t->postResponses[] = FakeTransport::text(202, '');
        $t->postResponses[] = FakeTransport::text(202, '');
        $t->postResponses[] = FakeTransport::json(200, ['code' => 'AUTHCODE', 'state' => 'DET1']);
        $c = new OAuthClient($this->idwCfg(), $t, sleep: static fn (int $s) => null);
        $res = $c->pollResult('DET1', 5, 0);
        $this->assertSame('AUTHCODE', $res['code']);
        $this->assertCount(3, $t->posts);
    }

    public function testPollResultExpired(): void
    {
        $t = new FakeTransport();
        $t->postResponses[] = FakeTransport::json(410, ['error_key' => 'oauth.result_expired']);
        $c = new OAuthClient($this->idwCfg(), $t, sleep: static fn (int $s) => null);
        try {
            $c->pollResult('DET1', 5, 0);
            $this->fail('expected ApiError');
        } catch (ApiError $e) {
            $this->assertSame(410, $e->status);
        }
    }

    // ── #481: 2fa_enroll mode + detached enrollment poll delivery ──────────────

    public function testAuthorizeUrlAccepts2faEnrollMode(): void
    {
        $c = new OAuthClient($this->idwCfg(), new FakeTransport());
        [, $q] = $this->parseUrl($c->authorizeUrl('2fa_enroll', responseMode: 'detached', state: 'EN1'));
        $this->assertSame('2fa_enroll', $q['mode']);
        $this->assertSame('detached', $q['response_mode']);
    }

    public function testPollResultPendingThenEnrolled(): void
    {
        // #481: a detached 2fa_enroll delivers {enrolled: true, state}, NOT a code. pollResult must
        // return on the `enrolled` sentinel — otherwise it consumes the one-shot result and times out.
        $t = new FakeTransport();
        $t->postResponses[] = FakeTransport::text(202, '');
        $t->postResponses[] = FakeTransport::json(200, ['enrolled' => true, 'state' => 'EN1']);
        $c = new OAuthClient($this->idwCfg(), $t, sleep: static fn (int $s) => null);
        $res = $c->pollResult('EN1', 5, 0);
        $this->assertTrue($res['enrolled']);
        $this->assertSame('EN1', $res['state']);
        $this->assertCount(2, $t->posts); // returned on first delivery, never polled past it
    }

    public function testPollResultStillReturnsOnCodeAfterEnrollChange(): void
    {
        // Regression: the enroll addition must not break the sign-in `code` delivery.
        $t = new FakeTransport();
        $t->postResponses[] = FakeTransport::json(200, ['code' => 'AUTHCODE', 'state' => 'DET1']);
        $c = new OAuthClient($this->idwCfg(), $t, sleep: static fn (int $s) => null);
        $this->assertSame('AUTHCODE', $c->pollResult('DET1', 5, 0)['code']);
    }
}
