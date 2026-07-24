<?php

declare(strict_types=1);

/**
 * One-command launcher for the company-data example (spec §2, §3; plan Phase 1/2).
 *
 * Steps:
 *   1. wipe .runtime/ (fresh state each boot)
 *   2. composer install if vendor/ is missing
 *   3. on a missing bundle: fetch the pinned frontend release (frontend.lock), VERIFY sha256, unpack to
 *      .frontend/<tag>/ (a present, verified bundle is a cache hit — nothing is re-fetched)
 *   4. assert the bundle's contract.json version == the backend's implemented contractVersion
 *   5. refuse a busy port with a clear message
 *   6. exec `php -S localhost:${PORT:-8091} router.php` — SINGLE WORKER (PHP_CLI_SERVER_WORKERS unset)
 */

const CONTRACT_VERSION = 3; // must equal Server::CONTRACT_VERSION
const RELEASE_BASE = 'https://github.com/allme-sdk/example-test-suite/releases/download';

$base = dirname(__DIR__);
chdir($base);

fwrite(STDERR, "company-data example — starting up\n");

// 1. fresh runtime state
rrmdir($base . '/.runtime');
@mkdir($base . '/.runtime', 0700, true);

// 2. dependencies
if (!is_file($base . '/vendor/autoload.php')) {
    fwrite(STDERR, "installing dependencies (composer install)…\n");
    passthru('composer install --no-interaction', $rc);
    if ($rc !== 0 || !is_file($base . '/vendor/autoload.php')) {
        fail("composer install failed — install Composer and retry.");
    }
}

// 3. frontend bundle (pinned release, checksum-verified, TAG-specific cache — spec §2/§8)
$lock = json_decode((string) @file_get_contents($base . '/frontend.lock'), true);
if (!is_array($lock) || !isset($lock['tag'], $lock['sha256'])) {
    fail("frontend.lock missing or malformed (need {\"tag\",\"sha256\"}).");
}
$tag = (string) $lock['tag'];
$wantSha = strtolower((string) $lock['sha256']);
$frontend = $base . '/.frontend/' . $tag;   // per-tag cache dir — a pin bump to a new tag serves a NEW dir
// A cache-hit is valid ONLY when the extracted bundle's recorded checksum matches THIS lock's sha256.
// So a pin bump (tag change) OR a tampered sha (same tag, different sha) both force a re-fetch + re-verify.
$markSha = strtolower(trim((string) (@file_get_contents($frontend . '/.sha') ?: '')));
$cacheValid = is_file($frontend . '/index.html')
    && is_file($frontend . '/contract.json')
    && $markSha !== '' && hash_equals($wantSha, $markSha);
if ($cacheValid) {
    fwrite(STDERR, "frontend {$tag} present + checksum-verified (cache hit) — skipping fetch\n");
} else {
    fetchBundle($base, $frontend, $tag, $wantSha);
}

// 4. contract guard
$bundleContract = json_decode((string) @file_get_contents($frontend . '/contract.json'), true);
$bundleVersion = is_array($bundleContract) ? ($bundleContract['contractVersion'] ?? null) : null;
if ((int) $bundleVersion !== CONTRACT_VERSION) {
    fail(sprintf(
        "contract mismatch: bundle contractVersion=%s, backend implements %d.\n"
        . "Bump the frontend.lock pin to a release whose contract.json matches, or update the backend.",
        var_export($bundleVersion, true),
        CONTRACT_VERSION,
    ));
}

// 5. port
$port = (int) (getenv('PORT') ?: 8091);
$sock = @stream_socket_server("tcp://localhost:{$port}", $errno, $errstr);
if ($sock === false) {
    fail("port {$port} is busy ({$errstr}). Set PORT=<n> to use another port "
        . "(one browser origin is shared across SDK examples, so only one runs at a time).");
}
fclose($sock);

// 6. serve — SINGLE WORKER (do NOT set PHP_CLI_SERVER_WORKERS)
fwrite(STDERR, "serving http://localhost:{$port}  (Ctrl-C to stop)\n");
$cmd = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("localhost:{$port}") . ' ' . escapeshellarg('router.php');
passthru($cmd, $rc);
exit($rc);

// ── helpers ────────────────────────────────────────────────────────────────

function fetchBundle(string $base, string $frontend, string $tag, string $wantSha): void
{
    $url = RELEASE_BASE . '/' . rawurlencode($tag) . '/dist.tar.gz';

    fwrite(STDERR, "fetching frontend {$tag} → {$url}\n");
    $tmp = $base . '/.frontend.download.tar.gz';
    @unlink($tmp);
    passthru('curl -fsSL ' . escapeshellarg($url) . ' -o ' . escapeshellarg($tmp), $rc);
    if ($rc !== 0 || !is_file($tmp)) {
        fail("could not download the pinned frontend release ({$url}).\n"
            . "If the release does not exist yet, seed it manually: build the frontend, then\n"
            . "  mkdir -p " . escapeshellarg($frontend) . " && tar -xzf dist.tar.gz -C " . escapeshellarg($frontend) . "\n"
            . "  printf %s " . escapeshellarg($wantSha) . " > " . escapeshellarg($frontend . '/.sha')
            . "   # the recorded checksum makes it a verified cache-hit");
    }

    $gotSha = strtolower((string) hash_file('sha256', $tmp));
    if (!hash_equals($wantSha, $gotSha)) {
        @unlink($tmp);
        fail("frontend checksum MISMATCH.\n  expected {$wantSha}\n  got      {$gotSha}\n"
            . "Refusing to serve an unverified bundle. Fix frontend.lock or re-download.");
    }

    rrmdir($frontend);
    @mkdir($frontend, 0755, true);
    passthru('tar -xzf ' . escapeshellarg($tmp) . ' -C ' . escapeshellarg($frontend), $rc);
    @unlink($tmp);
    if ($rc !== 0 || !is_file($frontend . '/index.html')) {
        fail("failed to unpack the frontend bundle.");
    }
    // Record the verified checksum so the next start recognises THIS tag/sha as a valid cache-hit
    // (and a later pin bump / sha tamper does not, forcing a re-verify — spec §8).
    file_put_contents($frontend . '/.sha', $wantSha);
    fwrite(STDERR, "frontend {$tag} verified + unpacked → {$frontend}\n");
}

function fail(string $msg): never
{
    fwrite(STDERR, "\nERROR: {$msg}\n");
    exit(1);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
