<?php

declare(strict_types=1);

namespace Allus\Examples;

use Allus\Examples\CompanyData\Handlers as CompanyDataHandlers;
use Allus\Examples\Flow\Handlers as FlowHandlers;
use Allus\Examples\Identity\Handlers as IdentityHandlers;

/**
 * The ONE example server (#494). Single class, single worker: it owns all the SHARED scaffolding — HTTP
 * dispatch, the aggregate GET /api/meta, the static-bundle serving, the JSON/text/redirect plumbing — and
 * routes every scenario request to its family's handler by the scenario id:
 *
 *   ints (1–8)          → the identity handlers   (Sign in / OIDC / 2FA + /callback + /enroll)
 *   flow:*              → the flow handler         (the poll-driven contract-flow drive loop)
 *   companydata:*       → the company-data handlers (read / definitions / changes / webhook / documents)
 *
 * The handlers ARE the SDK example — each opens with the intended top-level SDK calls. This class contains
 * NO SDK calls; it only serves the bundle and shuttles requests to/from the handlers. All 14 scenarios of
 * all three families run on ONE port (default 8091) at contractVersion 3.
 */
final class Server
{
    public const CONTRACT_VERSION = 3;
    public const SDK = 'php';

    private readonly IdentityHandlers $identity;
    private readonly FlowHandlers $flow;
    private readonly CompanyDataHandlers $companyData;

    /** @var array<string,Family> family key → handler (for run-dispatch by the run's recorded family) */
    private readonly array $families;

    public function __construct(
        private readonly Runtime $rt,
        private readonly string $frontendDir,
        private readonly string $sdkVersion,
    ) {
        $this->identity = new IdentityHandlers($rt);
        $this->flow = new FlowHandlers($rt);
        $this->companyData = new CompanyDataHandlers($rt);
        $this->families = [
            IdentityHandlers::FAMILY => $this->identity,
            FlowHandlers::FAMILY => $this->flow,
            CompanyDataHandlers::FAMILY => $this->companyData,
        ];
    }

    // ── entry point ────────────────────────────────────────────────────────

    public function handle(): void
    {
        $this->rt->ensureDirs();
        $this->rt->sweep(); // lazy TTL sweep on every request (spec §3)

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        try {
            $this->route($method, $path);
        } catch (\Throwable $e) {
            $this->emit(Response::json(['error' => 'server_error', 'message' => $e->getMessage()], 500));
        }
    }

    private function route(string $method, string $path): void
    {
        // Scenario ids span all three families: ints (identity), flow:run, companydata:* — one pattern.
        $sid = '([\w:.-]+)';

        if ($path === '/api/meta' && $method === 'GET') {
            $this->emit($this->meta());
        } elseif ($path === '/callback' && $method === 'GET') {
            $this->emit($this->identity->callback($_GET)); // identity-only public leg
        } elseif ($path === '/webhook' && $method === 'POST') {
            // company-data-only PUBLIC inbound delivery (not under /api/) — spec §2
            $this->emit($this->companyData->webhook((string) file_get_contents('php://input'), $this->requestHeaders()));
        } elseif ($path === '/api/clear' && $method === 'POST') {
            $this->rt->clearAll();
            $this->emit(Response::json(['ok' => true]));
        } elseif (preg_match("#^/api/scenarios/{$sid}/config$#", $path, $m) && $method === 'POST') {
            $this->emit($this->dispatch($m[1], fn (Family $f) => $f->config($m[1], $this->body())));
        } elseif (preg_match("#^/api/scenarios/{$sid}/start$#", $path, $m) && $method === 'POST') {
            $this->emit($this->dispatch($m[1], fn (Family $f) => $f->start($m[1])));
        } elseif (preg_match("#^/api/scenarios/{$sid}/enroll$#", $path, $m) && $method === 'POST') {
            $this->emit($this->identity->enroll($m[1], $this->body())); // identity-only (scenario 8)
        } elseif (preg_match("#^/api/scenarios/{$sid}/clear$#", $path, $m) && $method === 'POST') {
            $this->emit($this->clearScenario($m[1]));
        } elseif (preg_match('#^/api/runs/([0-9a-f]{32})$#', $path, $m) && $method === 'GET') {
            $this->emit($this->run($m[1]));
        } elseif (str_starts_with($path, '/api/')) {
            $this->emit(Response::json(['error' => 'not_found'], 404));
        } else {
            $this->serveStatic($path);
        }
    }

