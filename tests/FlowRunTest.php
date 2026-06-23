<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Client;
use Allus\CompanyData\Config;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Http\Response;
use Allus\CompanyData\Model\FlowRun;
use Allus\CompanyData\Tests\Support\FakeTransport;
use Allus\CompanyData\Tests\Support\Vector;
use PHPUnit\Framework\TestCase;

/**
 * Company-side contract-flow run methods — fully mocked (no live API). Mirrors the Python/TS/Go/
 * C#/Java run-method tests: trigger/list/get, decrypt-only-company, per-party fan-out + local
 * routing, generate one-time-key shape, and the processFlowRun company-leaf document chain.
 */
final class FlowRunTest extends TestCase
{
    private const COMPANY_UID = 'company-1';
    private const PERSON_UID = 'person-1';

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
        $this->dir = sys_get_temp_dir() . '/allus-flow-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->pemPath = $this->dir . '/service-key.pem';
        file_put_contents($this->pemPath, self::$vector['encrypted_private_key_pem']);
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
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
     * @param (callable(string, ?array<string,scalar>): Response)|null $getRouter
     * @param callable(string, string, ?string, array<string,string>): Response $writeRouter
     */
    private function clientRw(?callable $getRouter, callable $writeRouter): Client
    {
        $t = new FakeTransport($getRouter, $writeRouter);
        $http = new HttpClient($this->config(), transport: $t);
        return new Client($this->config(), http: $http, sleep: fn (float $_s): null => null);
    }

    private function noGet(): callable
    {
        return function (string $url): Response {
            throw new \AssertionError("unexpected GET {$url}");
        };
    }

    private function keyGet(string $spki): callable
    {
        return function (string $url) use ($spki): Response {
            if (str_ends_with($url, '/company-data/connections/csc-1')) {
                return FakeTransport::json(200, ['connection_id' => 'csc-1', 'share_code' => 'ABC123']);
            }
            if (str_ends_with($url, '/api/keys/ABC123')) {
                return FakeTransport::json(200, ['public_key' => $spki]);
            }
            throw new \AssertionError("unexpected GET {$url}");
        };
    }

