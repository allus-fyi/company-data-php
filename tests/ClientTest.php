<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Client;
use Allus\CompanyData\Config;
use Allus\CompanyData\Crypto\BinaryHandle;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Http\Response;
use Allus\CompanyData\Model\Connection;
use Allus\CompanyData\Model\LogEntry;
use Allus\CompanyData\Model\RequestField;
use Allus\CompanyData\Tests\Support\FakeTransport;
use Allus\CompanyData\Tests\Support\Vector;
use PHPUnit\Framework\TestCase;

/**
 * Client-facade tests — everything mocked, no live API.
 *
 * A router-based {@see FakeTransport} replays canned hardened API JSON; ciphertext
 * fields reuse the shared vector's real wrapper + key (written to a temp PEM the
 * Client loads at construction), exercising the whole facade → http → crypto →
 * model wiring end-to-end without the network.
 */
final class ClientTest extends TestCase
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
        $this->dir = sys_get_temp_dir() . '/allus-client-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->pemPath = $this->dir . '/service-key.pem';
        file_put_contents($this->pemPath, self::$vector['encrypted_private_key_pem']);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->dir);
    }

    private function config(): Config
    {
        return new Config(
            apiUrl: 'https://api.allme.fyi',
            clientId: 'svc_abc',
            clientSecret: 'topsecret',
            servicePrivateKey: $this->pemPath,
            keyPassphrase: self::$vector['passphrase'],
            cacheDir: $this->dir . '/cache',
        );
    }

    /**
     * @param callable(string, ?array<string,scalar>): Response $router
     * @return array{0: Client, 1: FakeTransport}
     */
    private function client(callable $router): array
    {
        $t = new FakeTransport($router);
        $http = new HttpClient($this->config(), transport: $t);
        return [new Client($this->config(), http: $http, sleep: fn (float $_s): null => null), $t];
    }

    /** @return array<string,mixed> */
    private static function requestFieldsBody(): array
    {
        return ['request_fields' => [
            ['slug' => 'work_email', 'label' => 'Work email', 'type' => 'email',
             'one_time' => false, 'mandatory_provide' => true, 'mandatory_connected' => false],
            ['slug' => 'billing_address', 'label' => 'Billing address', 'type' => 'address',
             'one_time' => false, 'mandatory_provide' => false, 'mandatory_connected' => false],
            ['slug' => 'logo', 'label' => 'Logo', 'type' => 'photo',
             'one_time' => true, 'mandatory_provide' => false, 'mandatory_connected' => false],
        ]];
    }

    // ── request_fields() caches ──────────────────────────────────────────────

    public function testRequestFieldsParsedAndCached(): void
    {
        $calls = 0;
        [$client] = $this->client(function (string $url, ?array $q) use (&$calls): Response {
            if (str_ends_with($url, '/request-fields')) {
                $calls++;
                return FakeTransport::json(200, self::requestFieldsBody());
            }
            throw new \AssertionError("unexpected GET {$url}");
        });
        $fields = $client->requestFields();
        self::assertSame(['work_email', 'billing_address', 'logo'], array_map(fn ($f) => $f->slug, $fields));
        self::assertContainsOnlyInstancesOf(RequestField::class, $fields);
        self::assertTrue($fields[0]->mandatory);

        $client->requestFields(); // cached — does not re-fetch
        self::assertSame(1, $calls);
    }

    // ── connections() lazy generator with decrypted values ──────────────────

    public function testConnectionsYieldsTypedDecrypted(): void
    {
        $addrWrapper = Vector::encryptForKey(json_encode(['city' => 'Utrecht', 'country' => 'NL'], JSON_THROW_ON_ERROR));
        $page1 = ['total' => 2, 'items' => [[
            'connection_id' => 'csc-1',
            'user_id' => 'person-1',
            'display_name' => 'Anna',
            'connected_at' => '2026-06-10T00:00:00Z',
            'values' => [
                'work_email' => ['value' => self::$vector['text']['wrapper'], 'live' => true, 'updatedAt' => '2026-06-17T10:00:00Z'],
                'billing_address' => ['value' => $addrWrapper, 'live' => false],
                'logo' => ['value_url' => 'https://api.allme.fyi/api/company-data/connections/csc-1/slots/sf-9/file', 'live' => true],
            ],
            'pending_consent' => [],
        ]]];

        [$client, $t] = $this->client(function (string $url, ?array $q) use ($page1): Response {
            if (str_ends_with($url, '/request-fields')) {
                return FakeTransport::json(200, self::requestFieldsBody());
            }
            if (str_ends_with($url, '/connections')) {
                return FakeTransport::json(200, $page1);
            }
            throw new \AssertionError("unexpected GET {$url}");
        });

        $conns = iterator_to_array($client->connections(limit: 100));
        self::assertCount(1, $conns);
        $conn = $conns[0];
        self::assertInstanceOf(Connection::class, $conn);
        self::assertSame('csc-1', $conn->id);
        self::assertSame('person-1', $conn->personId);
        self::assertSame('Anna', $conn->displayName);

        self::assertSame(self::$vector['text']['plaintext'], $conn->values['work_email']->value);
        self::assertTrue($conn->values['work_email']->live);
        self::assertSame(['city' => 'Utrecht', 'country' => 'NL'], $conn->values['billing_address']->value);
        self::assertInstanceOf(BinaryHandle::class, $conn->values['logo']->value);

        $connGets = array_filter($t->gets, fn ($g) => str_ends_with($g['url'], '/connections'));
        self::assertCount(1, $connGets);
        self::assertCount(0, array_filter($t->gets, fn ($g) => str_contains($g['url'], '/file')));
    }

    public function testConnectionsAutoPages(): void
    {
        $makeItem = fn (int $i) => ['connection_id' => "c{$i}", 'user_id' => "p{$i}", 'display_name' => "N{$i}", 'values' => []];
        $pages = [
            ['total' => 3, 'items' => [$makeItem(1), $makeItem(2)]], // full page (==limit 2)
            ['total' => 3, 'items' => [$makeItem(3)]],                 // short page → stop
        ];
        $i = 0;
        [$client, $t] = $this->client(function (string $url, ?array $q) use ($pages, &$i): Response {
            if (str_ends_with($url, '/request-fields')) {
                return FakeTransport::json(200, ['request_fields' => []]);
            }
            if (str_ends_with($url, '/connections')) {
                return FakeTransport::json(200, $pages[$i++]);
            }
            throw new \AssertionError("unexpected GET {$url}");
        });

        $ids = array_map(fn ($c) => $c->id, iterator_to_array($client->connections(limit: 2)));
        self::assertSame(['c1', 'c2', 'c3'], $ids);
        $connGets = array_values(array_filter($t->gets, fn ($g) => str_ends_with($g['url'], '/connections')));
        self::assertSame([0, 2], array_map(fn ($g) => $g['query']['offset'], $connGets));
    }

    // ── binary handle fetches the slot endpoint + decrypts ─────────────────────

    public function testBinaryHandleFetchesSlotAndDecrypts(): void
    {
        $page = ['total' => 1, 'items' => [[
            'connection_id' => 'csc-1', 'user_id' => 'person-1', 'display_name' => 'Anna',
            'values' => ['logo' => ['value_url' => 'https://api.allme.fyi/api/company-data/connections/csc-1/slots/sf-9/file', 'live' => true]],
        ]]];
        [$client, $t] = $this->client(function (string $url, ?array $q) use ($page): Response {
            if (str_ends_with($url, '/request-fields')) {
                return FakeTransport::json(200, self::requestFieldsBody());
            }
            if (str_ends_with($url, '/connections')) {
                return FakeTransport::json(200, $page);
            }
            if (str_ends_with($url, '/slots/sf-9/file')) {
                // The slot endpoint serves {"encrypted":true,"value":<wrapper>}.
                return FakeTransport::json(200, ['encrypted' => true, 'value' => self::$vector['binary']['wrapper']]);
            }
            throw new \AssertionError("unexpected GET {$url}");
        });

        $conns = iterator_to_array($client->connections());
        $handle = $conns[0]->values['logo']->value;
        self::assertInstanceOf(BinaryHandle::class, $handle);
        self::assertCount(0, array_filter($t->gets, fn ($g) => str_contains($g['url'], '/file'))); // lazy

        $data = $handle->bytes();
        self::assertNotEmpty(array_filter($t->gets, fn ($g) => str_ends_with($g['url'], '/slots/sf-9/file')));
        self::assertSame(self::$vector['binary']['inner_full_sha256'], hash('sha256', $data));
    }

    // ── connection(id) ─────────────────────────────────────────────────────────

    public function testConnectionById(): void
    {
        $detail = [
            'connection_id' => 'csc-7', 'user_id' => 'person-7',
            'values' => ['work_email' => ['value' => self::$vector['text']['wrapper'], 'live' => true]],
        ];
        [$client] = $this->client(function (string $url, ?array $q) use ($detail): Response {
            if (str_ends_with($url, '/request-fields')) {
                return FakeTransport::json(200, self::requestFieldsBody());
            }
            if (str_ends_with($url, '/connections/csc-7')) {
                return FakeTransport::json(200, $detail);
            }
            throw new \AssertionError("unexpected GET {$url}");
        });
        $conn = $client->connection('csc-7');
        self::assertSame('csc-7', $conn->id);
        self::assertSame('person-7', $conn->personId);
        self::assertSame(self::$vector['text']['plaintext'], $conn->values['work_email']->value);
    }

    // ── logs() ──────────────────────────────────────────────────────────────

    public function testLogsDeserialize(): void
    {
        $body = ['total' => 2, 'items' => [
            ['type' => 'email', 'message' => 'stale-queue alert', 'metadata' => ['days' => 3], 'created_at' => '2026-06-17T06:00:00Z'],
            ['type' => 'purge', 'message' => 'purged 4', 'metadata' => ['count' => 4], 'created_at' => '2026-06-17T07:00:00Z'],
        ]];
        [$client, $t] = $this->client(function (string $url, ?array $q) use ($body): Response {
            if (str_ends_with($url, '/logs')) {
                return FakeTransport::json(200, $body);
            }
            throw new \AssertionError("unexpected GET {$url}");
        });
        $logs = $client->logs(limit: 50);
        self::assertCount(2, $logs);
        self::assertContainsOnlyInstancesOf(LogEntry::class, $logs);
        self::assertSame('email', $logs[0]->type);
        self::assertSame(['days' => 3], $logs[0]->metadata);
        self::assertSame(50, $t->gets[0]['query']['limit']);
    }

    // ── process_changes() drains the feed through the pump one-by-one ──────────

    public function testProcessChangesDrainsThroughPump(): void
    {
        $served = false;
        [$client] = $this->client(function (string $url, ?array $q) use (&$served): Response {
            if (str_ends_with($url, '/request-fields')) {
                return FakeTransport::json(200, self::requestFieldsBody());
            }
            if (str_ends_with($url, '/changes')) {
                if ($served) {
                    return FakeTransport::json(200, ['changes' => []]);
                }
                $served = true;
                return FakeTransport::json(200, ['changes' => [
                    ['id' => 'chg-1', 'event' => 'field_updated', 'person_user_id' => 'person-1',
                     'slug' => 'work_email', 'value' => self::$vector['text']['wrapper'], 'live' => true,
                     'at' => '2026-06-17T12:00:00Z'],
                    ['id' => 'chg-2', 'event' => 'connection_created', 'person_user_id' => 'person-2',
                     'at' => '2026-06-17T12:05:00Z'],
                ]]);
            }
            throw new \AssertionError("unexpected GET {$url}");
        });

        $seen = [];
        $client->processChanges(function ($c) use (&$seen): void {
            $seen[] = [$c->id, $c->event, $c->value];
        });

        self::assertSame(['chg-1', 'chg-2'], array_map(fn ($s) => $s[0], $seen));
        self::assertSame('field_updated', $seen[0][1]);
        self::assertSame(self::$vector['text']['plaintext'], $seen[0][2]);
        self::assertSame('connection_created', $seen[1][1]);
        self::assertNull($seen[1][2]);
        self::assertSame([], $client->pump()->buffer()->pending());
    }

    // ── construction reads the key once (config-only keys) ────────

    public function testFromConfigLoadsKey(): void
    {
        $cfgPath = $this->dir . '/config.json';
        file_put_contents($cfgPath, json_encode([
            'api_url' => 'https://api.allme.fyi',
            'client_id' => 'svc_abc',
            'client_secret' => 's',
            'service_private_key' => $this->pemPath,
            'key_passphrase' => self::$vector['passphrase'],
            'cache_dir' => $this->dir . '/cache',
        ], JSON_THROW_ON_ERROR));

        $client = Client::fromConfig($cfgPath);
        // decryptValue is an internal helper (not part of the public surface);
        // reflect into it to prove fromConfig loaded a working service key.
        $decrypt = new \ReflectionMethod($client, 'decryptValue');
        self::assertSame(
            self::$vector['text']['plaintext'],
            $decrypt->invoke($client, self::$vector['text']['wrapper'])
        );
    }

    public function testFromConfigBadPassphraseIsConfigError(): void
    {
        $cfgPath = $this->dir . '/config.json';
        file_put_contents($cfgPath, json_encode([
            'api_url' => 'https://api.allme.fyi', 'client_id' => 'x', 'client_secret' => 's',
            'service_private_key' => $this->pemPath, 'key_passphrase' => 'WRONG',
            'cache_dir' => $this->dir . '/cache',
        ], JSON_THROW_ON_ERROR));
        $this->expectException(ConfigError::class);
        Client::fromConfig($cfgPath);
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
