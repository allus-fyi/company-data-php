<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Client;
use Allus\CompanyData\Config;
use Allus\CompanyData\Crypto\BinaryHandle;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Http\Response;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Model\Connection;
use Allus\CompanyData\Model\Change;
use Allus\CompanyData\Model\Document;
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

    /**
     * @param (callable(string, ?array<string,scalar>): Response)|null $getRouter
     * @param callable(string, string, ?string, array<string,string>): Response $writeRouter
     * @return array{0: Client, 1: FakeTransport}
     */
    private function clientRw(?callable $getRouter, callable $writeRouter): array
    {
        $t = new FakeTransport($getRouter, $writeRouter);
        $http = new HttpClient($this->config(), transport: $t);
        return [new Client($this->config(), http: $http, sleep: fn (float $_s): null => null), $t];
    }

    /** A GET router that fails the test if any GET happens (broadcast: no key fetch). */
    private function noGet(): callable
    {
        return function (string $url, ?array $q): Response {
            throw new \AssertionError("unexpected GET {$url}");
        };
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

    // ── company documents (write) ──────────────────────────────────────────────

    public function testCreateDocumentBroadcastJsonIsPlaintext(): void
    {
        $posted = [];
        $writeRouter = function (string $method, string $url, ?string $body) use (&$posted): Response {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/documents', $url);
            $posted['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(201, [
                'id' => 'd1', 'kind' => 'document', 'name' => 'Terms', 'description' => null,
                'status' => 'active', 'payload_kind' => 'json', 'is_private' => false,
                'value' => $posted['body']['value'], 'metadata' => null,
                'created_at' => null, 'updated_at' => null,
            ]);
        };
        [$client] = $this->clientRw($this->noGet(), $writeRouter);
        $doc = $client->createDocument([
            'name' => 'Terms', 'payload_kind' => 'json',
            'json_value' => ['url' => 'x', 'v' => '1'], 'status' => 'active',
        ]);
        self::assertNull($posted['body']['target']); // broadcast, no target
        self::assertSame(['url' => 'x', 'v' => '1'], $posted['body']['value']); // plaintext, no _enc
        self::assertFalse($posted['body']['is_private']);
        self::assertSame('d1', $doc->id);
        self::assertSame('active', $doc->status);
    }

    public function testCreateDocumentPerPersonEncryptsForBothPrivacy(): void
    {
        $spki = Vector::publicSpkiB64();

        foreach ([false, true] as $isPrivate) {
            $keysFetched = 0;
            $getRouter = function (string $url, ?array $q) use ($spki, &$keysFetched): Response {
                self::assertStringEndsWith('/api/keys/ABC123', $url);
                $keysFetched++;
                return FakeTransport::json(200, ['public_key' => $spki]);
            };
            $captured = [];
            $writeRouter = function (string $method, string $url, ?string $body) use (&$captured, $isPrivate): Response {
                $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
                return FakeTransport::json(201, [
                    'id' => 'd2', 'kind' => 'document', 'name' => 'PP', 'description' => null,
                    'status' => 'active', 'payload_kind' => 'json', 'is_private' => $isPrivate,
                    'value' => $captured['body']['value'], 'metadata' => null,
                    'created_at' => null, 'updated_at' => null,
                ]);
            };
            [$client] = $this->clientRw($getRouter, $writeRouter);
            $doc = $client->createDocument([
                'name' => 'PP', 'payload_kind' => 'json', 'json_value' => ['plan' => 'pro'],
                'connection_id' => 'conn-1', 'share_code' => 'ABC123', 'is_private' => $isPrivate,
            ]);
            self::assertSame(1, $keysFetched); // fetched the recipient key
            $val = $captured['body']['value'];
            self::assertIsArray($val);
            self::assertSame(1, $val['_enc']); // ENCRYPTED, any is_private
            self::assertArrayHasKey('k', $val);
            self::assertArrayHasKey('iv', $val);
            self::assertArrayHasKey('d', $val);
            self::assertSame(['connection_id' => 'conn-1'], $captured['body']['target']);
            self::assertSame($isPrivate, $captured['body']['is_private']);
            // round-trips through the SDK's own decrypt → the original plaintext
            $plain = Crypto::decrypt($val, Vector::privateKey());
            self::assertSame(['plan' => 'pro'], json_decode($plain, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame('d2', $doc->id);
        }
    }

    public function testCreateDocumentShareCodeOnlyIsPerPersonEncrypted(): void
    {
        // A share_code-only target (no connection_id / person_user_id) must be
        // PER-PERSON (encrypted to that recipient), NOT a plaintext broadcast (issue #29).
        $spki = Vector::publicSpkiB64();
        $keysFetched = 0;
        $getRouter = function (string $url, ?array $q) use ($spki, &$keysFetched): Response {
            self::assertStringEndsWith('/api/keys/ABC123', $url); // recipient key fetched by share_code
            $keysFetched++;
            return FakeTransport::json(200, ['public_key' => $spki]);
        };
        $captured = [];
        $writeRouter = function (string $method, string $url, ?string $body) use (&$captured): Response {
            $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(201, [
                'id' => 'd3', 'kind' => 'document', 'name' => 'SC', 'description' => null,
                'status' => 'active', 'payload_kind' => 'json', 'is_private' => false,
                'value' => $captured['body']['value'], 'metadata' => null,
                'created_at' => null, 'updated_at' => null,
            ]);
        };
        [$client] = $this->clientRw($getRouter, $writeRouter);
        $doc = $client->createDocument([
            'name' => 'SC', 'payload_kind' => 'json', 'json_value' => ['plan' => 'pro'],
            'share_code' => 'ABC123',
        ]);
        self::assertSame(1, $keysFetched);                                    // (a) recipient key fetched
        self::assertSame(['share_code' => 'ABC123'], $captured['body']['target']); // (b) per-person target, not null/broadcast
        $val = $captured['body']['value'];
        self::assertIsArray($val);
        self::assertSame(1, $val['_enc']);                                    // (c) encrypted wrapper, not plaintext
        self::assertArrayHasKey('k', $val);
        self::assertArrayHasKey('iv', $val);
        self::assertArrayHasKey('d', $val);
        $plain = Crypto::decrypt($val, Vector::privateKey());
        self::assertSame(['plan' => 'pro'], json_decode($plain, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('d3', $doc->id);
    }

    public function testCreateDocumentPrivateBroadcastRaises(): void
    {
        [$client] = $this->clientRw($this->noGet(), fn (string $m, string $u, ?string $b): Response => FakeTransport::json(200, []));
        $this->expectException(ConfigError::class);
        $client->createDocument([
            'name' => 'x', 'payload_kind' => 'json', 'json_value' => ['a' => 1], 'is_private' => true,
        ]);
    }

    public function testCreateDocumentContractWithoutTargetRaises(): void
    {
        [$client] = $this->clientRw($this->noGet(), fn (string $m, string $u, ?string $b): Response => FakeTransport::json(200, []));
        $this->expectException(ConfigError::class);
        $client->createDocument([
            'name' => 'Agreement', 'payload_kind' => 'json', 'kind' => 'agreement',
            'requires_signature' => true, 'json_value' => ['a' => 1],
        ]);
    }

    public function testCreateDocumentInvalidKindRaises(): void
    {
        [$client] = $this->clientRw($this->noGet(), fn (string $m, string $u, ?string $b): Response => FakeTransport::json(200, []));
        $this->expectException(ConfigError::class);
        $client->createDocument([
            'name' => 'x', 'payload_kind' => 'json', 'kind' => 'invalid', 'json_value' => ['a' => 1],
        ]);
    }

    public function testCreateDocumentFileBroadcastUploadsFileDataUri(): void
    {
        $calls = [];
        $writeRouter = function (string $method, string $url, ?string $body) use (&$calls): Response {
            $calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
            if (str_ends_with($url, '/documents')) {
                return FakeTransport::json(201, [
                    'id' => 'f1', 'kind' => 'document', 'name' => 'C', 'description' => null,
                    'status' => 'active', 'payload_kind' => 'file', 'is_private' => false,
                    'value' => ['_pending' => true], 'metadata' => null,
                    'created_at' => null, 'updated_at' => null,
                ]);
            }
            self::assertStringEndsWith('/documents/f1/file', $url);
            return FakeTransport::json(200, ['id' => 'f1']);
        };
        [$client] = $this->clientRw($this->noGet(), $writeRouter);
        $client->createDocument([
            'name' => 'C', 'payload_kind' => 'file', 'file_bytes' => '%PDF-1.4 x', 'file_mime' => 'application/pdf',
        ]);
        self::assertStringEndsWith('/documents', $calls[0]['url']);
        self::assertNull(json_decode((string) $calls[0]['body'], true, flags: JSON_THROW_ON_ERROR)['target']);
        self::assertStringEndsWith('/documents/f1/file', $calls[1]['url']);
        // Broadcast /file body is JSON {"file": "<data URI>", "original_name"} — NOT raw bytes.
        $up = json_decode((string) $calls[1]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('data:application/pdf;base64,', $up['file']);
        self::assertSame('%PDF-1.4 x', base64_decode(explode(',', $up['file'], 2)[1], true));
        // The human label "C" has no extension; original_name gets the mime-derived extension
        // so the API's extension allowlist accepts it (documents.bad_mime otherwise).
        self::assertSame('C.pdf', $up['original_name']);
    }

    /** A {@code name} already ending in an allowed extension is sent unchanged (no "x.pdf.pdf"). */
    public function testCreateDocumentFileBroadcastKeepsExistingExtension(): void
    {
        $up = $this->broadcastUpload(['name' => 'report.pdf', 'file_mime' => 'application/pdf']);
        self::assertSame('report.pdf', $up['original_name']);
    }

    /** An extensionless {@code name} derives its extension from {@code file_mime}. */
    public function testCreateDocumentFileBroadcastDerivesExtensionFromMime(): void
    {
        $up = $this->broadcastUpload(['name' => 'Price list', 'file_mime' => 'image/jpeg']);
        self::assertSame('Price list.jpg', $up['original_name']);
    }

    /** An explicit {@code file_name} overrides both {@code name} and the mime-derived value. */
    public function testCreateDocumentFileBroadcastExplicitFileNameOverrides(): void
    {
        $up = $this->broadcastUpload([
            'name' => 'Price list', 'file_mime' => 'application/pdf', 'file_name' => 'prices.pdf',
        ]);
        self::assertSame('prices.pdf', $up['original_name']);
    }

    /**
     * Run a broadcast file createDocument with the given option overrides and return
     * the decoded JSON body POSTed to the /{id}/file endpoint.
     *
     * @param array<string,mixed> $opts
     *
     * @return array<string,mixed>
     */
    private function broadcastUpload(array $opts): array
    {
        $calls = [];
        $writeRouter = function (string $method, string $url, ?string $body) use (&$calls): Response {
            $calls[] = ['url' => $url, 'body' => $body];
            if (str_ends_with($url, '/documents')) {
                return FakeTransport::json(201, [
                    'id' => 'f1', 'kind' => 'document', 'name' => 'C', 'description' => null,
                    'status' => 'active', 'payload_kind' => 'file', 'is_private' => false,
                    'value' => ['_pending' => true], 'metadata' => null,
                    'created_at' => null, 'updated_at' => null,
                ]);
            }
            return FakeTransport::json(200, ['id' => 'f1']);
        };
        [$client] = $this->clientRw($this->noGet(), $writeRouter);
        $client->createDocument(array_merge(
            ['payload_kind' => 'file', 'file_bytes' => '%PDF-1.4 x'],
            $opts,
        ));

        return json_decode((string) $calls[1]['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testCreateDocumentFilePerPersonUploadsValueWrapper(): void
    {
        $spki = Vector::publicSpkiB64();
        $calls = [];
        $getRouter = fn (string $url, ?array $q): Response => FakeTransport::json(200, ['public_key' => $spki]);
        $writeRouter = function (string $method, string $url, ?string $body) use (&$calls): Response {
            $calls[] = ['url' => $url, 'body' => $body];
            if (str_ends_with($url, '/documents')) {
                return FakeTransport::json(201, [
                    'id' => 'f2', 'kind' => 'document', 'name' => 'C', 'description' => null,
                    'status' => 'active', 'payload_kind' => 'file', 'is_private' => true,
                    'value' => ['_pending' => true], 'metadata' => null,
                    'created_at' => null, 'updated_at' => null,
                ]);
            }
            return FakeTransport::json(200, ['id' => 'f2']);
        };
        [$client] = $this->clientRw($getRouter, $writeRouter);
        $client->createDocument([
            'name' => 'C', 'payload_kind' => 'file', 'file_bytes' => 'hello-bytes',
            'file_mime' => 'application/pdf', 'person_user_id' => 'u1', 'share_code' => 'ABC123',
            'is_private' => true,
        ]);
        $upload = $calls[1]['body'];
        self::assertIsString($upload);
        // Per-person /file body is JSON {"value": "<wrapper JSON string>"}, not the bare wrapper.
        $outer = json_decode($upload, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($outer['value']);
        $wrapper = json_decode($outer['value'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $wrapper['_enc']); // ciphertext wrapper, not the raw file
        // decrypt → the {"file":"data:...base64,..."} envelope holding the original bytes
        $env = json_decode(Crypto::decrypt($wrapper, Vector::privateKey()), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('data:application/pdf;base64,', $env['file']);
        self::assertSame('hello-bytes', base64_decode(explode(',', $env['file'], 2)[1], true));
    }

    /**
     * A failed /file upload best-effort deletes the just-created (still
     * {"_pending": true}) document and re-throws the ORIGINAL upload error, so a
     * failed createDocument leaves no dangling document behind (issue #27).
     */
    public function testCreateDocumentFileUploadFailureDeletesCreatedDoc(): void
    {
        $calls = [];
        $writeRouter = function (string $method, string $url, ?string $body) use (&$calls): Response {
            $calls[] = ['method' => $method, 'url' => $url];
            if (str_ends_with($url, '/documents')) {
                return FakeTransport::json(201, [
                    'id' => 'f9', 'kind' => 'document', 'name' => 'C', 'description' => null,
                    'status' => 'active', 'payload_kind' => 'file', 'is_private' => false,
                    'value' => ['_pending' => true], 'metadata' => null,
                    'created_at' => null, 'updated_at' => null,
                ]);
            }
            if (str_ends_with($url, '/documents/f9/file')) {
                // The byte upload fails.
                return FakeTransport::json(500, ['error_key' => 'documents.upload_failed']);
            }
            // The best-effort cleanup DELETE.
            self::assertSame('DELETE', $method);
            self::assertStringEndsWith('/documents/f9', $url);
            return FakeTransport::json(200, []);
        };
        [$client] = $this->clientRw($this->noGet(), $writeRouter);

        try {
            $client->createDocument([
                'name' => 'C', 'payload_kind' => 'file', 'file_bytes' => '%PDF-1.4 x',
                'file_mime' => 'application/pdf',
            ]);
            self::fail('expected the upload error to propagate');
        } catch (ApiError $e) {
            self::assertSame('documents.upload_failed', $e->errorKey);
        }

        // create POST → /file upload (failed) → cleanup DELETE on /documents/f9.
        $methods = array_map(fn ($c) => [$c['method'], $c['url']], $calls);
        self::assertNotEmpty(array_filter(
            $methods,
            fn ($m) => $m[0] === 'DELETE' && str_ends_with($m[1], '/documents/f9'),
        ));
    }

    public function testDocumentVerbsHitRightPath(): void
    {
        $seen = [];
        $getRouter = function (string $url, ?array $q): Response {
            if (str_ends_with($url, '/documents')) {
                return FakeTransport::json(200, ['total' => 0, 'items' => []]);
            }
            if (str_contains($url, '/documents/d9')) {
                return FakeTransport::json(200, ['id' => 'd9', 'payload_kind' => 'json', 'is_private' => false, 'value' => ['a' => 1]]);
            }
            throw new \AssertionError("unexpected GET {$url}");
        };
        $writeRouter = function (string $method, string $url, ?string $body) use (&$seen): Response {
            $seen[] = ['method' => $method, 'url' => $url, 'body' => $body];
            return FakeTransport::json(200, ['id' => 'd9', 'payload_kind' => 'json', 'is_private' => false, 'value' => ['a' => 1], 'status' => 'ended']);
        };
        [$client] = $this->clientRw($getRouter, $writeRouter);
        self::assertSame([], $client->listDocuments(status: 'active'));
        self::assertSame('d9', $client->document('d9')->id);
        $client->updateDocumentStatus('d9', 'ended');
        $client->updateDocumentMetadata('d9', name: 'renamed');
        $client->deleteDocument('d9');

        $methods = array_map(
            fn ($s) => [$s['method'], substr($s['url'], strpos($s['url'], '/api/company-data') + strlen('/api/company-data'))],
            $seen,
        );
        self::assertContains(['PUT', '/documents/d9'], $methods);
        self::assertSame(2, count(array_filter($methods, fn ($m) => $m === ['PUT', '/documents/d9'])));
        self::assertContains(['DELETE', '/documents/d9'], $methods);
    }

    public function testChangeDocumentStatusChangedParses(): void
    {
        $served = false;
        [$client] = $this->client(function (string $url, ?array $q) use (&$served): Response {
            if (str_ends_with($url, '/request-fields')) {
                return FakeTransport::json(200, ['request_fields' => []]);
            }
            if (str_ends_with($url, '/changes')) {
                if ($served) {
                    return FakeTransport::json(200, ['changes' => []]);
                }
                $served = true;
                return FakeTransport::json(200, ['changes' => [[
                    'id' => 'chg-doc', 'event' => 'document_status_changed',
                    'person_user_id' => 'u-1', 'share_code' => 'ABC123',
                    'document_id' => 'doc-9', 'status' => 'ended', 'at' => '2026-06-22T10:00:00Z',
                ]]]);
            }
            throw new \AssertionError("unexpected GET {$url}");
        });

        $seen = [];
        $client->processChanges(function ($c) use (&$seen): void {
            $seen[] = $c;
        });
        self::assertCount(1, $seen);
        $chg = $seen[0];
        self::assertSame('document_status_changed', $chg->event);
        self::assertSame('doc-9', $chg->documentId);
        self::assertSame('ended', $chg->status);
        self::assertSame('u-1', $chg->personId);
        self::assertSame('ABC123', $chg->shareCode);
        self::assertNull($chg->slug);
        self::assertNull($chg->value);
        self::assertNull($chg->live);
    }

    public function testDocumentModelPerPersonJsonDecrypts(): void
    {
        $wrapper = Crypto::encryptForPublicKey(json_encode(['plan' => 'pro'], JSON_THROW_ON_ERROR), Crypto::loadPublicKey(Vector::publicSpkiB64()));
        $doc = Document::fromApi(
            ['id' => 'd2', 'kind' => 'document', 'name' => 'PP', 'status' => 'active',
             'payload_kind' => 'json', 'is_private' => true, 'value' => $wrapper, 'metadata' => []],
            fn (array|string $w): string => Crypto::decrypt($w, Vector::privateKey()),
        );
        self::assertSame(['plan' => 'pro'], $doc->json()); // decrypted via injected decrypt
    }

    public function testDocumentModelBroadcastJsonIsPlaintext(): void
    {
        $doc = Document::fromApi([
            'id' => 'd1', 'kind' => 'document', 'name' => 'Terms', 'status' => 'active',
            'payload_kind' => 'json', 'is_private' => false, 'value' => ['v' => 1], 'metadata' => [],
        ]);
        self::assertSame(['v' => 1], $doc->json()); // no decrypt needed
    }

    public function testChangeDocumentStatusChangedCarriesAction(): void
    {
        $chg = Change::fromApi(
            ['id' => 'chg-sign', 'event' => 'document_status_changed', 'person_user_id' => 'u-2',
             'action' => 'signed', 'document_id' => 'doc-7', 'status' => 'active', 'at' => '2026-06-22T10:00:00Z'],
            fn (string $s): ?string => null,
            fn (array|string $w): string => '',
        );
        self::assertSame('document_status_changed', $chg->event);
        self::assertSame('signed', $chg->action);
        self::assertSame('doc-7', $chg->documentId);
        self::assertSame('active', $chg->status);
        self::assertNull($chg->note); // no note on a signed event
        self::assertNull($chg->slug);

        $cancelled = Change::fromApi(
            ['id' => 'chg-cancel', 'event' => 'document_status_changed', 'person_user_id' => 'u-3',
             'action' => 'cancelled', 'note' => 'Too expensive', 'document_id' => 'doc-8', 'status' => 'ended',
             'at' => '2026-06-22T11:00:00Z'],
            fn (string $s): ?string => null,
            fn (array|string $w): string => '',
        );
        self::assertSame('cancelled', $cancelled->action);
        self::assertSame('Too expensive', $cancelled->note);
    }

    public function testDocumentModelCarriesContractFlagsAndSignatures(): void
    {
        $doc = Document::fromApi([
            'id' => 'c1', 'kind' => 'agreement', 'name' => 'Agreement', 'status' => 'active',
            'payload_kind' => 'json', 'is_private' => false, 'value' => ['v' => 1], 'metadata' => [],
            'requires_signature' => true, 'requires_acceptance' => false,
            'signatures' => [['action' => 'signed', 'method' => 'biometric']],
        ]);
        self::assertTrue($doc->requiresSignature);
        self::assertFalse($doc->requiresAcceptance);
        self::assertCount(1, $doc->signatures);
        self::assertSame('signed', $doc->signatures[0]['action']);
    }

    // ── connect requests (service-initiated; idea 2) ────────────────────────────

    public function testSendConnectRequestPostsShareCodeAndReturnsRequestId(): void
    {
        $captured = [];
        $writeRouter = function (string $method, string $url, ?string $body) use (&$captured): Response {
            self::assertSame('POST', $method);
            self::assertStringEndsWith('/company-data/connect-requests', $url);
            $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(201, ['request_id' => 'req-1']);
        };
        [$client] = $this->clientRw($this->noGet(), $writeRouter);
        self::assertSame('req-1', $client->sendConnectRequest('  ABC123 '));
        self::assertSame(['share_code' => 'ABC123'], $captured['body']); // trimmed
    }

    public function testSendConnectRequestBlankThrowsConfigError(): void
    {
        [$client] = $this->clientRw($this->noGet(), function (string $method, string $url, ?string $body): Response {
            throw new \AssertionError('should not write for a blank share code');
        });
        $this->expectException(ConfigError::class);
        $client->sendConnectRequest('   ');
    }

    public function testSendConnectRequestMissingIdThrowsApiError(): void
    {
        [$client] = $this->clientRw(
            $this->noGet(),
            fn (string $method, string $url, ?string $body): Response => FakeTransport::json(201, []),
        );
        $this->expectException(ApiError::class);
        $client->sendConnectRequest('ABC123');
    }

    public function testChangeConnectRequestOutcomeEventsCarryRequestId(): void
    {
        $type = fn (string $s): ?string => null;
        $dec = fn (array|string $w): string => '';

        $accepted = Change::fromApi(
            ['id' => 'c1', 'event' => 'connection_request_accepted', 'request_id' => 'req-9',
             'person_user_id' => 'person-1', 'share_code' => 'P1CODE', 'at' => '2026-06-23T10:00:00Z'],
            $type, $dec,
        );
        self::assertSame('connection_request_accepted', $accepted->event);
        self::assertSame('req-9', $accepted->requestId);
        self::assertSame('person-1', $accepted->personId);
        self::assertSame('P1CODE', $accepted->shareCode);
        self::assertNull($accepted->slug);
        self::assertNull($accepted->value);

        $rejected = Change::fromApi(
            ['id' => 'c2', 'event' => 'connection_request_rejected', 'request_id' => 'req-8',
             'person_user_id' => 'person-2'],
            $type, $dec,
        );
        self::assertSame('connection_request_rejected', $rejected->event);
        self::assertSame('req-8', $rejected->requestId);

        $created = Change::fromApi(
            ['id' => 'c3', 'event' => 'connection_created', 'person_user_id' => 'person-3'],
            $type, $dec,
        );
        self::assertNull($created->requestId); // unrelated event
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
