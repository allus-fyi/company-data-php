<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\ConfigError;
use PHPUnit\Framework\TestCase;

/**
 * Config loader tests.
 */
final class ConfigTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $envToClear = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/allus-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        foreach ($this->envToClear as $name) {
            putenv($name);
        }
    }

    /** @param array<string,mixed> $data */
    private function write(array $data): string
    {
        $p = $this->dir . '/config.json';
        file_put_contents($p, json_encode($data, JSON_THROW_ON_ERROR));
        return $p;
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $this->envToClear[] = $name;
    }

    /** @return array<string,mixed> */
    private function full(): array
    {
        return [
            'api_url' => 'https://api.allme.fyi',
            'client_id' => 'svc_abc',
            'client_secret' => 'file-secret',
            'service_private_key' => './service-CRM.pem',
            'key_passphrase' => 'file-passphrase',
            'account_private_key' => './account.pem',
            'account_passphrase' => 'acct-pass',
            'webhooks' => ['wh_1' => 'secret-one', 'wh_2' => 'secret-two'],
            'cache_dir' => './allus-cache',
            'format' => 'json',
        ];
    }

    public function testFromFileLoadsAllFields(): void
    {
        $cfg = Config::fromFile($this->write($this->full()));
        self::assertSame('https://api.allme.fyi', $cfg->apiUrl);
        self::assertSame('svc_abc', $cfg->clientId);
        self::assertSame('file-secret', $cfg->clientSecret);
        self::assertSame('./service-CRM.pem', $cfg->servicePrivateKey);
        self::assertSame('file-passphrase', $cfg->keyPassphrase);
        self::assertSame('./account.pem', $cfg->accountPrivateKey);
        self::assertSame('acct-pass', $cfg->accountPassphrase);
        self::assertSame('./allus-cache', $cfg->cacheDir);
        self::assertSame('json', $cfg->format);
        self::assertSame('secret-one', $cfg->webhookSecret('wh_1'));
        self::assertSame('secret-two', $cfg->webhookSecret('wh_2'));
    }

    public function testOptionalFieldsDefault(): void
    {
        $cfg = Config::fromFile($this->write([
            'api_url' => 'https://api.allme.fyi',
            'client_id' => 'svc_abc',
            'client_secret' => 's',
            'service_private_key' => './k.pem',
            'key_passphrase' => 'p',
        ]));
        self::assertNull($cfg->accountPrivateKey);
        self::assertNull($cfg->accountPassphrase);
        self::assertSame([], $cfg->webhooks);
        self::assertSame('./allus-cache', $cfg->cacheDir);
        self::assertSame('json', $cfg->format);
    }

    public function testEnvOverridesFileValues(): void
    {
        $path = $this->write($this->full());
        $this->setEnv('ALLUS_CLIENT_SECRET', 'env-secret');
        $this->setEnv('ALLUS_KEY_PASSPHRASE', 'env-passphrase');
        $this->setEnv('ALLUS_API_URL', 'https://api-eu.allme.fyi');
        $cfg = Config::fromFile($path);
        self::assertSame('env-secret', $cfg->clientSecret);
        self::assertSame('env-passphrase', $cfg->keyPassphrase);
        self::assertSame('https://api-eu.allme.fyi', $cfg->apiUrl);
        self::assertSame('svc_abc', $cfg->clientId); // from file (no env)
    }

    public function testFromEnvBuildsWithoutAFile(): void
    {
        $this->setEnv('ALLUS_API_URL', 'https://api.allme.fyi');
        $this->setEnv('ALLUS_CLIENT_ID', 'svc_env');
        $this->setEnv('ALLUS_CLIENT_SECRET', 'env-secret');
        $this->setEnv('ALLUS_SERVICE_PRIVATE_KEY', './k.pem');
        $this->setEnv('ALLUS_KEY_PASSPHRASE', 'env-pass');
        $cfg = Config::fromEnv();
        self::assertSame('svc_env', $cfg->clientId);
        self::assertSame('env-secret', $cfg->clientSecret);
    }

    public function testMissingRequiredFieldRaisesConfigError(): void
    {
        $data = $this->full();
        unset($data['client_secret']);
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessageMatches('/client_secret/');
        Config::fromFile($this->write($data));
    }

    public function testMissingFileRaisesConfigError(): void
    {
        $this->expectException(ConfigError::class);
        Config::fromFile($this->dir . '/does-not-exist.json');
    }

    public function testInvalidJsonRaisesConfigError(): void
    {
        $p = $this->dir . '/bad.json';
        file_put_contents($p, '{ not valid json');
        $this->expectException(ConfigError::class);
        Config::fromFile($p);
    }

    public function testInvalidFormatRaisesConfigError(): void
    {
        $data = $this->full();
        $data['format'] = 'yaml';
        $this->expectException(ConfigError::class);
        Config::fromFile($this->write($data));
    }

    public function testFlatWebhookSecretShortcut(): void
    {
        $cfg = Config::fromFile($this->write([
            'api_url' => 'https://api.allme.fyi',
            'client_id' => 'svc_abc',
            'client_secret' => 's',
            'service_private_key' => './k.pem',
            'key_passphrase' => 'p',
            'webhook_secret' => 'the-only-secret',
        ]));
        // No id, or an unknown id, falls back to the single-webhook secret.
        self::assertSame('the-only-secret', $cfg->webhookSecret());
        self::assertSame('the-only-secret', $cfg->webhookSecret('anything'));
    }

    public function testNoKeyOrSecretIsEverAMethodArgument(): void
    {
        // Config-only key handling: the only method, webhookSecret(),
        // takes a webhook *id* — never a secret.
        $refl = new \ReflectionMethod(Config::class, 'webhookSecret');
        $params = array_map(static fn (\ReflectionParameter $p) => $p->getName(), $refl->getParameters());
        self::assertSame(['webhookId'], $params);
    }

    // ── alternative webhook auth methods (file-config) ────────────────────────

    /**
     * Minimal valid config base (no webhook auth) to layer alt-auth fields on.
     *
     * @return array<string,mixed>
     */
    private function minimal(): array
    {
        return [
            'api_url' => 'https://api.allme.fyi',
            'client_id' => 'svc',
            'client_secret' => 's',
            'service_private_key' => './k.pem',
            'key_passphrase' => 'p',
        ];
    }

    public function testConfigRejectsTwoAuthMethods(): void
    {
        $data = $this->minimal() + ['webhook_secret' => 'h', 'webhook_bearer_token' => 'b'];
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('configure at most one webhook auth method (found: hmac, bearer)');
        Config::fromFile($this->write($data));
    }

    public function testConfigRejectsBearerPlusNone(): void
    {
        $data = $this->minimal() + ['webhook_bearer_token' => 'b', 'webhook_auth_none' => true];
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('configure at most one webhook auth method (found: bearer, none)');
        Config::fromFile($this->write($data));
    }

    public function testConfigBasicRequiresBothFields(): void
    {
        $data = $this->minimal() + ['webhook_basic' => ['username' => 'u']];
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('"webhook_basic" must be an object with non-empty "username" and "password"');
        Config::fromFile($this->write($data));
    }

    public function testConfigHeaderRequiresBothFields(): void
    {
        $data = $this->minimal() + ['webhook_header' => ['name' => 'X-H']];
        $this->expectException(ConfigError::class);
        $this->expectExceptionMessage('"webhook_header" must be an object with non-empty "name" and "value"');
        Config::fromFile($this->write($data));
    }

    public function testConfigSingleMethodOkAndMethodName(): void
    {
        $cfg = Config::fromFile($this->write($this->minimal() + ['webhook_bearer_token' => 'b']));
        self::assertSame('bearer', $cfg->webhookAuthMethod());
        self::assertSame('b', $cfg->webhookBearerToken);

        $cfg2 = Config::fromFile($this->write($this->minimal() + ['webhook_secret' => 'h']));
        self::assertSame('hmac', $cfg2->webhookAuthMethod());

        $cfg3 = Config::fromFile($this->write($this->minimal() + ['webhook_auth_none' => true]));
        self::assertSame('none', $cfg3->webhookAuthMethod());

        $cfg4 = Config::fromFile($this->write($this->minimal() + ['webhook_basic' => ['username' => 'u', 'password' => 'p']]));
        self::assertSame('basic', $cfg4->webhookAuthMethod());

        $cfg5 = Config::fromFile($this->write($this->minimal() + ['webhook_header' => ['name' => 'X-H', 'value' => 'v']]));
        self::assertSame('header', $cfg5->webhookAuthMethod());

        // No webhook auth configured → null.
        $cfg6 = Config::fromFile($this->write($this->minimal()));
        self::assertNull($cfg6->webhookAuthMethod());
    }
}
