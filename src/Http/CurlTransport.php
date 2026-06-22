<?php

declare(strict_types=1);

namespace Allus\CompanyData\Http;

use Allus\CompanyData\Errors\ApiError;

/**
 * The default cURL-based {@see Transport}.
 *
 * Uses ext-curl when available (the common case), else falls back to a stream
 * context. Returns a {@see Response} with the status, raw body bytes, and parsed
 * headers; throws {@see ApiError}(0) on a network failure.
 */
final class CurlTransport implements Transport
{
    public function __construct(
        private readonly float $timeout = 30.0,
    ) {
    }

    public function post(string $url, array $form, array $headers): Response
    {
        return $this->dispatch('POST', $url, http_build_query($form), $headers, formEncoded: true);
    }

    public function get(string $url, ?array $query, array $headers): Response
    {
        if ($query !== null && $query !== []) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($query);
        }
        return $this->dispatch('GET', $url, null, $headers, formEncoded: false);
    }

    public function send(string $method, string $url, ?array $query, ?string $body, array $headers): Response
    {
        if ($query !== null && $query !== []) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . http_build_query($query);
        }
        // The Content-Type for a body is the caller's (in $headers) — don't force
        // form-encoded (that's only for the token POST).
        return $this->dispatch($method, $url, $body, $headers, formEncoded: false);
    }

    /**
     * @param array<string,string> $headers
     * @param bool $formEncoded when true and $body !== null, set the
     *   {@code application/x-www-form-urlencoded} Content-Type (the token POST);
     *   otherwise the Content-Type is whatever is already in $headers.
     */
    private function dispatch(string $method, string $url, ?string $body, array $headers, bool $formEncoded): Response
    {
        if (\function_exists('curl_init')) {
            return $this->sendCurl($method, $url, $body, $headers, $formEncoded);
        }
        return $this->sendStream($method, $url, $body, $headers, $formEncoded);
    }

    /**
     * @param array<string,string> $headers
     */
    private function sendCurl(string $method, string $url, ?string $body, array $headers, bool $formEncoded): Response
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiError(0, null, "could not init curl for {$url}");
        }
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        if ($body !== null && $formEncoded) {
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_CONNECTTIMEOUT => (int) ceil($this->timeout),
            CURLOPT_HEADERFUNCTION => function ($_ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            throw new ApiError(0, null, "request to {$url} failed: {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // No curl_close(): since PHP 8.0 the CurlHandle is an object freed automatically
        // when $ch goes out of scope; curl_close() is a no-op (we require PHP >= 8.1).

        return new Response($status, (string) $resp, $responseHeaders);
    }

    /**
     * Stream-context fallback (no ext-curl).
     *
     * @param array<string,string> $headers
     */
    private function sendStream(string $method, string $url, ?string $body, array $headers, bool $formEncoded): Response
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        if ($body !== null && $formEncoded) {
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $this->timeout,
                'ignore_errors' => true, // so non-2xx still returns a body
            ],
        ]);
        $resp = @file_get_contents($url, false, $context);
        if ($resp === false) {
            throw new ApiError(0, null, "request to {$url} failed");
        }
        $status = 0;
        $responseHeaders = [];
        // $http_response_header is populated by the stream wrapper.
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[trim($parts[0])] = trim($parts[1]);
            }
        }
        return new Response($status, $resp, $responseHeaders);
    }
}