    // ── dispatch ──────────────────────────────────────────────────────────────

    /**
     * Resolve a scenario id to its family (spec §3): ints → identity, flow:* → flow,
     * companydata:* → company-data. Unknown shapes are 404.
     */
    private function familyFor(string $id): ?Family
    {
        if (ctype_digit($id)) {
            return $this->identity;
        }
        if (str_starts_with($id, 'flow:')) {
            return $this->flow;
        }
        if (str_starts_with($id, 'companydata:')) {
            return $this->companyData;
        }
        return null;
    }

    /**
     * Run $fn against the family that owns $id, or 404 when no family claims the id.
     *
     * @param callable(Family):Response $fn
     */
    private function dispatch(string $id, callable $fn): Response
    {
        $family = $this->familyFor($id);
        return $family === null ? Response::json(['error' => 'not_found'], 404) : $fn($family);
    }

    private function clearScenario(string $id): Response
    {
        if ($this->familyFor($id) === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $this->rt->clearScenario($id);
        return Response::json(['ok' => true]);
    }

    // ── GET /api/meta ────────────────────────────────────────────────────────

    /** Aggregate ALL scenarios of all three families (spec §3 / #494), at contractVersion 3. */
    private function meta(): Response
    {
        $scenarios = [];
        foreach ([$this->identity, $this->flow, $this->companyData] as $family) {
            foreach ($family->scenarios() as $s) {
                $scenarios[] = $s;
            }
        }
        return Response::json([
            'sdk' => self::SDK,
            'sdkVersion' => $this->sdkVersion,
            'contractVersion' => self::CONTRACT_VERSION,
            'scenarios' => $scenarios,
        ]);
    }

    // ── GET /api/runs/{runId} ──────────────────────────────────────────────────

    /** Route the poll to the family that created the run (recorded in run.family). */
    private function run(string $runId): Response
    {
        $run = $this->rt->readRun($runId);
        if ($run === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $family = $this->families[(string) ($run['family'] ?? '')] ?? null;
        if ($family === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return $family->run($runId, $run);
    }

    // ── shared HTTP plumbing ────────────────────────────────────────────────────

    private function emit(Response $r): void
    {
        http_response_code($r->status);
        switch ($r->kind) {
            case 'redirect':
                header('Location: ' . $r->location, true, $r->status === 200 ? 302 : $r->status);
                return;
            case 'text':
                header('Content-Type: text/plain; charset=utf-8');
                echo $r->text;
                return;
            case 'json':
            default:
                header('Content-Type: application/json');
                echo json_encode($r->json ?? [], JSON_UNESCAPED_SLASHES);
        }
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Request headers as a name → value map (for the SDK webhook verify/parse, which look them up
     * case-insensitively). Uses getallheaders() when available; else reconstructs from $_SERVER.
     *
     * @return array<string,string>
     */
    private function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if (is_array($h)) {
                return array_map('strval', $h);
            }
        }
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with((string) $k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $k, 5)))));
                $out[$name] = (string) $v;
            }
        }
        return $out;
    }

    private function serveStatic(string $path): void
    {
        $rel = $path === '/' ? '/index.html' : $path;
        $full = realpath($this->frontendDir . $rel);
        $root = realpath($this->frontendDir);

        // Path-traversal guard + SPA fallback to index.html.
        if ($full === false || $root === false || !str_starts_with($full, $root) || !is_file($full)) {
            $index = $this->frontendDir . '/index.html';
            if (is_file($index)) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($index);
                return;
            }
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "bundle not found";
            return;
        }

        header('Content-Type: ' . self::mime($full));
        readfile($full);
    }

    private static function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => 'text/html; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'json', 'map' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
