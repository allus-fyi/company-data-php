<?php

declare(strict_types=1);

namespace Allus\Examples;

/**
 * Cross-request state for the whole example test suite — shared by all three scenario families (spec §3,
 * config-file amendment; one-server restructure).
 *
 * Single-worker server → requests serialize; there is NO concurrency to guard, so there are NO locks,
 * NO tombstones and NO burn-on-read. Everything lives under ONE {@see $runtimeDir} (git-ignored, wiped at
 * startup):
 *   - config/{sid}.json        — the canonical SDK config file a scenario runs OFF (written by
 *                                POST /api/scenarios/{id}/config from the browser settings; NOT TTL-swept)
 *   - config/{sid}.meta.json   — demo-only run parameters that are not SDK Config fields (authorize base,
 *                                one_time claims, share code, context, flow/connection ids, webhook id …)
 *   - config/keys/<sha1>.pem   — the private-key file(s) a config references by path (mode 0600)
 *   - runs/{runId}.json        — one run's PKCE/state/nonce/outcome (+ its family + public scenario id)
 *   - webhook-route.json       — the SINGLE active company-data webhook run {webhookId, runId} (spec §2)
 *   - state.json               — the setup snapshot POSTed to /api/state, held verbatim as OPAQUE cold
 *                                storage: never parsed here, never used to run anything
 *   - cache/                   — the SDK pump's buffer + dead-letters (company-data Config.cacheDir)
 *
 * Config files are keyed by the PUBLIC scenario id via {@see sid()} (e.g. "1", "flow:run",
 * "companydata:read") so the three families never collide in the one tree. Config files persist across
 * runs — they are configuration, not runs, so they are removed ONLY by a Clear or the startup wipe, never
 * by the TTL. Run files are written via write-temp + atomic rename (crash hygiene only) and removed by
 * their 30-minute TTL (lazy sweep on any request, which also collects orphaned *.tmp files), by Clear, or
 * by the startup wipe.
 */
final class Runtime
{
    /** 30-minute run TTL (seconds). Config files are exempt (they are configuration, not runs). */
    public const TTL = 1800;

    public readonly string $runtimeDir;
    public readonly string $runsDir;
    public readonly string $configDir;
    public readonly string $configKeysDir;
    public readonly string $cacheDir;
    public readonly string $routePath;
    public readonly string $statePath;

    public function __construct(string $baseDir)
    {
        $this->runtimeDir = $baseDir . '/.runtime';
        $this->runsDir = $this->runtimeDir . '/runs';
        $this->configDir = $this->runtimeDir . '/config';
        $this->configKeysDir = $this->configDir . '/keys';
        // The company-data SDK pump persists its buffer + dead-letters here (Config.cacheDir → this path),
        // so Clear / the startup wipe removes it and the "writes only under .runtime/" property holds.
        $this->cacheDir = $this->runtimeDir . '/cache';
        $this->routePath = $this->runtimeDir . '/webhook-route.json';
        $this->statePath = $this->runtimeDir . '/state.json';
    }

