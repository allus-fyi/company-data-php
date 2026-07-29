<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Http\HttpClient;
use Allus\CompanyData\Tests\Support\FakeTransport;
use Allus\CompanyData\TwoFactorClient;
use PHPUnit\Framework\TestCase;

/**
 * The 2FA client's additions on top of the base challenge/result client: waitForResult.
 * Ports test_two_factor.py.
 */
final class TwoFactorTest extends TestCase
{
    private function config(): Config
    {
        return new Config(
            apiUrl: 'https://api.allme.fyi',
            clientId: 'svc_abc',
            clientSecret: 'topsecret',
            servicePrivateKey: '/no/such/k.pem',
            keyPassphrase: 'pp',
        );
    }

    /**
     * A real HttpClient over a scripted transport (HttpClient/TwoFactorClient are final — no stub
     * subclass). One token POST is cached; each result() poll is one queued GET.
     */
    private function client(FakeTransport $t): TwoFactorClient
    {
        $http = new HttpClient($this->config(), transport: $t);

        return new TwoFactorClient($http, static fn (int $s) => null);
    }

    public function testWaitForResultReturnsFirstTerminal(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [
            FakeTransport::json(200, ['status' => 'pending']),
            FakeTransport::json(200, ['status' => 'pending']),
            FakeTransport::json(200, ['status' => 'approved', 'completed_at' => '2026-07-24T10:00:00Z']),
        ];
        $res = $this->client($t)->waitForResult('chal_1', 600, 0);
        self::assertSame('approved', $res->status);
        self::assertSame('2026-07-24T10:00:00Z', $res->completedAt);
        // Stopped at the first terminal read — never re-read a burned challenge.
        self::assertCount(3, $t->gets);
    }

    /**
     * @return list<array{string}>
     */
    public static function terminalStatuses(): array
    {
        return [['approved'], ['denied'], ['expired'], ['revoked'], ['gone']];
    }

    /**
     * @dataProvider terminalStatuses
     */
    public function testWaitForResultEachTerminalStatus(string $terminal): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        $t->getResponses = [
            FakeTransport::json(200, ['status' => 'pending']),
            FakeTransport::json(200, ['status' => $terminal]),
        ];
        self::assertSame($terminal, $this->client($t)->waitForResult('chal_1', 600, 0)->status);
    }

    public function testWaitForResultTimeoutRaisesApiError(): void
    {
        $t = new FakeTransport();
        $t->postResponses = [FakeTransport::tokenOk()];
        // timeout=0 → after the first pending poll the deadline has passed.
        $t->getResponses = [
            FakeTransport::json(200, ['status' => 'pending']),
            FakeTransport::json(200, ['status' => 'pending']),
        ];
        try {
            $this->client($t)->waitForResult('chal_late', 0, 0);
            self::fail('expected ApiError');
        } catch (ApiError $e) {
            self::assertStringContainsString('not completed within', $e->getMessage());
        }
    }
}
