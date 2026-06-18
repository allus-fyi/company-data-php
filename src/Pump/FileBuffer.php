<?php

declare(strict_types=1);

namespace Allus\CompanyData\Pump;

use Allus\CompanyData\Util\AtomicFile;

/**
 * Durable plain-file buffer for the crash-safe changes pump.
 *
 * The changes feed is a server-side **drain-on-fetch queue**: a fetch returns up
 * to N events and deletes those rows in the same transaction — the API keeps no
 * copy. So a drained batch MUST be persisted locally BEFORE any delivery, or a
 * consumer crash mid-batch loses events the API already deleted. This is that
 * persistence: a zero-dependency, plain-file buffer under {@code cache_dir}.
 *
 * Layout:
 *
 *     <cache_dir>/pending/<seq>_<change_id>.json     # one un-acked event, oldest-first
 *     <cache_dir>/deadletter/<seq>_<change_id>.json   # events that exhausted retries
 *
 * - The stored event is the **raw hardened API event dict** — its {@code value} /
 *   {@code value_url} is **CIPHERTEXT**, never the decrypted plaintext. No PII is
 *   ever written to disk.
 * - {@code <seq>} is a zero-padded, monotonically increasing sequence persisted in
 *   {@code <cache_dir>/.seq}. Because {@see append()} is called in drain order
 *   (oldest-first), sorting filenames lexicographically yields oldest-first.
 * - Writes are **crash-safe** ({@see AtomicFile}: temp → fsync → rename → dir
 *   fsync) — a crash never leaves a half-written pending file.
 * - {@see ack()} deletes the pending file; {@see deadLetter()} moves it to
 *   {@code deadletter/} with the error + attempt count. Neither re-fetches from
 *   the API (it already deleted the row) — the buffer is the only home.
 */
final class FileBuffer
{
    private const PENDING_DIR = 'pending';
    private const DEADLETTER_DIR = 'deadletter';
    private const SEQ_FILE = '.seq';

    /** Width of the zero-padded sequence prefix (sorts lexicographically). */
    private const SEQ_WIDTH = 16;

    private readonly string $pendingDir;
    private readonly string $deadletterDir;
    private readonly string $seqPath;

    public function __construct(private readonly string $cacheDir)
    {
        $this->pendingDir = $cacheDir . DIRECTORY_SEPARATOR . self::PENDING_DIR;
        $this->deadletterDir = $cacheDir . DIRECTORY_SEPARATOR . self::DEADLETTER_DIR;
        $this->seqPath = $cacheDir . DIRECTORY_SEPARATOR . self::SEQ_FILE;
        $this->ensureDir($this->pendingDir);
        $this->ensureDir($this->deadletterDir);
    }

    // ── append / list / ack ──────────────────────────────────────────────────

    /**
     * Persist a drained batch (oldest-first), each in its own fsync'd file.
     *
     * Each event is stored verbatim (ciphertext value intact). Returns the list
     * of pending filenames written. This is the backup the API no longer holds —
     * it MUST complete before the pump delivers anything.
     *
     * @param list<array<string,mixed>> $events
     *
     * @return list<string>
     */
    public function append(array $events): array
    {
        $written = [];
        foreach ($events as $event) {
            $seq = $this->nextSeq();
            $changeId = is_array($event) ? ($event['id'] ?? null) : null;
            $name = sprintf('%0' . self::SEQ_WIDTH . 'd_%s.json', $seq, self::sanitizeId($changeId));
            $path = $this->pendingDir . DIRECTORY_SEPARATOR . $name;
            AtomicFile::write($path, self::encode($event));
            $written[] = $name;
        }
        return $written;
    }

    /**
     * All un-acked events, oldest-first (by the sortable filename).
     *
     * @return list<array<string,mixed>>
     */
    public function pending(): array
    {
        $out = [];
        foreach ($this->pendingFiles() as $name) {
            $out[] = $this->readEvent($this->pendingDir, $name);
        }
        return $out;
    }

