<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\AuthError;
use Allus\CompanyData\Errors\RateLimitError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * HTTP/auth layer tests — all mocked, no live API.
 */
final class HttpTest extends TestCase
{
    private function config(string $fmt = 'json'): Config
    {
        // service_private_key path need not exist — HttpClient never loads it.
        return new Config(
            apiUrl: 'https://api.allme.fyi',
            clientId: 'svc_abc',
            clientSecret: 'topsecret',
            servicePrivateKey: '/no/such/k.pem',
            keyPassphrase: 'pp',
            format: $fmt,
        );
    }

    /**
     * @param list<float> $sleeps captured sleep durations (by reference)
     */
    private function client(FakeTransport $t, string $fmt = 'json', array &$sleeps = [], int $maxRetries429 = 3): HttpClient
    {
        return new HttpClient(
            $this->config($fmt),
            transport: $t,
            sleep: function (float $s) use (&$sleeps): void {
                $sleeps[] = $s;
            },
            maxRetries429: $maxRetries429,
        );
    }

    // ── token fetch + caching ───────────────────────────────────────────────

    public function testTokenFetchedWithClientCredentialsAndAttached(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [FakeTransport::json(200, ['ok' => true])];
        $c = $this->client($t);

        $body = $c->get('/api/company-data/request-fields');
        self::assertSame(['ok' => true], $body);

        self::assertSame('https://api.allme.fyi/oauth2/token', $t->posts[0]['url']);
        self::assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'svc_abc',
            'client_secret' => 'topsecret',
        ], $t->posts[0]['form']);
        self::assertSame('Bearer tok-123', $t->gets[0]['headers']['Authorization']);
        self::assertSame('application/json', $t->gets[0]['headers']['Accept']);
    }

    public function testTokenIsCachedAcrossCalls(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()]; // only one token fetch expected
        $t->getResponses = [
            FakeTransport::json(200, ['n' => 1]),
            FakeTransport::json(200, ['n' => 2]),
        ];
        $c = $this->client($t);
        $c->get('/api/company-data/changes');
        $c->get('/api/company-data/changes');
        self::assertCount(1, $t->posts); // token fetched once and reused
    }

    public function testTokenRefetchedWhenExpired(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [
            FakeTransport::json(200, ['access_token' => 'first', 'expires_in' => 0]),
            FakeTransport::json(200, ['access_token' => 'second', 'expires_in' => 3600]),
        ];
        $t->getResponses = [FakeTransport::json(200, []), FakeTransport::json(200, [])];
        // A monotonic clock that advances so the 0-expiry token is stale by the 2nd call.
        $ticks = [0.0, 0.0, 100.0, 100.0, 100.0, 100.0];
        $i = 0;
        $clock = function () use (&$ticks, &$i): float {
            return $ticks[$i++] ?? 1000.0;
        };
        $c = new HttpClient($this->config(), transport: $t, clock: $clock);

        $c->get('/api/company-data/changes'); // "first" (expires_in=0 → already stale)
        $c->get('/api/company-data/changes'); // must refetch → "second"
        self::assertCount(2, $t->posts);
        self::assertSame('Bearer second', $t->gets[1]['headers']['Authorization']);
    }

    public function testTokenFetchFailureRaisesAuthError(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::json(401, ['error_key' => 'oauth.bad_client'])];
        $c = $this->client($t);
        $this->expectException(AuthError::class);
        $c->get('/api/company-data/changes');
    }

    // ── 401 refresh-and-retry ─────────────────────────────────────────────

    public function test401TriggersOneRefreshAndRetryThenSucceeds(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk(), FakeTransport::tokenOk()];
        $t->getResponses = [
            FakeTransport::json(401, ['error_key' => 'auth.expired']),
            FakeTransport::json(200, ['recovered' => true]),
        ];
        $c = $this->client($t);
        $body = $c->get('/api/company-data/connections');
        self::assertSame(['recovered' => true], $body);
        self::assertCount(2, $t->posts); // token refreshed exactly once
        self::assertCount(2, $t->gets);  // original + retry
    }

    public function test401AfterRefreshRaisesAuthError(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk(), FakeTransport::tokenOk()];
        $t->getResponses = [
            FakeTransport::json(401, ['error_key' => 'auth.expired']),
            FakeTransport::json(401, ['error_key' => 'auth.expired']),
        ];
        $c = $this->client($t);
        try {
            $c->get('/api/company-data/connections');
            self::fail('expected AuthError');
        } catch (AuthError) {
            self::assertCount(2, $t->posts); // only ONE refresh, then gives up
        }
    }

    // ── 429 backoff ─────────────────────────────────────────────────────

    public function test429WithRetryAfterBacksOffThenSucceeds(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [
            FakeTransport::json(429, ['error_key' => 'rate.limited'], ['Retry-After' => '2']),
            FakeTransport::json(200, ['done' => true]),
        ];
        $sleeps = [];
        $c = $this->client($t, sleeps: $sleeps);
        $body = $c->get('/api/company-data/changes');
        self::assertSame(['done' => true], $body);
        self::assertSame([2.0], $sleeps); // honored Retry-After
    }

    public function test429ExhaustsRetriesThenRaisesRateLimitError(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = array_fill(0, 10, FakeTransport::json(429, ['error_key' => 'rate.limited'], ['Retry-After' => '1']));
        $sleeps = [];
        $c = $this->client($t, sleeps: $sleeps, maxRetries429: 3);
        try {
            $c->get('/api/company-data/connections');
            self::fail('expected RateLimitError');
        } catch (RateLimitError $e) {
            self::assertSame(1.0, $e->retryAfter);
            self::assertSame(429, $e->status);
            self::assertSame('rate.limited', $e->errorKey);
            self::assertCount(3, $sleeps); // 3 bounded retries → 3 sleeps
            self::assertCount(4, $t->gets); // 4 GET attempts total
        }
    }

    public function test429DefaultBackoffWhenNoRetryAfter(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [
            FakeTransport::json(429, ['error_key' => 'rate.limited']),
            FakeTransport::json(200, ['ok' => 1]),
        ];
        $sleeps = [];
        $c = $this->client($t, sleeps: $sleeps);
        self::assertSame(['ok' => 1], $c->get('/api/company-data/changes'));
        self::assertCount(1, $sleeps);
        self::assertGreaterThan(0, $sleeps[0]); // exponential default kicked in
    }

    // ── ApiError mapping ──────────────────────────────────────────────────

    public function testNon2xxMapsToApiErrorWithErrorKey(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [FakeTransport::json(403, [
            'error' => 'Not a registered service client',
            'error_key' => 'company_data.no_client',
        ])];
        $c = $this->client($t);
        try {
            $c->get('/api/company-data/connections');
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(403, $e->status);
            self::assertSame('company_data.no_client', $e->errorKey);
            self::assertStringContainsString('Not a registered service client', $e->getMessage());
            self::assertNotInstanceOf(RateLimitError::class, $e); // not a 429
        }
    }

    public function test404MapsToApiError(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [FakeTransport::json(404, ['error_key' => 'company_data.connection_not_found'])];
        $c = $this->client($t);
        try {
            $c->get('/api/company-data/connections/zzz');
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame(404, $e->status);
            self::assertSame('company_data.connection_not_found', $e->errorKey);
        }
    }

    // ── XML format ────────────────────────────────────────────────────────

    public function testXmlAcceptHeaderAndParsing(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<response>'
            . '<request_fields>'
            . '<item><slug>work_email</slug><label>Work email</label><type>email</type>'
            . '<one_time>false</one_time><mandatory_provide>true</mandatory_provide>'
            . '<mandatory_connected>false</mandatory_connected></item>'
            . '<item><slug>logo</slug><label>Logo</label><type>photo</type>'
            . '<one_time>false</one_time><mandatory_provide>false</mandatory_provide>'
            . '<mandatory_connected>false</mandatory_connected></item>'
            . '</request_fields>'
            . '</response>';
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [FakeTransport::text(200, $xml)];
        $c = $this->client($t, fmt: 'xml');

        $body = $c->get('/api/company-data/request-fields');
        self::assertSame('application/xml', $t->gets[0]['headers']['Accept']);
        self::assertIsArray($body);
        self::assertIsArray($body['request_fields']);
        self::assertCount(2, $body['request_fields']);
        self::assertSame('work_email', $body['request_fields'][0]['slug']);
        self::assertSame('email', $body['request_fields'][0]['type']);
        // Booleans come back as the "true"/"false" strings the serializer wrote.
        self::assertSame('false', $body['request_fields'][0]['one_time']);
        self::assertSame('true', $body['request_fields'][0]['mandatory_provide']);
    }

    public function testXmlErrorBodyCarriesErrorKey(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<response><error>nope</error><error_key>company_data.no_client</error_key></response>';
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [FakeTransport::text(403, $xml)];
        $c = $this->client($t, fmt: 'xml');
        try {
            $c->get('/api/company-data/connections');
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertSame('company_data.no_client', $e->errorKey);
        }
    }

    public function testXmlSingleItemListIsStillAList(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<response><changes><item><id>c1</id><event>connection_created</event>'
            . '<person_user_id>u1</person_user_id></item></changes></response>';
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [FakeTransport::text(200, $xml)];
        $c = $this->client($t, fmt: 'xml');
        $body = $c->get('/api/company-data/changes');
        self::assertIsArray($body['changes']);
        self::assertSame('connection_created', $body['changes'][0]['event']);
    }

    // ── XXE safety ──────────────────────────────────────────────────────────

    public function testXmlDoctypeIsRejectedXxeSafe(): void
    {
        // A classic XXE attempt: a DOCTYPE defining an external entity. The parser
        // must reject the DOCTYPE outright (never read the local file).
        $payload = '<?xml version="1.0"?>'
            . '<!DOCTYPE response [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<response><leak>&xxe;</leak></response>';
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [FakeTransport::text(200, $payload)];
        $c = $this->client($t, fmt: 'xml');
        $this->expectException(ApiError::class);
        $c->get('/api/company-data/request-fields');
    }
}