    /** Ensure the runtime directories exist (idempotent). */
    public function ensureDirs(): void
    {
        foreach ([$this->runtimeDir, $this->runsDir, $this->configDir, $this->configKeysDir, $this->cacheDir] as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0700, true);
            }
        }
    }

    /** Startup wipe: remove ALL runtime state (configs + keys + runs + cache + route), then recreate. */
    public function wipeAll(): void
    {
        $this->rmTree($this->runtimeDir);
        $this->ensureDirs();
    }

    // ── lazy TTL sweep ──────────────────────────────────────────────────────

    /**
     * Remove expired run files and orphaned *.tmp files. Called on every request (spec §3). When the
     * active webhook run expires, its routing record is dropped too (a stale record never routes to a
     * burned run). Config files carry NO TTL — they are wiped only at startup or by Clear.
     */
    public function sweep(): void
    {
        $now = time();
        foreach (glob($this->runsDir . '/*') ?: [] as $path) {
            if (str_ends_with($path, '.tmp')) {
                @unlink($path); // orphaned temp from an interrupted write
                continue;
            }
            if (str_ends_with($path, '.json') && ($now - (int) @filemtime($path)) > self::TTL) {
                @unlink($path);
            }
        }
        // Drop the routing record if its run is gone (expired/swept above).
        $route = $this->readRoute();
        if ($route !== null && !is_file($this->runsDir . '/' . $route['runId'] . '.json')) {
            @unlink($this->routePath);
        }
    }

    // ── config files (amendment) ─────────────────────────────────────────────

    /** Filesystem-safe token for a scenario's public id (e.g. "companydata:read" → "companydata_read"). */
    public static function sid(string $scenarioId): string
    {
        $tok = preg_replace('/[^a-z0-9]+/i', '_', $scenarioId) ?? '';
        return trim($tok, '_');
    }

    /** Absolute path of a scenario's canonical SDK config file. */
    public function configPathFor(string $scenarioId): string
    {
        return $this->configDir . '/' . self::sid($scenarioId) . '.json';
    }

    /** Absolute path of a scenario's demo-only meta sidecar. */
    public function metaPathFor(string $scenarioId): string
    {
        return $this->configDir . '/' . self::sid($scenarioId) . '.meta.json';
    }

    public function hasConfig(string $scenarioId): bool
    {
        return is_file($this->configPathFor($scenarioId));
    }

    /**
     * Write a scenario's canonical SDK config file (spec §3 config endpoint). Atomic write-temp +
     * rename. Returns the RELATIVE path (for display/inspection in the setup panel).
     *
     * @param array<string,mixed> $config the canonical SDK config shape (snake_case keys, sdk.html §2/§12c)
     */
    public function writeConfig(string $scenarioId, array $config): string
    {
        $this->ensureDirs();
        $this->atomicWrite($this->configPathFor($scenarioId), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return '.runtime/config/' . self::sid($scenarioId) . '.json';
    }

    /**
     * Write a scenario's demo-only meta sidecar — the run parameters that are NOT SDK Config fields, so
     * they stay out of the canonical config file.
     *
     * @param array<string,mixed> $meta
     */
    public function writeConfigMeta(string $scenarioId, array $meta): void
    {
        $this->ensureDirs();
        $this->atomicWrite($this->metaPathFor($scenarioId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read a scenario's meta sidecar; {} when absent.
     *
     * @return array<string,mixed>
     */
    public function readConfigMeta(string $scenarioId): array
    {
        $raw = @file_get_contents($this->metaPathFor($scenarioId));
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The decoded canonical config file for a scenario ({} if none).
     *
     * @return array<string,mixed>
     */
    public function readConfig(string $scenarioId): array
    {
        $raw = @file_get_contents($this->configPathFor($scenarioId));
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Materialize a browser-sent PEM to config/keys/<sha1>.pem (0600) and return its ABSOLUTE path —
     * the value recorded in the config file (the SDK reads keys by path via file_get_contents).
     * Content-addressed: identical PEM reuses the same file, so two scenarios sharing a key share the
     * file. Removed only by Clear or the startup wipe (never TTL).
     */
    public function materializeConfigKey(string $pem): string
    {
        $this->ensureDirs();
        $path = $this->configKeysDir . '/' . sha1($pem) . '.pem';
        if (!is_file($path)) {
            $this->atomicWrite($path, $pem, 0600);
        }
        @chmod($path, 0600);
        return $path;
    }

    // ── runs ────────────────────────────────────────────────────────────────

    public function newRunId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Write a run atomically (write-temp + rename). A reader never sees a partial file.
     *
     * @param array<string,mixed> $data
     */
    public function writeRun(string $runId, array $data): void
    {
        $data['runId'] = $runId;
        $this->atomicWrite($this->runsDir . '/' . $runId . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read a run, honouring the TTL. Returns null for unknown/expired ids (idempotent reads —
     * an outcome, once written, is returned on every poll until TTL/Clear removes it).
     *
     * @return array<string,mixed>|null
     */
    public function readRun(string $runId): ?array
    {
        if (!self::isRunId($runId)) {
            return null;
        }
        $path = $this->runsDir . '/' . $runId . '.json';
        if (!is_file($path)) {
            return null;
        }
        if ((time() - (int) @filemtime($path)) > self::TTL) {
            @unlink($path);
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ── webhook routing record (spec §2 — single active company-data webhook run) ──

    /**
     * Persist the single active webhook route {webhookId, runId}, superseding any prior one. A new
     * companydata:webhook run calls this on /start; the old run stops receiving (its file stays readable
     * until TTL/Clear).
     */
    public function writeRoute(string $webhookId, string $runId): void
    {
        $this->ensureDirs();
        $this->atomicWrite($this->routePath, json_encode(['webhookId' => $webhookId, 'runId' => $runId], JSON_UNESCAPED_SLASHES));
    }

    /**
     * The active webhook route, or null when none is set.
     *
     * @return array{webhookId:string,runId:string}|null
     */
    public function readRoute(): ?array
    {
        $raw = @file_get_contents($this->routePath);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['webhookId'], $decoded['runId'])) {
            return null;
        }
        return ['webhookId' => (string) $decoded['webhookId'], 'runId' => (string) $decoded['runId']];
    }

    public function clearRoute(): void
    {
        @unlink($this->routePath);
    }

    // ── the setup snapshot (POST/GET /api/state) ──────────────────────────────

    /**
     * Store the setup snapshot the request carried, VERBATIM. The bytes are OPAQUE here — never
     * parsed, never inspected, never used to run anything — so nothing in this class constrains what
     * they may contain, and an empty body is a snapshot like any other. Carries no TTL (it is setup,
     * not a run); removed by a global clear or the startup wipe.
     */
    public function writeState(string $blob): void
    {
        $this->ensureDirs();
        $this->atomicWrite($this->statePath, $blob);
    }

    /**
     * The stored snapshot's bytes, or null when NO snapshot file exists — the file's presence is the
     * whole of the answer, since judging the content would be the inspection this store does not do.
     * A file that exists but cannot be read is a fault, not an absence, so it is raised rather than
     * reported as "nothing saved".
     */
    public function readState(): ?string
    {
        if (!is_file($this->statePath)) {
            return null;
        }
        $raw = @file_get_contents($this->statePath);
        if ($raw === false) {
            throw new \RuntimeException('could not read the saved setup snapshot at ' . $this->statePath);
        }
        return $raw;
    }

    public function clearState(): void
    {
        @unlink($this->statePath);
    }

    // ── clear ────────────────────────────────────────────────────────────────

    /**
     * Per-scenario clear (spec §3): delete that scenario's run files (matched on the run's public scenario
     * id) AND its config + meta files, then garbage-collect any key PEM no surviving config still
     * references. Clearing the webhook scenario also drops the routing record + pump cache.
     */
    public function clearScenario(string $scenarioId): void
    {
        foreach (glob($this->runsDir . '/*.json') ?: [] as $path) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded) && (string) ($decoded['scenario'] ?? '') === $scenarioId) {
                @unlink($path);
            }
        }
        @unlink($this->configPathFor($scenarioId));
        @unlink($this->metaPathFor($scenarioId));
        if (str_starts_with($scenarioId, 'companydata:')) {
            // Only the company-data family uses the pump cache + webhook route.
            if ($scenarioId === 'companydata:webhook') {
                $this->clearRoute();
            }
            $this->rmTree($this->cacheDir);
        }
        $this->gcConfigKeys();
        $this->ensureDirs();
    }

    /**
     * Global clear: wipe all run files, the config tree (configs, metas, keys), the route + pump cache,
     * and the saved setup snapshot. The snapshot goes too because it can hold the same credentials the
     * config tree does — a clear that left it behind would leave those sitting on disk.
     */
    public function clearAll(): void
    {
        foreach (glob($this->runsDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        $this->rmTree($this->configDir);
        $this->rmTree($this->cacheDir);
        $this->clearRoute();
        $this->clearState();
        $this->ensureDirs();
    }

    /**
     * Delete any key PEM in config/keys that no surviving config/{sid}.json references by path.
     * Robust to content-addressed sharing: a key survives as long as ANY config points at it.
     */
    private function gcConfigKeys(): void
    {
        $referenced = [];
        foreach (glob($this->configDir . '/*.json') ?: [] as $cfgPath) {
            if (str_ends_with($cfgPath, '.meta.json')) {
                continue;
            }
            $decoded = json_decode((string) @file_get_contents($cfgPath), true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (['oauth_private_key', 'service_private_key'] as $keyField) {
                $p = $decoded[$keyField] ?? null;
                if (is_string($p) && $p !== '') {
                    $referenced[$p] = true;
                }
            }
        }
        foreach (glob($this->configKeysDir . '/*.pem') ?: [] as $keyPath) {
            if (!isset($referenced[$keyPath])) {
                @unlink($keyPath);
            }
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    public static function isRunId(string $s): bool
    {
        return $s !== '' && (bool) preg_match('/^[0-9a-f]{32}$/', $s);
    }

    /** Write-temp + atomic rename on the same filesystem (crash hygiene: no partial reads). */
    private function atomicWrite(string $finalPath, string $contents, ?int $mode = null): void
    {
        $tmp = $finalPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $contents);
        if ($mode !== null) {
            @chmod($tmp, $mode);
        }
        rename($tmp, $finalPath);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── the "what just happened" trace ────────────────────────────────────────

    /**
     * Append a call name to a run's trace, preserving first-occurrence order and skipping a repeat.
     * ONE implementation for all three families (standards §1): several handlers can run twice for one
     * run — /callback carries no already-completed guard, and the flow / company-data poll loops
     * legitimately re-attempt the same call on every poll — so an unconditional append writes the same
     * line again. The trace must read as what the run DID.
     *
     * **RECORD AT ATTEMPT TIME: call this IMMEDIATELY BEFORE the SDK call it names, never after.**
     * A run that ends `failed` is still a run the panel reports, and the call the reader most needs to
     * see is the one that threw — a bad client secret, a 429, a decrypt failure. An append placed after
     * the call is skipped by the very exception the reader is trying to understand, so the panel would
     * say only that the client was constructed. This is the same under-reporting this rule exists to
     * remove, one path further in; the rule is the invariant, not a per-scenario habit. A bulk call records one
     * entry per attempt, so a partial run shows exactly how far it got.
     *
     * @param list<string>|array<int,mixed> $calls
     * @return list<string>
     */
    public static function addCall(array $calls, string $name): array
    {
        $calls = array_map('strval', array_values($calls));
        if (!in_array($name, $calls, true)) {
            $calls[] = $name;
        }
        return $calls;
    }

    /**
     * {@see addCall()} against a run array in place. Returns true when the name was newly added, so the
     * caller can persist on that transition.
     *
     * @param array<string,mixed> $run
     */
    public static function recordCall(array &$run, string $name): bool
    {
        $before = (array) ($run['calls'] ?? []);
        $run['calls'] = self::addCall($before, $name);
        return count((array) $run['calls']) !== count($before);
    }
}
