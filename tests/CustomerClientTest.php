<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Config;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\CustomerClient;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Tests\Support\FakeTransport;
use Allus\CompanyData\Tests\Support\Vector;
use PHPUnit\Framework\TestCase;

/**
 * CustomerClient (b2b) — parse + method-shape + key-sourcing tests.
 * Reuses the shared decryption vector's key as the customer ACCOUNT key.
 */
final class CustomerClientTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $vector;
    private string $dir;
    private string $pemPath;

    public static function setUpBeforeClass(): void
    {
        self::$vector = Vector::load();
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/allus-customer-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);
        $this->pemPath = $this->dir . '/account-key.pem';
        file_put_contents($this->pemPath, self::$vector['encrypted_private_key_pem']);
    }

    protected function tearDown(): void
    {
        @unlink($this->pemPath);
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    private function config(): Config
    {
        return new Config(
            apiUrl: 'https://api.allme.fyi',
            customerClientId: 'acct_abc',
            customerClientSecret: 'topsecret',
            accountPrivateKey: $this->pemPath,
            accountPassphrase: self::$vector['passphrase'],
            cacheDir: $this->dir . '/cache',
        );
    }

    /**
     * @return array{0: CustomerClient, 1: FakeTransport}
     */
    private function customer(?callable $getRouter, ?callable $writeRouter = null): array
    {
        $t = new FakeTransport($getRouter, $writeRouter);
        $http = new HttpClient($this->config(), transport: $t);
        return [new CustomerClient($this->config(), http: $http), $t];
    }

    public function testConfigRequiresAcctPair(): void
    {
        $p = $this->dir . '/c.json';
        file_put_contents($p, json_encode(['api_url' => 'https://api.allme.fyi']));
        $this->expectException(ConfigError::class);
        Config::fromCustomerFile($p);
    }

    public function testConnectionsParse(): void
    {
        $body = [
            'connections' => [[
                'id' => 'conn-1',
                'customer_type' => 'company',
                'company' => ['user_id' => 'co-1', 'display_name' => 'Acme BV', 'share_code' => 'ACME01'],
                'company_profile' => [['slug' => 'company_email', 'value' => 'hi@acme.example']],
                'services' => [['service_link_id' => 'sl-1', 'service_name' => 'CRM', 'shared' => [['slug' => 'x', 'value' => 'y']]]],
            ]],
        ];
        [$customer] = $this->customer(fn (string $url, ?array $q) => FakeTransport::json(200, $body));
        $conns = $customer->connections();
        $this->assertCount(1, $conns);
        $this->assertSame('company', $conns[0]->customerType);
        $this->assertSame('Acme BV', $conns[0]->companyName);
        $this->assertSame('ACME01', $conns[0]->companyCode);
        $this->assertSame('CRM', $conns[0]->services[0]->serviceName);
    }

    public function testProvideConsentEncryptsToServiceKey(): void
    {
        $spki = Vector::publicSpkiB64();
        [$customer, $t] = $this->customer(
            function (string $url, ?array $q) use ($spki): \Allus\CompanyData\Http\Response {
                if (str_contains($url, '/api/keys/ACME01/CRM')) {
                    return FakeTransport::json(200, ['public_key' => $spki]);
                }
                return FakeTransport::json(200, []);
            },
            fn (string $m, string $url, ?string $body, array $h): \Allus\CompanyData\Http\Response => FakeTransport::json(200, ['ok' => true]),
        );
        $customer->provideConsent('consent-1', [['request_field_id' => 'rf-1', 'value' => 'billing@me.example']], 'ACME01', 'CRM');
        $write = $t->sends[count($t->sends) - 1];
        $this->assertStringEndsWith('/consents/consent-1/provide', $write['url']);
        $sent = json_decode($write['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('typed', $sent['decisions'][0]['kind']);
        $plain = Crypto::decrypt($sent['decisions'][0]['value'], Vector::privateKey());
        $this->assertSame('billing@me.example', $plain);
    }

    public function testDocumentFileDecryptsWithAccountKey(): void
    {
        $wrapper = Vector::encryptForKey(json_encode(['file' => 'data:application/pdf;base64,AAA', 'name' => 'contract.pdf'], JSON_THROW_ON_ERROR));
        [$customer] = $this->customer(fn (string $url, ?array $q) => FakeTransport::json(200, ['encrypted' => true, 'value' => $wrapper]));
        $out = $customer->documentFile('conn-1', 'doc-1');
        $this->assertSame('contract.pdf', $out['name']);
    }

    public function testDrainBatchUsesCustomerChanges(): void
    {
        $hit = false;
        [$customer] = $this->customer(function (string $url, ?array $q) use (&$hit): \Allus\CompanyData\Http\Response {
            if (str_contains($url, '/api/customer/changes')) {
                $hit = true;
                return FakeTransport::json(200, ['changes' => [['id' => 'ch-1', 'event' => 'share_changed', 'customer_type' => 'company']]]);
            }
            return FakeTransport::json(200, []);
        });
        $changes = $customer->drainBatch(10);
        $this->assertTrue($hit);
        $this->assertSame('ch-1', $changes[0]->id);
        $this->assertSame('company', $changes[0]->customerType);
    }

    public function testNoSignOrAcceptMethods(): void
    {
        foreach (['sign', 'accept', 'signDocument', 'acceptDocument', 'signEmailCode'] as $banned) {
            $this->assertFalse(method_exists(CustomerClient::class, $banned), "CustomerClient must not expose {$banned} (D6)");
        }
    }
}
