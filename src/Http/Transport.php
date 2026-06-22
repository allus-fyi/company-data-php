<?php

declare(strict_types=1);

namespace Allus\CompanyData\Http;

use Allus\CompanyData\Errors\ApiError;

/**
 * The wire transport seam.
 *
 * {@see HttpClient} owns auth + parsing + the error mapping and goes through a
 * Transport for the raw bytes — so tests inject a fake transport (no network) and
 * production uses {@see CurlTransport}. A transport MUST throw {@see ApiError}
 * (status 0) on a network failure, and otherwise return a {@see Response}.
 */
interface Transport
{
    /**
     * @param array<string,string> $form    application/x-www-form-urlencoded body fields.
     * @param array<string,string> $headers request headers.
     *
     * @throws ApiError on a network failure (status 0).
     */
    public function post(string $url, array $form, array $headers): Response;

    /**
     * @param array<string,scalar>|null $query   query parameters (appended to the URL).
     * @param array<string,string>      $headers request headers.
     *
     * @throws ApiError on a network failure (status 0).
     */
    public function get(string $url, ?array $query, array $headers): Response;

    /**
     * Send a request for any verb with an optional raw body (the verb's
     * {@code Content-Type} is carried in {@code $headers}). The {@see HttpClient}
     * uses this for the company-document write methods (JSON-body POST/PUT and
     * raw-bytes file POST and DELETE); the form-encoded token POST stays on
     * {@see post()}.
     *
     * @param array<string,scalar>|null $query   query parameters (appended to the URL).
     * @param array<string,string>      $headers request headers (incl. Content-Type when $body !== null).
     *
     * @throws ApiError on a network failure (status 0).
     */
    public function send(string $method, string $url, ?array $query, ?string $body, array $headers): Response;
}
