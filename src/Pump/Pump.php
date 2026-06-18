<?php

declare(strict_types=1);

namespace Allus\CompanyData\Pump;

use Allus\CompanyData\Config;
use Allus\CompanyData\Errors\DecryptError;
use Allus\CompanyData\Model\Change;

/**
 * Crash-safe streaming changes pump.
 *
 * The changes feed is a server-side **drain-on-fetch queue**: a fetch returns up
 * to N events (default 100, max 500) and deletes those rows in the same
 * transaction — the API keeps no copy. So consumption cannot be a plain list: a
 * consumer crash mid-batch would lose events the API already deleted, and a huge
 * backlog must not materialize in memory. The pump solves both:
 *
 *     processChanges(handler) — one Change at a time, until the feed is empty,
 *                               then RETURNS. No follow/daemon mode (you schedule
 *                               re-runs yourself).
 *
 * Per cycle:
 *   1. Replay first  — deliver any un-acked buffered events (a crashed run), oldest-first.
 *   2. Drain         — when empty, fetch ONE batch (≤ batchSize, ≤500) and PERSIST it
 *                      to the durable buffer (fsync) BEFORE handing anything out.
 *   3. Deliver       — for each buffered event oldest-first: decrypt at delivery, call handler.
 *   4. Ack/retry/DL  — success acks; error retries up to maxRetries then dead-letters
 *                      (deadletter) or re-raises (halt). One poison never wedges the stream.
 *   5. Repeat until a drain returns empty AND the buffer is drained → return.
 *
 * Crash safety + at-least-once + idempotency: a batch is durably buffered before
 * any delivery, and acked per-item only after the handler succeeds. A crash
 * between a handler's success and its ack re-delivers on restart, so the handler
 * MUST be idempotent — every {@see Change} carries a stable {@code id}.
 *
 * Injection (so tests + the real Client share one pump): {@code fetchChanges(limit)}
 * is the raw drain-on-fetch call (returns ciphertext event dicts) and {@code decrypt(event)}
 * builds a typed {@see Change} (closes over the loaded service key — config-only).
 * No key/secret is ever a method argument.
 */
final class Pump
{
    /** The drain-on-fetch queue caps a fetch at 500. */
    public const MAX_BATCH = 500;
    private const DEFAULT_BATCH = 100;

    private const DEFAULT_MAX_RETRIES = 3;
    private const DEFAULT_BACKOFF_S = 0.5;
    private const MAX_BACKOFF_S = 30.0;

    private readonly FileBuffer $buffer;
    private readonly Logger $log;
    /** @var callable(float): void */
    private $sleep;
    /** @var callable(int): list<array<string,mixed>> */
    private $fetchChanges;
    /** @var callable(array<string,mixed>): Change */
    private $decrypt;

    /**
     * @param callable(int): list<array<string,mixed>> $fetchChanges drain-and-return up to N raw event dicts.
     * @param callable(array<string,mixed>): Change     $decrypt      raw event dict → typed Change.
     * @param callable(float): void|null                $sleep        injectable for tests.
     */
    public function __construct(
        Config $config,
        callable $fetchChanges,
        callable $decrypt,
        ?Logger $logger = null,
        ?callable $sleep = null,
    ) {
        $this->fetchChanges = $fetchChanges;
        $this->decrypt = $decrypt;
        $this->log = $logger ?? new NullLogger();
        $this->sleep = $sleep ?? static function (float $s): void {
            if ($s > 0) {
                usleep((int) round($s * 1_000_000));
            }
        };
        // The buffer recovers whatever is already on disk — that recovery IS the
        // replay-on-restart in step 1.
        $this->buffer = new FileBuffer($config->cacheDir);
    }

    public function buffer(): FileBuffer
    {
        return $this->buffer;
    }

    // ── the pump ──────────────────────────────────────────────────────────────

