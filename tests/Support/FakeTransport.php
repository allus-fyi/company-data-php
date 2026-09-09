<?php

declare(strict_types=1);

namespace Allus\CompanyData\Tests\Support;

use Allus\CompanyData\Http\Response;
use Allus\CompanyData\Http\Transport;

/**
 * A scripted {@see Transport} for tests (no network). Either queue POST/GET
 * responses FIFO, or supply a GET router callback that returns a Response per URL.
 *
 * Mirrors the Python tests' {@code FakeSession}.
 */
final class FakeTransport implements Transport
{
    /** @var list<Response> */
    public array $postResponses = [];
    /** @var list<Response> */
    public array $getResponses = [];

    /** @var list<array{url: string, form: array<string,string>, headers: array<string,string>}> */
    public array $posts = [];
    /** @var list<array{url: string, query: ?array<string,scalar>, headers: array<string,string>}> */
    public array $gets = [];
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string,string>}> */
    public array $sends = [];

    /** @var (callable(string, ?array<string,scalar>): Response)|null */
    private $getRouter;

    /** @var (callable(string, string, ?string, array<string,string>): Response)|null */
    private $writeRouter;

    /**
     * @param (callable(string, ?array<string,scalar>): Response)|null $getRouter
     * @param (callable(string, string, ?string, array<string,string>): Response)|null $writeRouter
     *   invoked for write verbs (POST/PUT/DELETE via {@see send()}) with
     *   ({@code $method, $url, $body, $headers}).
     */
    public function __construct(?callable $getRouter = null, ?callable $writeRouter = null)
    {
        $this->getRouter = $getRouter;
        $this->writeRouter = $writeRouter;
    }

    public function post(string $url, array $form, array $headers): Response
    {
        $this->posts[] = ['url' => $url, 'form' => $form, 'headers' => $headers];
        if ($this->getRouter !== null) {
            // Router mode: POST always returns a token.
            return self::tokenOk();
        }
        return array_shift($this->postResponses) ?? throw new \RuntimeException('no queued POST response');
    }

    public function get(string $url, ?array $query, array $headers): Response
    {
        $this->gets[] = ['url' => $url, 'query' => $query, 'headers' => $headers];
        // The registry route is served the way a deployment serves it: the client fetches it
        // beside the request-field catalog, and a fake that did not answer it would be testing an
        // environment no deployment has.
        if (str_ends_with($url, '/api/contact-field-types')) {
            return self::json(200, self::fieldTypeRows());
        }
        if ($this->getRouter !== null) {
            return ($this->getRouter)($url, $query);
        }
        return array_shift($this->getResponses) ?? throw new \RuntimeException('no queued GET response');
    }

    public function send(string $method, string $url, ?array $query, ?string $body, array $headers): Response
    {
        if ($query !== null && $query !== []) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($query);
        }
        $this->sends[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
        if ($this->writeRouter !== null) {
            return ($this->writeRouter)($method, $url, $body, $headers);
        }
        return self::json(200, []);
    }

    /**
     * The vector's own registry rows — the same body a deployment serves.
     *
     * @return list<array<string,mixed>>
     */
    public static function fieldTypeRows(): array
    {
        static $rows = null;
        if ($rows === null) {
            $raw = file_get_contents(__DIR__ . '/../../testdata/contract-field-validation-vector.json');
            $rows = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR)['registry'];
        }
        return $rows;
    }

    /** The vector's registry, which every model test types its values against. */
    public static function fieldTypes(): \Allus\CompanyData\Model\FieldTypes
    {
        static $registry = null;
        return $registry ??= new \Allus\CompanyData\Model\FieldTypes(self::fieldTypeRows());
    }

    // ── response builders ───────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|list<mixed>|null $jsonBody
     * @param array<string,string> $headers
     */
    public static function json(int $status, ?array $jsonBody = null, array $headers = []): Response
    {
        return new Response($status, $jsonBody !== null ? json_encode($jsonBody, JSON_THROW_ON_ERROR) : '', $headers);
    }

    /**
     * @param array<string,string> $headers
     */
    public static function text(int $status, string $text, array $headers = []): Response
    {
        return new Response($status, $text, $headers);
    }

    public static function tokenOk(): Response
    {
        return self::json(200, ['access_token' => 'tok-123', 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }
}