    /** @return array<string,mixed> */
    private static function flowDef(): array
    {
        return [
            'output_mode' => 'data_only',
            'parties' => [['key' => 'company'], ['key' => 'person']],
            'nodes' => [
                ['key' => 'n1', 'party' => 'company'],
                ['key' => 'n2', 'party' => 'person'],
                ['key' => 'n_end', 'party' => 'person'],
            ],
            'edges' => [
                ['from' => 'n1', 'to' => 'n_end', 'sort' => 0, 'condition' => ['field' => 'tier', 'op' => 'eq', 'value' => 'vip']],
                ['from' => 'n1', 'to' => 'n2', 'sort' => 1, 'condition' => null],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $answers
     * @param array<string,mixed>|null  $def
     *
     * @return array<string,mixed>
     */
    private static function runObj(
        string $status = 'awaiting_company',
        string $current = 'n1',
        array $answers = [],
        ?array $def = null,
        string $outputMode = 'data_only',
        ?string $documentId = null,
    ): array {
        $def ??= self::flowDef();
        $def['output_mode'] = $outputMode;
        return [
            'id' => 'run-1',
            'flow_id' => 'flow-1',
            'flow_version' => 3,
            'service_id' => 'svc-1',
            'connection_id' => 'csc-1',
            'company_user_id' => self::COMPANY_UID,
            'bindings' => ['company' => self::COMPANY_UID, 'person' => self::PERSON_UID],
            'status' => $status,
            'current_node' => $current,
            'document_id' => $documentId,
            'output_mode' => $outputMode,
            'definition' => $def,
            'answers' => $answers,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    // ── trigger / list / get ──────────────────────────────────────────────────────

    public function testTriggerFlowRun(): void
    {
        $captured = [];
        $client = $this->clientRw($this->noGet(), function (string $method, string $url, ?string $body) use (&$captured): Response {
            $captured['url'] = $url;
            $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(201, self::runObj());
        });
        $run = $client->triggerFlowRun('flow-1', 'csc-1', ['company' => self::COMPANY_UID, 'person' => self::PERSON_UID]);
        self::assertStringEndsWith('/company-data/flows/flow-1/runs', $captured['url']);
        self::assertSame('csc-1', $captured['body']['target']['connection_id']);
        self::assertSame('company', $run->companyPartyKey());
        self::assertSame(self::COMPANY_UID, $run->serviceUserId());
    }

    public function testFlowRunsDefaultAwaitingCompany(): void
    {
        $client = $this->clientRw(function (string $url, ?array $q): Response {
            self::assertStringEndsWith('/company-data/flow-runs', $url);
            self::assertSame('awaiting_company', $q['status'] ?? null);
            return FakeTransport::json(200, ['total' => 1, 'items' => [self::runObj()]]);
        }, fn (string $m, string $u, ?string $b): Response => FakeTransport::json(200, []));
        $runs = $client->flowRuns();
        self::assertCount(1, $runs);
        self::assertSame('awaiting_company', $runs[0]->status);
    }

    public function testFlowRunById(): void
    {
        $client = $this->clientRw(function (string $url): Response {
            self::assertStringEndsWith('/company-data/flow-runs/run-1', $url);
            return FakeTransport::json(200, self::runObj());
        }, fn (string $m, string $u, ?string $b): Response => FakeTransport::json(200, []));
        self::assertSame('n1', $client->flowRun('run-1')->currentNode);
    }

    // ── submit: per-party fan-out + local routing ─────────────────────────────────

    public function testSubmitFansOutAndRoutesFallthrough(): void
    {
        $spki = Vector::publicSpkiB64();
        $captured = [];
        $client = $this->clientRw($this->keyGet($spki), function (string $method, string $url, ?string $body) use (&$captured): Response {
            $captured['url'] = $url;
            $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(200, self::runObj('awaiting_person', 'n2'));
        });
        $run = FlowRun::fromApi(self::runObj());
        $out = $client->submitFlowAnswers($run, ['company_name' => 'ACME BV']);

        self::assertStringEndsWith('/company-data/flow-runs/run-1/answers', $captured['url']);
        self::assertCount(1, $captured['body']['answers']);
        $values = $captured['body']['answers'][0]['values'];
        $forUsers = array_map(fn ($v) => $v['for_user_id'], $values);
        sort($forUsers);
        self::assertSame([self::COMPANY_UID, self::PERSON_UID], $forUsers);
        foreach ($values as $v) {
            self::assertSame(1, $v['value']['_enc']);
        }
        // company copy round-trips with the service private key
        $companyVal = null;
        foreach ($values as $v) {
            if ($v['for_user_id'] === self::COMPANY_UID) {
                $companyVal = $v['value'];
            }
        }
        $priv = Crypto::loadPrivateKey(self::$vector['encrypted_private_key_pem'], self::$vector['passphrase']);
        self::assertSame('ACME BV', Crypto::decrypt($companyVal, $priv));
        // local routing: no 'tier' → fallthrough to n2
        self::assertSame('n2', $captured['body']['next_node']);
        self::assertSame('person', $captured['body']['next_party']);
        self::assertArrayNotHasKey('leaf', $captured['body']);
        self::assertSame('awaiting_person', $out->status);
    }

    public function testSubmitRoutesGuardedEdge(): void
    {
        $spki = Vector::publicSpkiB64();
        $captured = [];
        $client = $this->clientRw($this->keyGet($spki), function (string $method, string $url, ?string $body) use (&$captured): Response {
            $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(200, self::runObj('awaiting_person', 'n_end'));
        });
        $run = FlowRun::fromApi(self::runObj());
        $client->submitFlowAnswers($run, ['tier' => 'vip']);
        self::assertSame('n_end', $captured['body']['next_node']);
        self::assertArrayNotHasKey('leaf', $captured['body']);
    }

    public function testSubmitUsesSuppliedPartyPubKeys(): void
    {
        $priv = Crypto::loadPrivateKey(self::$vector['encrypted_private_key_pem'], self::$vector['passphrase']);
        $personPub = Crypto::loadPublicKey(Vector::publicSpkiB64());
        $captured = [];
        $client = $this->clientRw($this->noGet(), function (string $method, string $url, ?string $body) use (&$captured): Response {
            $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(200, self::runObj('awaiting_person', 'n2'));
        });
        $run = FlowRun::fromApi(self::runObj());
        $client->submitFlowAnswers($run, ['company_name' => 'X'], [self::PERSON_UID => $personPub]);
        $values = $captured['body']['answers'][0]['values'];
        self::assertCount(2, $values);
    }

    // ── generate (document leaf) ──────────────────────────────────────────────────

    public function testGenerateFlowDocument(): void
    {
        $wrapper = Vector::encryptForKey('ACME BV');
        $answers = [['slug' => 'company_name', 'for_user_id' => self::COMPANY_UID, 'value' => $wrapper]];
        $captured = [];
        $client = $this->clientRw($this->noGet(), function (string $method, string $url, ?string $body) use (&$captured): Response {
            $captured['url'] = $url;
            $captured['body'] = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            return FakeTransport::json(200, ['document_id' => 'doc-9', 'status' => 'awaiting_signature']);
        });
        $run = FlowRun::fromApi(self::runObj('generating', 'n1', $answers, null, 'document'));
        $res = $client->generateFlowDocument($run);
        self::assertSame('doc-9', $res['document_id']);
        self::assertStringEndsWith('/company-data/flow-runs/run-1/generate', $captured['url']);

        $otk = base64_decode($captured['body']['otk'], true);
        $blob = base64_decode($captured['body']['values'], true);
        self::assertNotFalse($otk);
        self::assertNotFalse($blob);
        self::assertSame(32, strlen($otk));
        self::assertGreaterThanOrEqual(12 + 16, strlen($blob));
        // reproduce the server read: iv(12) . ct . tag(16)
        $iv = substr($blob, 0, 12);
        $tag = substr($blob, -16);
        $ct = substr($blob, 12, -16);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $otk, OPENSSL_RAW_DATA, $iv, $tag);
        self::assertNotFalse($plain);
        $got = json_decode($plain, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('ACME BV', $got['company_name']);
    }

    // ── processFlowRun: chains submit + generate on a company-leaf document flow ───

    public function testProcessFlowRunCompanyLeafDocument(): void
    {
        $spki = Vector::publicSpkiB64();
        $single = [
            'output_mode' => 'document',
            'parties' => [['key' => 'company'], ['key' => 'person']],
            'nodes' => [['key' => 'n1', 'party' => 'company']],
            'edges' => [],
        ];
        $posts = [];
        $getRouter = function (string $url) use ($spki, &$posts, $single): Response {
            if (str_ends_with($url, '/company-data/flow-runs/run-1')) {
                $status = $posts === [] ? 'awaiting_company' : 'awaiting_signature';
                $docId = $posts === [] ? null : 'doc-9';
                return FakeTransport::json(200, self::runObj($status, 'n1', [], $single, 'document', $docId));
            }
            if (str_ends_with($url, '/company-data/connections/csc-1')) {
                return FakeTransport::json(200, ['connection_id' => 'csc-1', 'share_code' => 'ABC123']);
            }
            if (str_ends_with($url, '/api/keys/ABC123')) {
                return FakeTransport::json(200, ['public_key' => $spki]);
            }
            throw new \AssertionError("unexpected GET {$url}");
        };
        $writeRouter = function (string $method, string $url, ?string $body) use (&$posts, $single): Response {
            $posts[] = $url;
            if (str_ends_with($url, '/answers')) {
                return FakeTransport::json(200, self::runObj('generating', 'n1', [], $single, 'document'));
            }
            self::assertStringEndsWith('/generate', $url);
            return FakeTransport::json(200, ['document_id' => 'doc-9', 'status' => 'awaiting_signature']);
        };
        $client = $this->clientRw($getRouter, $writeRouter);
        $run = $client->processFlowRun('run-1', fn (array $node, array $answers): array => ['company_name' => 'ACME BV']);

        self::assertTrue((bool) array_filter($posts, fn ($p) => str_ends_with($p, '/answers')));
        self::assertTrue((bool) array_filter($posts, fn ($p) => str_ends_with($p, '/generate')));
        self::assertSame('awaiting_signature', $run->status);
        self::assertSame('doc-9', $run->documentId);
    }

    public function testProcessFlowRunNotOurTurn(): void
    {
        $calls = 0;
        $client = $this->clientRw(
            fn (string $url): Response => FakeTransport::json(200, self::runObj('awaiting_person', 'n2')),
            fn (string $m, string $u, ?string $b): Response => FakeTransport::json(200, []),
        );
        $run = $client->processFlowRun('run-1', function (array $node, array $answers) use (&$calls): array {
            $calls++;
            return ['x' => 'y'];
        });
        self::assertSame('awaiting_person', $run->status);
        self::assertSame(0, $calls);
    }
}
