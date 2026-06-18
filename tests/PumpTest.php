<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests;

use Allus\CompanyData\Config;
use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\DecryptError;
use Allus\CompanyData\Model\Change;
use Allus\CompanyData\Pump\FileBuffer;
use Allus\CompanyData\Pump\Pump;
use Allus\CompanyData\Tests\Support\Vector;
use PHPUnit\Framework\TestCase;
use phpseclib3\Crypt\RSA\PrivateKey as RSAPrivateKey;

/**
 * Crash-safe changes-pump tests.
 *
 * Drives the pump with a fake in-memory drain-on-fetch source returning canned
 * CIPHERTEXT events (reusing the shared vector's real {_enc:1,...} wrapper) and a
 * decrypt callable that runs the real crypto core. Covers the full §6 contract +
 * the four durability caveats.
 */
final class PumpTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $vector;
    private static RSAPrivateKey $key;

    private string $cacheDir;
    /** @var list<string> */
    private array $cleanup = [];

    public static function setUpBeforeClass(): void
    {
        self::$vector = Vector::load();
        self::$key = Crypto::loadPrivateKey(self::$vector['encrypted_private_key_pem'], self::$vector['passphrase']);
    }

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/allus-pump-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::rmrf($this->cacheDir);
    }

    private function config(): Config
    {
        return new Config(
            apiUrl: 'https://api.example.test',
            clientId: 'svc_test',
            clientSecret: 'secret',
            servicePrivateKey: 'unused.pem',
            keyPassphrase: self::$vector['passphrase'],
            cacheDir: $this->cacheDir,
        );
    }

    /** @return callable(array<string,mixed>): Change */
    private function decryptChange(): callable
    {
        return fn (array $event): Change => Change::fromApi(
            $event,
            fn (string $s): ?string => 'text',
            fn (array|string $w): string => Crypto::decrypt($w, self::$key),
        );
    }

    /** @return list<array<string,mixed>> */
    private function makeEvents(int $count, int $start = 1): array
    {
        $events = [];
        for ($i = $start; $i < $start + $count; $i++) {
            $events[] = [
                'id' => sprintf('chg-%04d', $i),
                'event' => 'field_updated',
                'person_user_id' => "person-{$i}",
                'slug' => 'work_email',
                'value' => self::$vector['text']['wrapper'], // ciphertext, as the API serves it
                'live' => true,
                'at' => sprintf('2026-06-17T10:0%d:00Z', $i),
            ];
        }
        return $events;
    }

    private function pump(FakeSource $source): Pump
    {
        return new Pump(
            $this->config(),
            fn (int $limit): array => $source->fetch($limit),
            $this->decryptChange(),
            sleep: fn (float $_s): null => null, // no real backoff sleeps in tests
        );
    }

    // ── (a) persist-before-deliver ─────────────────────────────────────────

    public function testBatchPersistedBeforeAnyHandlerCall(): void
    {
        $source = new FakeSource($this->makeEvents(3));
        $cacheDir = $this->cacheDir;
        $pendingAtFirstCall = null;

        $handler = function (Change $c) use (&$pendingAtFirstCall, $cacheDir): void {
            if ($pendingAtFirstCall === null) {
                $buf = new FileBuffer($cacheDir);
                $pendingAtFirstCall = count($buf->pending());
            }
        };

        $this->pump($source)->processChanges($handler);
        self::assertSame(3, $pendingAtFirstCall);
    }

    // ── (b) ack on success ──────────────────────────────────────────────────

    public function testHandlerSuccessAcksPendingFile(): void
    {
        $source = new FakeSource($this->makeEvents(3));
        $seen = [];
        $this->pump($source)->processChanges(function (Change $c) use (&$seen): void {
            $seen[] = $c->id;
        });
        self::assertSame(['chg-0001', 'chg-0002', 'chg-0003'], $seen);
        $buf = new FileBuffer($this->cacheDir);
        self::assertSame([], $buf->pending());
        self::assertSame([], $buf->deadLetters());
    }

    public function testDeliveredChangeIsDecryptedPlaintext(): void
    {
        $source = new FakeSource($this->makeEvents(1));
        $delivered = [];
        $this->pump($source)->processChanges(function (Change $c) use (&$delivered): void {
            $delivered[] = $c;
        });
        self::assertCount(1, $delivered);
        self::assertSame(self::$vector['text']['plaintext'], $delivered[0]->value);
    }

    // ── (c) retry → dead-letter → continue ──────────────────────────────────

    public function testPoisonEventDeadLetteredOthersProcessed(): void
    {
        $source = new FakeSource($this->makeEvents(3));
        $attempts = 0;
        $deliveredOk = [];
        $handler = function (Change $c) use (&$attempts, &$deliveredOk): void {
            if ($c->id === 'chg-0002') {
                $attempts++;
                throw new \RuntimeException('poison');
            }
            $deliveredOk[] = $c->id;
        };
        $this->pump($source)->processChanges($handler, maxRetries: 3);

        self::assertSame(4, $attempts); // 1 + max_retries
        self::assertSame(['chg-0001', 'chg-0003'], $deliveredOk);

        $buf = new FileBuffer($this->cacheDir);
        self::assertSame([], $buf->pending());
        $dl = $buf->deadLetters();
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], $dl));
        self::assertStringContainsString('poison', $dl[0]['error']);
        self::assertSame(4, $dl[0]['attempts']);
    }

    public function testOnErrorHaltRaisesAndLeavesPending(): void
    {
        $source = new FakeSource($this->makeEvents(3));
        $handler = function (Change $c): void {
            if ($c->id === 'chg-0002') {
                throw new \RuntimeException('halt-me');
            }
        };
        try {
            $this->pump($source)->processChanges($handler, maxRetries: 1, onError: 'halt');
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('halt-me', $e->getMessage());
        }
        $buf = new FileBuffer($this->cacheDir);
        $pendingIds = array_map(fn ($e) => $e['id'], $buf->pending());
        self::assertSame(['chg-0002', 'chg-0003'], $pendingIds);
    }

    // ── (d) crash test ──────────────────────────────────────────────────────

    public function testCrashAfterOneThenReplayOnRestart(): void
    {
        $source = new FakeSource($this->makeEvents(3));
        $deliveredRun1 = [];
        $handler = function (Change $c) use (&$deliveredRun1): void {
            $deliveredRun1[] = $c->id;
            if (count($deliveredRun1) === 1) {
                return; // #1 succeeds → gets acked
            }
            throw new \RuntimeException('CRASH'); // dying right after #1's ack
        };
        try {
            $this->pump($source)->processChanges($handler, maxRetries: 0, onError: 'halt');
            self::fail('expected crash');
        } catch (\RuntimeException) {
        }

        self::assertSame(['chg-0001', 'chg-0002'], $deliveredRun1);
        $bufMid = new FileBuffer($this->cacheDir);
        self::assertSame(['chg-0002', 'chg-0003'], array_map(fn ($e) => $e['id'], $bufMid->pending()));

        // Restart: a brand-new pump on the SAME cacheDir, with NO new events.
        $emptySource = new FakeSource([]);
        $deliveredRun2 = [];
        (new Pump(
            $this->config(),
            fn (int $l): array => $emptySource->fetch($l),
            $this->decryptChange(),
            sleep: fn (float $_s): null => null,
        ))->processChanges(function (Change $c) use (&$deliveredRun2): void {
            $deliveredRun2[] = $c->id;
        });

        self::assertSame(['chg-0002', 'chg-0003'], $deliveredRun2);
        self::assertNotEmpty($emptySource->fetchCalls);
        $bufEnd = new FileBuffer($this->cacheDir);
        self::assertSame([], $bufEnd->pending());
    }

    public function testIdempotentChangeIdStableAcrossReplay(): void
    {
        $source = new FakeSource($this->makeEvents(2));
        $run1 = [];
        $crash = function (Change $c) use (&$run1): void {
            $run1[] = [$c->id, $c->value];
            throw new \RuntimeException('CRASH'); // crash immediately → both stay pending
        };
        try {
            $this->pump($source)->processChanges($crash, maxRetries: 0, onError: 'halt');
        } catch (\RuntimeException) {
        }

        $run2 = [];
        $empty = new FakeSource([]);
        (new Pump(
            $this->config(),
            fn (int $l): array => $empty->fetch($l),
            $this->decryptChange(),
            sleep: fn (float $_s): null => null,
        ))->processChanges(function (Change $c) use (&$run2): void {
            $run2[] = [$c->id, $c->value];
        });

        self::assertSame('chg-0001', $run1[0][0]);
        self::assertSame(['chg-0001', $run1[0][1]], $run2[0]); // same id AND decrypted value
    }

    // ── (e) ciphertext at rest ───────────────────────────────────────────────

    public function testBufferFilesStoreCiphertextNotPlaintext(): void
    {
        $source = new FakeSource($this->makeEvents(2));
        $expected = self::$vector['text']['plaintext'];
        $wrapper = self::$vector['text']['wrapper'];

        try {
            $this->pump($source)->processChanges(function (Change $c): void {
                throw new \RuntimeException('STOP'); // crash immediately so files stay on disk
            }, maxRetries: 0, onError: 'halt');
        } catch (\RuntimeException) {
        }

        $pendingDir = $this->cacheDir . '/pending';
        $files = glob($pendingDir . '/*.json') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $path) {
            $raw = file_get_contents($path);
            self::assertNotFalse($raw);
            self::assertStringNotContainsString($expected, $raw); // no plaintext on disk
            $stored = json_decode($raw, true);
            self::assertSame(1, $stored['value']['_enc']);
            self::assertSame($wrapper['k'], $stored['value']['k']);
        }
    }

    // ── (f) returns when drained ─────────────────────────────────────────────

    public function testProcessChangesReturnsWhenSourceDrained(): void
    {
        $source = new FakeSource($this->makeEvents(5));
        $delivered = [];
        $this->pump($source)->processChanges(function (Change $c) use (&$delivered): void {
            $delivered[] = $c->id;
        }, batchSize: 2);
        self::assertSame(['chg-0001', 'chg-0002', 'chg-0003', 'chg-0004', 'chg-0005'], $delivered);
        self::assertSame([], $source->queue);
        self::assertSame(2, end($source->fetchCalls));
    }

    public function testEmptySourceReturnsImmediately(): void
    {
        $source = new FakeSource([]);
        $delivered = [];
        $this->pump($source)->processChanges(function (Change $c) use (&$delivered): void {
            $delivered[] = $c;
        });
        self::assertSame([], $delivered);
        self::assertSame([100], $source->fetchCalls); // one drain, default batch_size, got nothing
    }

    public function testBatchSizeClampedTo500(): void
    {
        $source = new FakeSource($this->makeEvents(1));
        $this->pump($source)->processChanges(fn (Change $c): null => null, batchSize: 9999);
        self::assertSame(500, max($source->fetchCalls));
    }

    // ── drain_batch primitive + dead-letter retry ─────────────────────────────

    public function testDrainBatchIsRawUnbuffered(): void
    {
        $source = new FakeSource($this->makeEvents(3));
        $pump = $this->pump($source);
        $batch = $pump->drainBatch(2);
        self::assertSame(['chg-0001', 'chg-0002'], array_map(fn ($c) => $c->id, $batch));
        $buf = new FileBuffer($this->cacheDir);
        self::assertSame([], $buf->pending()); // nothing buffered
        self::assertSame([2], $source->fetchCalls);
    }

    public function testDrainBatchClampedTo500(): void
    {
        $source = new FakeSource([]);
        $this->pump($source)->drainBatch(10000);
        self::assertSame([500], $source->fetchCalls);
    }

    public function testRetryDeadLettersRedrives(): void
    {
        $source = new FakeSource($this->makeEvents(2));
        $pump = $this->pump($source);
        $pump->processChanges(function (Change $c): void {
            if ($c->id === 'chg-0002') {
                throw new \RuntimeException('boom');
            }
        }, maxRetries: 1);

        $buf = new FileBuffer($this->cacheDir);
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], $buf->deadLetters()));

        $redriven = [];
        $pump->retryDeadLetters(function (Change $c) use (&$redriven): void {
            $redriven[] = $c->id;
        });
        self::assertSame(['chg-0002'], $redriven);
        self::assertSame([], (new FileBuffer($this->cacheDir))->deadLetters());
    }

    public function testRetryDeadLettersStillFailingStaysDeadletteredNeverPending(): void
    {
        $source = new FakeSource($this->makeEvents(2));
        $pump = $this->pump($source);
        $fail2 = function (Change $c): void {
            if ($c->id === 'chg-0002') {
                throw new \RuntimeException('boom');
            }
        };
        $pump->processChanges($fail2, maxRetries: 1);

        $buf = new FileBuffer($this->cacheDir);
        $dl0 = $buf->deadLetters();
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], $dl0));
        self::assertSame(2, $dl0[0]['attempts']); // 1 + max_retries
        $pendingDir = $this->cacheDir . '/pending';
        $deadletterDir = $this->cacheDir . '/deadletter';

        $redriven = $pump->retryDeadLetters($fail2, maxRetries: 2);
        self::assertSame(0, $redriven);

        $buf2 = new FileBuffer($this->cacheDir);
        $dl1 = $buf2->deadLetters();
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], $dl1));
        self::assertSame(3, $dl1[0]['attempts']); // 1 + the 2 re-drive attempts
        self::assertStringContainsString('boom', $dl1[0]['error']);
        self::assertSame([], $buf2->pending());
        self::assertSame([], $this->jsonFiles($pendingDir)); // not even a temp/leftover
        self::assertCount(1, $this->jsonFiles($deadletterDir)); // rewritten in place

        $ok = [];
        $again = $pump->retryDeadLetters(function (Change $c) use (&$ok): void {
            $ok[] = $c->id;
        });
        self::assertSame(1, $again);
        self::assertSame(['chg-0002'], $ok);
        self::assertSame([], (new FileBuffer($this->cacheDir))->deadLetters());
        self::assertSame([], (new FileBuffer($this->cacheDir))->pending());
    }

    public function testRetryDeadLettersAttemptsMonotonicAcrossRuns(): void
    {
        $source = new FakeSource($this->makeEvents(2));
        $pump = $this->pump($source);
        $fail2 = function (Change $c): void {
            if ($c->id === 'chg-0002') {
                throw new \RuntimeException('boom');
            }
        };
        $pump->processChanges($fail2, maxRetries: 3);
        $dl0 = (new FileBuffer($this->cacheDir))->deadLetters();
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], $dl0));
        self::assertSame(4, $dl0[0]['attempts']); // 1 + 3 retries

        // Re-drive with a SMALLER budget (run-local attempts = 1). The stored count
        // must stay clamped at the prior high-water mark of 4 (caveat 3).
        self::assertSame(0, $pump->retryDeadLetters($fail2, maxRetries: 0));
        $dl1 = (new FileBuffer($this->cacheDir))->deadLetters();
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], $dl1));
        self::assertSame(4, $dl1[0]['attempts']); // monotonic — NOT 1
    }

    // ── caveat 1: poison-decrypt does not wedge the stream ─────────────────────

    public function testPoisonDecryptDeadLettersWithoutWedging(): void
    {
        $decryptCalls = 0;
        $decryptChange = function (array $event) use (&$decryptCalls): Change {
            if (($event['id'] ?? null) === 'chg-0002') {
                $decryptCalls++;
                throw new DecryptError('corrupt ciphertext for chg-0002');
            }
            return Change::fromApi(
                $event,
                fn (string $s): ?string => 'text',
                fn (array|string $w): string => Crypto::decrypt($w, self::$key),
            );
        };

        $events = $this->makeEvents(1, 1);
        $events[] = self::makePoisonEvent('chg-0002');
        $events = array_merge($events, $this->makeEvents(1, 3));
        $source = new FakeSource($events);

        $delivered = [];
        $pump = new Pump(
            $this->config(),
            fn (int $l): array => $source->fetch($l),
            $decryptChange,
            sleep: fn (float $_s): null => null,
        );
        $pump->processChanges(function (Change $c) use (&$delivered): void {
            $delivered[] = $c->id;
        }, maxRetries: 3);

        self::assertSame(['chg-0001', 'chg-0003'], $delivered);
        self::assertSame(1, $decryptCalls); // dead-lettered immediately, no retries burned

        $buf = new FileBuffer($this->cacheDir);
        self::assertSame([], $buf->pending());
        $dl = $buf->deadLetters();
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], $dl));
        self::assertStringContainsString('DecryptError', $dl[0]['error']);
        self::assertSame(1, $dl[0]['attempts']);
        self::assertSame([], $this->jsonFiles($this->cacheDir . '/pending'));
        self::assertCount(1, $this->jsonFiles($this->cacheDir . '/deadletter'));

        // A fresh pump on the SAME cacheDir does NOT re-deliver the poison event.
        $delivered2 = [];
        (new Pump(
            $this->config(),
            fn (int $l): array => (new FakeSource([]))->fetch($l),
            $decryptChange,
            sleep: fn (float $_s): null => null,
        ))->processChanges(function (Change $c) use (&$delivered2): void {
            $delivered2[] = $c->id;
        });
        self::assertSame([], $delivered2);
        self::assertSame(['chg-0002'], array_map(fn ($d) => $d['id'], (new FileBuffer($this->cacheDir))->deadLetters()));
    }

    public function testPoisonDecryptWithHaltReraises(): void
    {
        $decryptChange = function (array $event): Change {
            if (($event['id'] ?? null) === 'chg-0001') {
                throw new DecryptError('undecryptable');
            }
            return Change::fromApi(
                $event,
                fn (string $s): ?string => 'text',
                fn (array|string $w): string => Crypto::decrypt($w, self::$key),
            );
        };
        $source = new FakeSource([self::makePoisonEvent('chg-0001')]);
        $pump = new Pump(
            $this->config(),
            fn (int $l): array => $source->fetch($l),
            $decryptChange,
            sleep: fn (float $_s): null => null,
        );
        try {
            $pump->processChanges(fn (Change $c): null => null, onError: 'halt');
            self::fail('expected DecryptError');
        } catch (DecryptError $e) {
            self::assertStringContainsString('undecryptable', $e->getMessage());
        }
        // The un-acked poison event survives in pending/ (halt left it for inspection).
        $buf = new FileBuffer($this->cacheDir);
        self::assertSame(['chg-0001'], array_map(fn ($e) => $e['id'], $buf->pending()));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function makePoisonEvent(string $id): array
    {
        return [
            'id' => $id,
            'event' => 'field_updated',
            'person_user_id' => 'person-x',
            'slug' => 'work_email',
            // A structurally-bogus wrapper → DecryptError at delivery (never on disk).
            'value' => ['_enc' => 1, 'k' => '@@notbase64@@', 'iv' => 'AAAA', 'd' => 'AAAA'],
            'live' => true,
            'at' => '2026-06-17T10:09:00Z',
        ];
    }

    /** @return list<string> */
    private function jsonFiles(string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $p) {
            $out[] = basename($p);
        }
        return $out;
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

/**
 * In-memory drain-on-fetch queue: fetch deletes exactly what it returns.
 */
final class FakeSource
{
    /** @var list<array<string,mixed>> */
    public array $queue;
    /** @var list<int> */
    public array $fetchCalls = [];

    /** @param list<array<string,mixed>> $events */
    public function __construct(array $events)
    {
        $this->queue = $events;
    }

    /** @return list<array<string,mixed>> */
    public function fetch(int $limit): array
    {
        $this->fetchCalls[] = $limit;
        $batch = array_slice($this->queue, 0, $limit);
        $this->queue = array_slice($this->queue, count($batch));
        return $batch;
    }
}
