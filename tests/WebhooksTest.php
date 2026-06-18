<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Client;
use Allus\CompanyData\Config;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\WebhookError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Http\Response;
use Allus\CompanyData\Tests\Support\FakeTransport;
use Allus\CompanyData\Tests\Support\Vector;
use Allus\CompanyData\Webhooks\Webhooks;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;
use phpseclib3\Crypt\RSA\PublicKey as RSAPublicKey;

/**
 * Webhook receiver-helper tests — no live API.
 *
 * Fixture requests are built exactly like the platform's WebhookDeliveryService:
 * the body is the slug-keyed Change shape (JSON or XML); X-Allus-Signature =
 * lowercase-hex HMAC-SHA256(body, secret); X-Allus-Webhook-Id selects the secret.
 * For an encrypt_payload webhook the body is REPLACED by a {"_enc":1,...} envelope
 * encrypted to the account public key with OAEP-SHA1 + AES-256-GCM (HMAC over the
 * envelope). The inner field value is a service-key (SHA-256) wrapper from the
 * shared vector.
 */
final class WebhooksTest extends TestCase
{
    private const SECRET = 'wh_secret_abc123';
    private const WEBHOOK_ID = 'wh-1';

    /** @var array<string,mixed> */
    private static array $vector;

    private string $dir;
    private string $servicePem;