    /**
     * Stream events through {@code $handler} until the feed is empty, then return.
     *
     * {@code $handler} is called with one typed {@see Change} at a time and must be
     * idempotent (at-least-once delivery; dedup on {@code Change->id}).
     *
     * @param callable(Change): void $handler
     * @param callable(int): float|null $backoff attempt(1-based) → seconds.
     */
    public function processChanges(
        callable $handler,
        int $batchSize = self::DEFAULT_BATCH,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        string $onError = 'deadletter',
        ?callable $backoff = null,
    ): void {
        if ($onError !== 'deadletter' && $onError !== 'halt') {
            throw new \InvalidArgumentException("onError must be 'deadletter' or 'halt'");
        }
        $backoff ??= self::defaultBackoff(...);
        $size = self::clampBatch($batchSize);

        while (true) {
            // 1. Replay anything already buffered (a previous crashed run), then
            //    deliver it. If the buffer is empty, drain ONE batch first.
            $pending = $this->buffer->pending();
            if ($pending !== []) {
                $this->log->info(sprintf('pump replay: %d buffered event(s)', count($pending)));
            } else {
                $drained = $this->drainIntoBuffer($size);
                if ($drained === 0) {
                    // A drain returned empty AND the buffer is drained → done.
                    return;
                }
                $pending = $this->buffer->pending();
            }

            // 3+4. Deliver each buffered event oldest-first; ack/retry/dead-letter.
            foreach ($pending as $event) {
                $this->deliverOne($event, $handler, $maxRetries, $onError, $backoff);
            }
            // Loop: re-check the buffer (now drained) and try another drain.
        }
    }

    /**
     * Fetch one batch and PERSIST it to the buffer before any delivery.
     * Returns the number of events drained (0 means the feed is empty).
     */
    private function drainIntoBuffer(int $size): int
    {
        $batch = ($this->fetchChanges)($size) ?: [];
        $this->log->info(sprintf('pump drain: fetched %d event(s) (limit=%d)', count($batch), $size));
        if ($batch === []) {
            return 0;
        }
        // Persist-before-deliver: the durable backup the API no longer has.
        $this->buffer->append($batch);
        return count($batch);
    }

