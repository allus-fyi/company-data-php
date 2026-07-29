<?php

declare(strict_types=1);

/**
 * One-command launcher for the whole example test suite — one server, all three scenario families.
 *
 * Steps:
 *   1. wipe .runtime/ (fresh state each boot)
 *   2. composer install if vendor/ is missing
 *   3. on a missing bundle: fetch the pinned frontend release (frontend.lock), VERIFY sha256, unpack to
 *      .frontend/<tag>/ (a present, verified bundle is a cache hit — nothing is re-fetched)
 *   4. assert the bundle's contract.json version == the backend's implemented contractVersion
 *   5. refuse a busy port with a clear message
 *   6. exec `php -S 0.0.0.0:${PORT:-8091} router.php` — SINGLE WORKER (PHP_CLI_SERVER_WORKERS unset),
 *      bound to ALL interfaces so a phone on the same network can reach it, and printing every URL it
 *      is reachable on.
 */

const CONTRACT_VERSION = 3; // must equal Server::CONTRACT_VERSION
const RELEASE_BASE = 'https://github.com/allme-sdk/example-test-suite/releases/download';

$base = dirname(__DIR__);
chdir($base);

fwrite(STDERR, "allus SDK examples — starting up\n");

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
// So a pin bump (tag change) OR a tampered sha (same tag, different sha) both force a re-fetch + re-verify,
// which is what makes the spec §8 "tamper the pin's sha256 → loud checksum refusal" drill actually fire.
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

// 5. port — probe the SAME address the server binds (all interfaces), not just loopback
$port = (int) (getenv('PORT') ?: 8091);
$sock = @stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
if ($sock === false) {
    fail("port {$port} is busy ({$errstr}). Set PORT=<n> to use another port "
        . "(one browser origin is shared across SDK examples, so only one runs at a time).");
}
fclose($sock);

// 6. serve — SINGLE WORKER (do NOT set PHP_CLI_SERVER_WORKERS), on ALL interfaces
printReachableUrls($port);
$cmd = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("0.0.0.0:{$port}") . ' ' . escapeshellarg('router.php');
passthru($cmd, $rc);
exit($rc);

// ── helpers ────────────────────────────────────────────────────────────────

/**
 * Announce every URL the server is reachable on.
 *
 * The server binds 0.0.0.0, so a phone on the same network can reach it — but only if the person
 * holding the phone knows which address to type. Print the loopback URL AND every non-loopback IPv4
 * address of this host, plus the plain warning that this is now open to the local network.
 */
function printReachableUrls(int $port): void
{
    fwrite(STDERR, "serving on ALL interfaces, port {$port}  (all three scenario families; Ctrl-C to stop)\n");
    fwrite(STDERR, "  on this machine:  http://localhost:{$port}\n");
    $lan = lanAddresses();
    if ($lan === []) {
        fwrite(STDERR, "  on this network:  (no non-loopback IPv4 address found — is this machine on a network?)\n");
    } else {
        foreach ($lan as $i => $addr) {
            $label = $i === 0 ? '  on this network:  ' : '                    ';
            fwrite(STDERR, "{$label}http://{$addr}:{$port}\n");
        }
    }
    fwrite(STDERR, "  NOTE: anyone on your network can now reach this demo, and its setup panels accept and\n"
        . "        store real credentials under .runtime/config/ — OAuth and data-client secrets,\n"
        . "        private-key PEMs and their passphrases, and webhook signing secrets. It is a local\n"
        . "        developer example, not a hardened service: run it only on a network you trust, and\n"
        . "        only with sandbox credentials.\n");
}

/**
 * EVERY non-loopback, non-link-local IPv4 address of this host, in interface order.
 *
 * Enumerates the interfaces — a route probe or a hostname lookup would return only ONE address
 * on a laptop with Wi-Fi plus a VPN or a docker bridge, and not necessarily the reachable one.
 * Down interfaces are skipped, matching Go/Java/C#'s "up" filter.
 */
function lanAddresses(): array
{
    $out = [];
    if (function_exists('net_get_interfaces')) {
        foreach (net_get_interfaces() ?: [] as $iface) {
            if (($iface['up'] ?? true) !== true) {
                continue;
            }
            foreach ($iface['unicast'] ?? [] as $unicast) {
                $addr = (string) ($unicast['address'] ?? '');
                if (isLanIpv4($addr)) {
                    $out[] = $addr;
                }
            }
        }
    }
    if ($out === []) {
        // net_get_interfaces() is unavailable on some platforms (Windows) — fall back to a host
        // lookup. Incomplete by construction (one hostname answer, not one per interface), which
        // is why it runs only when real enumeration is unavailable.
        $host = gethostname();
        foreach ($host === false ? [] : (gethostbynamel($host) ?: []) as $addr) {
            if (isLanIpv4($addr)) {
                $out[] = $addr;
            }
        }
    }
    return array_values(array_unique($out));
}

/** IPv4 only — an IPv6 literal is not what anyone types into a phone. */
function isLanIpv4(string $addr): bool
{
    if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    return !str_starts_with($addr, '127.') && !str_starts_with($addr, '169.254.');
}

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
