<?php

declare(strict_types=1);

/**
 * Router for `php -S localhost:${PORT:-8091} router.php` (spec §3).
 *
 * SINGLE WORKER — PHP_CLI_SERVER_WORKERS is deliberately NOT set, so requests serialize and there is no
 * cross-request concurrency to guard. Every request (static bundle OR API) is dispatched by {@see Server}.
 */

use Allus\FlowExample\Runtime;
use Allus\FlowExample\Server;

$base = __DIR__;

$autoload = $base . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "dependencies not installed — run `composer install` (or `composer start`).";
    return true;
}
require $autoload;

// The SDK's own version (read from the linked package's composer.json — path repo → ../..).
$sdkVersion = 'unknown';
$sdkComposer = $base . '/../../composer.json';
if (is_file($sdkComposer)) {
    $meta = json_decode((string) file_get_contents($sdkComposer), true);
    if (is_array($meta) && isset($meta['version'])) {
        $sdkVersion = (string) $meta['version'];
    }
}

$rt = new Runtime($base);
// #478: the verified bundle lives under the tag-specific cache dir .frontend/<tag>/ (spec §2), so a pin
// bump serves the new release rather than a stale extraction. Resolve the tag from frontend.lock.
$lock = json_decode((string) @file_get_contents($base . '/frontend.lock'), true);
$frontendDir = $base . '/.frontend'
    . (is_array($lock) && isset($lock['tag']) ? '/' . $lock['tag'] : '');
$server = new Server($rt, $frontendDir, $sdkVersion);
$server->handle();

return true;