    /**
     * Decrypt at delivery, call the handler, then ack / retry / dead-letter.
     *
     * The decrypt happens INSIDE the delivery attempt (not before the loop) so a
     * {@see DecryptError} on a persisted poison event (corrupt/truncated
     * ciphertext, rotated key) is handled like a failure instead of propagating
     * out of {@see processChanges()} and wedging the stream on replay (caveat 1).
     * Re-decrypting can't fix it, so a DecryptError is dead-lettered IMMEDIATELY —
     * it does NOT burn maxRetries (with onError='halt' it re-raises).
     *
     * @param array<string,mixed> $event
     * @param callable(Change): void $handler
     * @param callable(int): float $backoff
     */
    private function deliverOne(
        array $event,
        callable $handler,
        int $maxRetries,
        string $onError,
        callable $backoff,
    ): void {
        $changeId = isset($event['id']) ? (string) $event['id'] : null;
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                // Decrypt only now — never on disk (ciphertext at rest).
                // Inside the try so a poison-ciphertext DecryptError is contained.
                $change = ($this->decrypt)($event);
                $this->log->debug(sprintf('pump deliver: id=%s attempt=%d', (string) $changeId, $attempts));
                $handler($change);
            } catch (DecryptError $exc) {
                // A poison event: re-decrypting won't help, so don't burn retries.
                if ($onError === 'halt') {
                    $this->log->error(sprintf('pump halt: id=%s undecryptable (%s)', (string) $changeId, $exc->getMessage()));
                    throw $exc;
                }
                $this->buffer->deadLetter($changeId, 'DecryptError: ' . $exc->getMessage(), $attempts);
                $this->log->error(sprintf('pump dead-letter (undecryptable): id=%s: %s', (string) $changeId, $exc->getMessage()));
                return;
            } catch (\Throwable $exc) {
                if ($attempts <= $maxRetries) {
                    $delay = max(0.0, $backoff($attempts));
                    $this->log->warning(sprintf(
                        'pump retry: id=%s attempt=%d failed (%s); backoff %.3fs',
                        (string) $changeId,
                        $attempts,
                        $exc->getMessage(),
                        $delay,
                    ));
                    if ($delay > 0) {
                        ($this->sleep)($delay);
                    }
                    continue;
                }
                // Retries exhausted.
                if ($onError === 'halt') {
                    $this->log->error(sprintf('pump halt: id=%s failed after %d attempt(s)', (string) $changeId, $attempts));
                    throw $exc;
                }
                $this->buffer->deadLetter($changeId, $exc->getMessage(), $attempts);
                $this->log->error(sprintf('pump dead-letter: id=%s after %d attempt(s): %s', (string) $changeId, $attempts, $exc->getMessage()));
                return;
            }
            // Success → per-item ack (remove from the buffer).
            $this->buffer->ack($changeId);
            $this->log->debug(sprintf('pump ack: id=%s', (string) $changeId));
            return;
        }
    }

    // ── advanced primitive ─────────────────────────────────────────────────────

    /**
     * Raw, UNBUFFERED drain → a list of typed Changes (advanced).
     *
     * Fetches one batch (clamped ≤500) and returns the decrypted Changes directly —
     * it does NOT persist anything to the buffer, so **you own durability** if you
     * use it. Prefer {@see processChanges()} for safe consumption.
     *
     * @return list<Change>
     */
    public function drainBatch(int $max = self::DEFAULT_BATCH): array
    {
        $size = self::clampBatch($max);
        $batch = ($this->fetchChanges)($size) ?: [];
        $this->log->info(sprintf('drainBatch: fetched %d event(s) (limit=%d)', count($batch), $size));
        $out = [];
        foreach ($batch as $event) {
            $out[] = ($this->decrypt)($event);
        }
        return $out;
    }

    // ── dead-letter inspect / re-drive ─────────────────────────────────────────

    /**
     * The local dead-letter store (ciphertext + error + attempt count).
     *
     * @return list<array<string,mixed>>
     */
    public function deadLetters(): array
    {
        return $this->buffer->deadLetters();
    }

    /**
     * Re-drive every dead-lettered event through {@code $handler}.
     *
     * On success the dead-letter record is removed; on repeated failure it is
     * updated IN PLACE within {@code deadletter/} (caveat 2 — never routed back
     * through {@code pending/}), or the error re-raises (halt). They are never
     * re-fetched from the API. Returns the count successfully re-driven.
     *
     * @param callable(Change): void $handler
     * @param callable(int): float|null $backoff
     */
    public function retryDeadLetters(
        callable $handler,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        string $onError = 'deadletter',
        ?callable $backoff = null,
    ): int {
        if ($onError !== 'deadletter' && $onError !== 'halt') {
            throw new \InvalidArgumentException("onError must be 'deadletter' or 'halt'");
        }
        $backoff ??= self::defaultBackoff(...);

        $redriven = 0;
        foreach ($this->buffer->deadLetters() as $record) {
            $changeId = isset($record['id']) ? (string) $record['id'] : null;
            // Strip the reserved failure block before re-decrypting the event.
            $event = $record;
            unset($event['_deadletter'], $event['error'], $event['attempts']);
            $attempts = 0;
            while (true) {
                $attempts++;
                try {
                    // Decrypt inside the loop so an undecryptable dead-letter
                    // (the poison case) is contained here too — it updates its own
                    // record in place instead of crashing the re-drive.
                    $change = ($this->decrypt)($event);
                    $handler($change);
                } catch (DecryptError $exc) {
                    if ($onError === 'halt') {
                        $this->log->error(sprintf('retryDeadLetters halt: id=%s undecryptable (%s)', (string) $changeId, $exc->getMessage()));
                        throw $exc;
                    }
                    $this->buffer->updateDeadLetter($changeId, 'DecryptError: ' . $exc->getMessage(), $attempts);
                    $this->log->warning(sprintf('retryDeadLetters: id=%s still undecryptable (%s)', (string) $changeId, $exc->getMessage()));
                    break;
                } catch (\Throwable $exc) {
                    if ($attempts <= $maxRetries) {
                        $delay = max(0.0, $backoff($attempts));
                        if ($delay > 0) {
                            ($this->sleep)($delay);
                        }
                        continue;
                    }
                    if ($onError === 'halt') {
                        $this->log->error(sprintf('retryDeadLetters halt: id=%s failed again', (string) $changeId));
                        throw $exc;
                    }
                    // Refresh the stored attempt count + error IN PLACE — the record
                    // stays in deadletter/ and never re-enters pending/ (caveat 2).
                    $this->buffer->updateDeadLetter($changeId, $exc->getMessage(), $attempts);
                    $this->log->warning(sprintf('retryDeadLetters: id=%s still failing (%s)', (string) $changeId, $exc->getMessage()));
                    break;
                }
                $this->buffer->removeDeadLetter($changeId);
                $this->log->info(sprintf('retryDeadLetters: id=%s re-driven OK', (string) $changeId));
                $redriven++;
                break;
            }
        }
        return $redriven;
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** Exponential backoff (capped) for the attempt-th retry (1-based). */
    private static function defaultBackoff(int $attempt): float
    {
        return min(self::DEFAULT_BACKOFF_S * (2 ** ($attempt - 1)), self::MAX_BACKOFF_S);
    }

    /** Clamp a requested batch size into [1, MAX_BATCH]. */
    public static function clampBatch(int $value): int
    {
        if ($value < 1) {
            return 1;
        }
        if ($value > self::MAX_BATCH) {
            return self::MAX_BATCH;
        }
        return $value;
    }
}