    /**
     * Delete the pending file for {@code $changeId} (the per-item ack). Idempotent.
     */
    public function ack(?string $changeId): bool
    {
        $name = $this->findPendingFile($changeId);
        if ($name === null) {
            return false;
        }
        $path = $this->pendingDir . DIRECTORY_SEPARATOR . $name;
        if (!@unlink($path)) {
            return false;
        }
        AtomicFile::fsyncDir($this->pendingDir);
        return true;
    }

    // ── dead-letter ────────────────────────────────────────────────────────────

    /**
     * Move a poison event from pending → deadletter with error + attempts.
     *
     * The event keeps its ciphertext value; the failure context is stored under a
     * reserved key so it is never silently dropped.
     *
     * **At-least-once (caveat 4):** the new dead-letter copy is written BEFORE the
     * pending copy is unlinked — a crash between them leaves the event in both
     * dirs → harmless re-delivery on replay (the id-dedup handler absorbs it).
     * Do NOT "fix" this by deleting-first.
     */
    public function deadLetter(?string $changeId, string $error, int $attempts): bool
    {
        $name = $this->findPendingFile($changeId);
        if ($name === null) {
            return false;
        }
        $event = $this->readEvent($this->pendingDir, $name);
        $record = $event;
        $record['_deadletter'] = ['error' => $error, 'attempts' => $attempts];
        // Write the dead-letter copy FIRST (never lose), then unlink pending.
        $dest = $this->deadletterDir . DIRECTORY_SEPARATOR . $name;
        AtomicFile::write($dest, self::encode($record));
        @unlink($this->pendingDir . DIRECTORY_SEPARATOR . $name);
        AtomicFile::fsyncDir($this->pendingDir);
        return true;
    }

