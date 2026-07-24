<?php

declare(strict_types=1);

namespace Allus\CompanyDataExample;

/**
 * Cross-request state for the company-data demo backend (spec §3, config-file amendment).
 *
 * Single-worker server → requests serialize; there is NO concurrency to guard, so there are NO locks,
 * NO tombstones and NO burn-on-read. Everything lives under {@see $runtimeDir} (git-ignored, wiped at
 * startup):
 *   - config/{sid}.json        — the canonical SDK config file a scenario runs OFF (written by
 *                                POST /api/scenarios/{id}/config from the browser settings; NOT TTL-swept)
 *   - config/{sid}.meta.json   — demo-only run parameters that are not SDK Config fields (a documents
 *                                target share_code; the webhook id)
 *   - config/keys/<sha1>.pem   — the service private-key file(s) a config references by path (mode 0600)
 *   - runs/{runId}.json        — one run's accumulated result (events / rows / docs) + calls
 *   - webhook-route.json       — the SINGLE active webhook run: {webhookId, runId} (spec §2). A new
 *                                companydata:webhook run supersedes it; TTL/Clear of the run drops it.
 *   - cache/                   — the SDK pump's buffer + dead-letter dir (Config.cacheDir), wiped by Clear
 *
 * {@see $sid} is the scenario's string id (e.g. "companydata:read"); the config/meta filenames use a
 * filesystem-safe token derived from it. Config files persist across runs — they are configuration, not
 * runs, so they are removed ONLY by a Clear or the startup wipe, never by the TTL. Run files are written
 * via write-temp + atomic rename (crash hygiene only) and removed by their 30-minute TTL (lazy sweep on
 * any request, which also collects orphaned *.tmp files), by Clear, or by the startup wipe.
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

    public function __construct(string $baseDir)
    {
        $this->runtimeDir = $baseDir . '/.runtime';
        $this->runsDir = $this->runtimeDir . '/runs';
        $this->configDir = $this->runtimeDir . '/config';
        $this->configKeysDir = $this->configDir . '/keys';
        // The SDK pump persists its buffer + dead-letters here (Config.cacheDir → this path), so Clear /
        // the startup wipe removes it and the Phase-3 "writes only under .runtime/" check holds.
        $this->cacheDir = $this->runtimeDir . '/cache';
        $this->routePath = $this->runtimeDir . '/webhook-route.json';
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

    /** Filesystem-safe token for a scenario's string id (e.g. "companydata:read" → "companydata_read"). */
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
     * @param array<string,mixed> $config the canonical SDK config shape (snake_case keys, sdk.html §2)
     */
    public function writeConfig(string $scenarioId, array $config): string
    {
        $this->ensureDirs();
        $this->atomicWrite($this->configPathFor($scenarioId), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return '.runtime/config/' . self::sid($scenarioId) . '.json';
    }

    /**
     * Write a scenario's demo-only meta sidecar (share_code, webhook id) — the run parameters that are
     * NOT SDK Config fields, so they stay out of the canonical config file.
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
     * Materialize a browser-sent PEM to config/keys/<sha1>.pem (0600) and return its ABSOLUTE path —
     * the value recorded in the config file (the SDK reads keys by path via file_get_contents).
     * Content-addressed: identical PEM reuses the same file. Removed only by Clear or the startup wipe.
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

    // ── webhook routing record (spec §2 — single active webhook run) ────────────

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

    // ── clear ────────────────────────────────────────────────────────────────

    /**
     * Per-scenario clear (spec §3): delete that scenario's run files AND its config + meta files, then
     * garbage-collect any key PEM no surviving config still references (keys are content-addressed and
     * may be shared). Clearing the webhook scenario also drops the routing record; clearing anything
     * wipes the shared pump cache dir (cheap, single-worker).
     *
     * @param list<string> $allScenarioIds all known scenario ids (to gc runs by string scenario)
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
        if ($scenarioId === 'companydata:webhook') {
            $this->clearRoute();
        }
        $this->rmTree($this->cacheDir);
        $this->gcConfigKeys();
        $this->ensureDirs();
    }

    /** Global clear: wipe all run files, the config tree (configs, metas, keys), the route + pump cache. */
    public function clearAll(): void
    {
        foreach (glob($this->runsDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        $this->rmTree($this->configDir);
        $this->rmTree($this->cacheDir);
        $this->clearRoute();
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
            $p = $decoded['service_private_key'] ?? null;
            if (is_string($p) && $p !== '') {
                $referenced[$p] = true;
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
}
