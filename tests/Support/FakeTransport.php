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

    /** @var (callable(string, ?array<string,scalar>): Response)|null */
    private $getRouter;

    /**
     * @param (callable(string, ?array<string,scalar>): Response)|null $getRouter
     */
    public function __construct(?callable $getRouter = null)
    {
        $this->getRouter = $getRouter;
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
        if ($this->getRouter !== null) {
            return ($this->getRouter)($url, $query);
        }
        return array_shift($this->getResponses) ?? throw new \RuntimeException('no queued GET response');
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