    /**
     * All dead-lettered events, oldest-first.
     *
     * Each item is the stored (ciphertext) event with a flattened {@code error}
     * and {@code attempts} lifted out of the reserved {@code _deadletter} block,
     * plus the event's own {@code id} for convenience.
     *
     * @return list<array<string,mixed>>
     */
    public function deadLetters(): array
    {
        $out = [];
        foreach ($this->deadletterFiles() as $name) {
            $event = $this->readEvent($this->deadletterDir, $name);
            $meta = $event['_deadletter'] ?? [];
            $item = $event;
            $item['error'] = $meta['error'] ?? null;
            $item['attempts'] = $meta['attempts'] ?? null;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * Rewrite a dead-letter record IN PLACE with a refreshed error + attempts.
     *
     * Used by a still-failing re-drive ({@see Pump::retryDeadLetters()}): the
     * record stays in {@code deadletter/} and its failure context is updated
     * atomically ({@see AtomicFile} temp+fsync+rename within {@code deadletter/}).
     * It is NEVER routed back through {@code pending/}, so a crash anywhere in this
     * method leaves the record either as the old dead-letter or the new one — it
     * can never resurrect as a live pending event (caveat 2). Idempotent (returns
     * false if the record is gone). Preserves the file's seq prefix.
     *
     * The stored attempt count is **monotonic** across separate re-drive runs — a
     * later run with a smaller max_retries must never lower the recorded total — so
     * we clamp to {@code max(existing, new)} (caveat 3).
     */
    public function updateDeadLetter(?string $changeId, string $error, int $attempts): bool
    {
        $name = $this->findDeadletterFile($changeId);
        if ($name === null) {
            return false;
        }
        $path = $this->deadletterDir . DIRECTORY_SEPARATOR . $name;
        $event = $this->readEvent($this->deadletterDir, $name);

        $prior = ($event['_deadletter'] ?? [])['attempts'] ?? null;
        $priorAttempts = is_numeric($prior) ? (int) $prior : 0;

        $record = $event;
        unset($record['_deadletter'], $record['error'], $record['attempts']);
        $record['_deadletter'] = ['error' => $error, 'attempts' => max($priorAttempts, $attempts)];
        AtomicFile::write($path, self::encode($record)); // temp+fsync+rename within deadletter/
        return true;
    }

    /** Delete a dead-letter record (after a successful re-drive). Idempotent. */
    public function removeDeadLetter(?string $changeId): bool
    {
        $name = $this->findDeadletterFile($changeId);
        if ($name === null) {
            return false;
        }
        if (!@unlink($this->deadletterDir . DIRECTORY_SEPARATOR . $name)) {
            return false;
        }
        AtomicFile::fsyncDir($this->deadletterDir);
        return true;
    }

    // ── sequence ─────────────────────────────────────────────────────────────

    /**
     * Monotonic sequence, recovered from disk so it survives a restart. On a
     * fresh process we seed from the highest seq already present in either
     * directory, so replayed-then-newly-appended events keep ordering globally.
     */
    private function nextSeq(): int
    {
        $current = $this->readSeq();
        if ($current === null) {
            $current = $this->maxOnDiskSeq();
        }
        $next = $current + 1;
        AtomicFile::write($this->seqPath, (string) $next);
        return $next;
    }

    private function readSeq(): ?int
    {
        $raw = @file_get_contents($this->seqPath);
        if ($raw === false) {
            return null;
        }
        $raw = trim($raw);
        if (!ctype_digit($raw)) {
            return null;
        }
        return (int) $raw;
    }

    private function maxOnDiskSeq(): int
    {
        $best = 0;
        foreach ([$this->pendingDir, $this->deadletterDir] as $dir) {
            foreach (@scandir($dir) ?: [] as $name) {
                $seq = self::seqOf($name);
                if ($seq !== null && $seq > $best) {
                    $best = $seq;
                }
            }
        }
        return $best;
    }

    // ── file helpers ───────────────────────────────────────────────────────────

    /** @return list<string> */
    private function pendingFiles(): array
    {
        return $this->jsonFilesSorted($this->pendingDir);
    }

    /** @return list<string> */
    private function deadletterFiles(): array
    {
        return $this->jsonFilesSorted($this->deadletterDir);
    }

    /** @return list<string> */
    private function jsonFilesSorted(string $dir): array
    {
        $names = [];
        foreach (@scandir($dir) ?: [] as $name) {
            if (str_ends_with($name, '.json') && !str_starts_with($name, '.tmp_')) {
                $names[] = $name;
            }
        }
        sort($names, SORT_STRING); // zero-padded seq prefix → lexicographic == oldest-first
        return $names;
    }

    private function findPendingFile(?string $changeId): ?string
    {
        $target = self::sanitizeId($changeId);
        foreach ($this->pendingFiles() as $name) {
            if (self::idPart($name) === "{$target}.json") {
                return $name;
            }
        }
        return null;
    }

    private function findDeadletterFile(?string $changeId): ?string
    {
        $target = self::sanitizeId($changeId);
        foreach ($this->deadletterFiles() as $name) {
            if (self::idPart($name) === "{$target}.json") {
                return $name;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function readEvent(string $dir, string $name): array
    {
        $raw = file_get_contents($dir . DIRECTORY_SEPARATOR . $name);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $obj */
    private static function encode(array $obj): string
    {
        return json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
    }

    private static function sanitizeId(mixed $changeId): string
    {
        $s = $changeId !== null ? (string) $changeId : 'noid';
        $out = preg_replace('/[^A-Za-z0-9_-]/', '_', $s);
        return ($out === null || $out === '') ? 'noid' : $out;
    }

    /** The part after the leading "<seq>_" — i.e. "<sanitized_id>.json". */
    private static function idPart(string $name): string
    {
        $pos = strpos($name, '_');
        return $pos === false ? $name : substr($name, $pos + 1);
    }

    private static function seqOf(string $name): ?int
    {
        $pos = strpos($name, '_');
        $head = $pos === false ? $name : substr($name, 0, $pos);
        return ctype_digit($head) ? (int) $head : null;
    }
}