    public static function setUpBeforeClass(): void
    {
        self::$vector = Vector::load();
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/allus-wh-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->servicePem = $this->dir . '/service-key.pem';
        file_put_contents($this->servicePem, self::$vector['encrypted_private_key_pem']);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->dir);
    }

    private function config(?string $accountPem = null, ?string $accountPass = null): Config
    {
        return new Config(
            apiUrl: 'https://api.allme.fyi',
            clientId: 'svc',
            clientSecret: 's',
            servicePrivateKey: $this->servicePem,
            keyPassphrase: self::$vector['passphrase'],
            accountPrivateKey: $accountPem,
            accountPassphrase: $accountPass,
            webhooks: [self::WEBHOOK_ID => self::SECRET],
            cacheDir: $this->dir . '/cache',
        );
    }

    private function serviceKey(): RSAPrivateKey
    {
        return Crypto::loadPrivateKey(self::$vector['encrypted_private_key_pem'], self::$vector['passphrase']);
    }

    /** @return callable(array<string,mixed>|string): string */
    private function decryptValue(): callable
    {
        $key = $this->serviceKey();
        return fn (array|string $w): string => Crypto::decrypt($w, $key);
    }

    private function typeForSlug(): callable
    {
        return fn (string $slug): ?string => ['work_email' => 'email', 'logo' => 'photo'][$slug] ?? null;
    }

    private static function hmacSign(string $body, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * @return array<string,string>
     */
    private static function headers(string $body, string $secret = self::SECRET, string $webhookId = self::WEBHOOK_ID, bool $sign = true): array
    {
        $h = ['X-Allus-Webhook-Id' => $webhookId, 'X-Allus-Event' => 'field_updated'];
        if ($sign) {
            $h['X-Allus-Signature'] = self::hmacSign($body, $secret);
        }
        return $h;
    }

    private static function changeBody(): string
    {
        return json_encode([
            'id' => 'chg-1',
            'event' => 'field_updated',
            'person_user_id' => 'person-1',
            'slug' => 'work_email',
            'at' => '2026-06-17T12:00:00Z',
            'live' => true,
            'value' => self::$vector['text']['wrapper'],
        ], JSON_THROW_ON_ERROR);
    }

    // ── verify ─────────────────────────────────────────────────────────────

    public function testVerifyTrueWithKnownSecret(): void
    {
        $body = self::changeBody();
        self::assertTrue(Webhooks::verify($body, self::headers($body), $this->config()));
    }

    public function testVerifyFalseOnTamperedBody(): void
    {
        $body = self::changeBody();
        $headers = self::headers($body);
        self::assertFalse(Webhooks::verify($body . ' ', $headers, $this->config()));
    }

    public function testVerifyFalseOnUnknownWebhookId(): void
    {
        $body = self::changeBody();
        $headers = self::headers($body, webhookId: 'wh-UNKNOWN');
        self::assertFalse(Webhooks::verify($body, $headers, $this->config()));
    }

    public function testVerifyFalseOnMissingSignature(): void
    {
        $body = self::changeBody();
        self::assertFalse(Webhooks::verify($body, self::headers($body, sign: false), $this->config()));
    }

    public function testVerifyAcceptsUppercaseHex(): void
    {
        $body = self::changeBody();
        $headers = ['X-Allus-Webhook-Id' => self::WEBHOOK_ID, 'X-Allus-Signature' => strtoupper(self::hmacSign($body))];
        self::assertTrue(Webhooks::verify($body, $headers, $this->config()));
    }

    public function testVerifySingleWebhookShortcut(): void
    {
        $cfg = new Config(
            apiUrl: 'https://api.allme.fyi', clientId: 'svc', clientSecret: 's',
            servicePrivateKey: $this->servicePem, keyPassphrase: self::$vector['passphrase'],
            webhooks: [Config::SINGLE_WEBHOOK_KEY => self::SECRET],
            cacheDir: $this->dir . '/c',
        );
        $body = self::changeBody();
        // Header carries an id, but config has only the flat secret → falls back.
        self::assertTrue(Webhooks::verify($body, self::headers($body), $cfg));
    }

    // ── parse (plain JSON) ──────────────────────────────────────────────────

    public function testParsePlainJsonBody(): void
    {
        $body = self::changeBody();
        $change = Webhooks::parse($body, self::headers($body), $this->config(), $this->typeForSlug(), $this->decryptValue());
        self::assertSame('chg-1', $change->id);
        self::assertSame('field_updated', $change->event);
        self::assertSame('person-1', $change->personId);
        self::assertSame('work_email', $change->slug);
        self::assertSame(self::$vector['text']['plaintext'], $change->value);
        self::assertTrue($change->live);
    }

    public function testParseXmlBody(): void
    {
        $w = self::$vector['text']['wrapper'];
        $xml = '<response>'
            . '<id>chg-7</id>'
            . '<event>field_updated</event>'
            . '<person_user_id>person-1</person_user_id>'
            . '<slug>work_email</slug>'
            . '<at>2026-06-17T12:00:00Z</at>'
            . '<live>true</live>'
            . '<value>'
            . "<_enc>1</_enc><k>{$w['k']}</k><iv>{$w['iv']}</iv><d>{$w['d']}</d>"
            . '</value>'
            . '</response>';
        $headers = self::headers($xml);

        $change = Webhooks::parse($xml, $headers, $this->config(), $this->typeForSlug(), $this->decryptValue());
        self::assertSame('chg-7', $change->id);
        self::assertSame('field_updated', $change->event);
        self::assertSame('work_email', $change->slug);
        self::assertSame(self::$vector['text']['plaintext'], $change->value);
    }

    // ── parse (account-key encrypt_payload envelope) ─────────────────────────

    public function testParseAccountKeyEnvelope(): void
    {
        [$accountPem, $accountPub] = $this->makeAccountKey('acctpp');
        $cfg = $this->config($accountPem, 'acctpp');

        $inner = self::changeBody();
        $body = self::wrapToAccountKey($accountPub, $inner); // the envelope IS the sent body
        $headers = self::headers($body); // HMAC over the envelope (the final body)

        self::assertTrue(Webhooks::verify($body, $headers, $cfg));
        $change = Webhooks::parse($body, $headers, $cfg, $this->typeForSlug(), $this->decryptValue());
        self::assertSame('chg-1', $change->id);
        self::assertSame('field_updated', $change->event);
        self::assertSame('work_email', $change->slug);
        // The OUTER envelope is account-key (SHA-1); the INNER value is service-key
        // (SHA-256) → still decrypts to the vector plaintext.
        self::assertSame(self::$vector['text']['plaintext'], $change->value);
    }

    public function testParseAccountEnvelopeWithoutAccountKeyRaises(): void
    {
        [, $accountPub] = $this->makeAccountKey('x');
        $body = self::wrapToAccountKey($accountPub, self::changeBody());
        $this->expectException(WebhookError::class);
        Webhooks::parse($body, self::headers($body), $this->config(), $this->typeForSlug(), $this->decryptValue());
    }

    // ── handle = verify + parse ───────────────────────────────────────────────

    public function testHandleVerifyThenParse(): void
    {
        $body = self::changeBody();
        $change = Webhooks::handle($body, self::headers($body), $this->config(), $this->typeForSlug(), $this->decryptValue());
        self::assertSame('chg-1', $change->id);
    }

    public function testHandleBadSignatureRaises(): void
    {
        $body = self::changeBody();
        $headers = self::headers($body);
        $headers['X-Allus-Signature'] = 'deadbeef';
        $this->expectException(WebhookError::class);
        Webhooks::handle($body, $headers, $this->config(), $this->typeForSlug(), $this->decryptValue());
    }

    // ── Client method delegation ──────────────────────────────────────────────

    public function testClientMethodsDelegate(): void
    {
        $catalogCalls = 0;
        $router = function (string $url, ?array $q) use (&$catalogCalls): Response {
            if (!str_ends_with($url, '/request-fields')) {
                throw new \AssertionError("unexpected GET {$url}");
            }
            $catalogCalls++;
            return FakeTransport::json(200, ['request_fields' => [
                ['slug' => 'work_email', 'label' => 'Work email', 'type' => 'email',
                 'one_time' => false, 'mandatory_provide' => true, 'mandatory_connected' => false],
            ]]);
        };
        $cfg = $this->config();
        $client = new Client($cfg, http: new HttpClient($cfg, transport: new FakeTransport($router)));
        $body = self::changeBody();
        $headers = self::headers($body);

        // verify makes NO HTTP at all.
        self::assertTrue($client->verifyWebhook($body, $headers));
        self::assertSame(0, $catalogCalls);

        $change = $client->handleWebhook($body, $headers);
        self::assertSame('chg-1', $change->id);
        self::assertSame(self::$vector['text']['plaintext'], $change->value);
        self::assertSame(1, $catalogCalls); // catalog fetched at most once

        $client->handleWebhook($body, $headers);
        self::assertSame(1, $catalogCalls); // cached
    }

    public function testParseWebhookLoadsAccountKeyWhenNotSupplied(): void
    {
        [$accountPem, $accountPub] = $this->makeAccountKey('acctpp');
        $cfg = $this->config($accountPem, 'acctpp');
        $body = self::wrapToAccountKey($accountPub, self::changeBody());
        // No accountKey arg → loaded from config on demand (config-only contract holds).
        $change = Webhooks::parse($body, self::headers($body), $cfg, $this->typeForSlug(), $this->decryptValue());
        self::assertSame('chg-1', $change->id);
        self::assertSame(self::$vector['text']['plaintext'], $change->value);
    }

    // ── alternative webhook auth methods (bearer / basic / header / none) ─────

    /**
     * Minimal Config carrying one alt-auth field (verify never reads the PEM here).
     *
     * @param array{username:string,password:string}|null $basic
     * @param array{name:string,value:string}|null $header
     */
    private static function authCfg(
        ?string $bearer = null,
        ?array $basic = null,
        ?array $header = null,
        bool $none = false,
    ): Config {
        return new Config(
            apiUrl: 'https://api.allme.fyi',
            clientId: 'svc',
            clientSecret: 's',
            servicePrivateKey: 'unused.pem',
            keyPassphrase: 'unused',
            webhookBearerToken: $bearer,
            webhookBasic: $basic,
            webhookHeader: $header,
            webhookAuthNone: $none,
        );
    }

    public function testVerifyBearerTrue(): void
    {
        $cfg = self::authCfg(bearer: 'tok123');
        self::assertTrue(Webhooks::verify('{}', ['Authorization' => 'Bearer tok123'], $cfg));
    }

    public function testVerifyBearerFalseWrongToken(): void
    {
        $cfg = self::authCfg(bearer: 'tok123');
        self::assertFalse(Webhooks::verify('{}', ['Authorization' => 'Bearer nope'], $cfg));
    }

    public function testVerifyBearerFalseMissingHeader(): void
    {
        $cfg = self::authCfg(bearer: 'tok123');
        self::assertFalse(Webhooks::verify('{}', [], $cfg));
    }

    public function testVerifyBasicTrue(): void
    {
        $cfg = self::authCfg(basic: ['username' => 'u', 'password' => 'p']);
        $token = base64_encode('u:p');
        self::assertTrue(Webhooks::verify('{}', ['Authorization' => 'Basic ' . $token], $cfg));
    }

    public function testVerifyBasicFalseWrongPassword(): void
    {
        $cfg = self::authCfg(basic: ['username' => 'u', 'password' => 'p']);
        $bad = base64_encode('u:wrong');
        self::assertFalse(Webhooks::verify('{}', ['Authorization' => 'Basic ' . $bad], $cfg));
    }

    public function testVerifyHeaderTrueCaseInsensitiveName(): void
    {
        $cfg = self::authCfg(header: ['name' => 'X-My-Auth', 'value' => 'sekret']);
        self::assertTrue(Webhooks::verify('{}', ['x-my-auth' => 'sekret'], $cfg));
    }

    public function testVerifyHeaderFalseWrongValue(): void
    {
        $cfg = self::authCfg(header: ['name' => 'X-My-Auth', 'value' => 'sekret']);
        self::assertFalse(Webhooks::verify('{}', ['X-My-Auth' => 'nope'], $cfg));
    }

    public function testVerifyNoneAlwaysTrue(): void
    {
        $cfg = self::authCfg(none: true);
        self::assertTrue(Webhooks::verify('anything at all', [], $cfg));
    }

    public function testVerifyNoMethodConfiguredFalse(): void
    {
        $cfg = self::authCfg();
        self::assertFalse(Webhooks::verify('{}', ['Authorization' => 'Bearer x'], $cfg));
    }

    // ── helpers (account key) ─────────────────────────────────────────────────

    /**
     * Generate an account RSA keypair; write the encrypted private PEM + return
     * the public key (OAEP-SHA1-configured, the platform's account-key padding).
     *
     * @return array{0: string, 1: RSAPublicKey}
     */
    private function makeAccountKey(string $passphrase): array
    {
        $key = RSA::createKey(2048);
        \assert($key instanceof RSAPrivateKey);
        // Encrypt the PEM with the passphrase (phpseclib emits a PBES2 PKCS#8 PEM).
        $pem = $key->withPassword($passphrase)->toString('PKCS8');
        $path = $this->dir . '/account.pem';
        file_put_contents($path, $pem);
        $pub = $key->getPublicKey();
        \assert($pub instanceof RSAPublicKey);
        return [$path, $pub->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha1')->withMGFHash('sha1')];
    }

    /**
     * Mimic the account-key envelope — OAEP-SHA1 + AES-256-GCM.
     */
    private static function wrapToAccountKey(RSAPublicKey $publicKey, string $plaintext): string
    {
        $aesKey = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        \assert($ct !== false);
        $k = $publicKey->encrypt($aesKey); // OAEP-SHA1 (configured on the key)
        return json_encode([
            '_enc' => 1,
            'k' => base64_encode($k),
            'iv' => base64_encode($iv),
            'd' => base64_encode($ct . $tag),
        ], JSON_THROW_ON_ERROR);
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? self::rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
