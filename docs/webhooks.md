# Webhook receiver helpers

The lower-latency push alternative to polling the changes feed. The platform POSTs
each change event to your configured webhook URL with:

* `X-Allus-Webhook-Id` — which webhook this is (selects the HMAC secret from config).
* `X-Allus-Signature` — `HMAC-SHA256(rawBody, secret)` as lowercase hex.
* the body — the same slug-keyed `Change` shape as the pull feed (JSON or XML). If `encrypt_payload` is on, the body is replaced by a `{"_enc":1,…}` envelope encrypted to the company **account** key (and the HMAC is over that envelope).

**All secrets/keys come from config — these helpers take NO key or secret
arguments.** Always pass the **raw request body bytes** (don't re-serialize a
parsed body; the HMAC is over the exact bytes sent).

## Client methods (the usual form)

```php
$client->verifyWebhook(string $rawBody, array $headers): bool
$client->parseWebhook(string $rawBody, array $headers):  Change
$client->handleWebhook(string $rawBody, array $headers): Change   // verify + parse
```

| Method | Returns | Errors |
|--------|---------|--------|
| `verifyWebhook` | `bool` — recomputes `HMAC-SHA256($rawBody, secret)` and constant-time-compares (`hash_equals`) to `X-Allus-Signature`. `false` on missing signature / unknown id / mismatch. | **Never throws** for a bad signature. |
| `parseWebhook` | a typed `Change`. Does **not** verify. Handles JSON, XML, and the `encrypt_payload` account-key envelope. | `WebhookError` on a malformed/unparseable body or envelope. |
| `handleWebhook` | a typed `Change` — verify **then** parse. | `WebhookError` on a bad/unknown signature, or any `parseWebhook` error. |

`$headers` may be `array<string,string>` or `array<string,string[]>` (PSR-7 shape);
the SDK looks up `X-Allus-*` case-insensitively and takes the first value of an
array.

## Static functions

The same three are static methods on `Allus\CompanyData\Webhooks\Webhooks`. They
take the `Config` and the decrypt/type closures explicitly — used by `Client`
internally; you'll normally use the client methods inside an app.

```php
use Allus\CompanyData\Webhooks\Webhooks;

Webhooks::verify(string $rawBody, array $headers, Config $config): bool
Webhooks::parse(string $rawBody, array $headers, Config $config, callable $typeForSlug, callable $decryptValue, ?callable $binaryFetch = null, ?RSAPrivateKey $accountKey = null): Change
Webhooks::handle(string $rawBody, array $headers, Config $config, callable $typeForSlug, callable $decryptValue, ?callable $binaryFetch = null, ?RSAPrivateKey $accountKey = null): Change
```

## In a web route

### Framework-agnostic (raw PHP)

```php
use Allus\CompanyData\Client;
use Allus\CompanyData\Errors\WebhookError;

$client  = Client::fromConfig('allus.json');
$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [
    'X-Allus-Webhook-Id' => $_SERVER['HTTP_X_ALLUS_WEBHOOK_ID'] ?? '',
    'X-Allus-Signature'  => $_SERVER['HTTP_X_ALLUS_SIGNATURE'] ?? '',
];

try {
    $change = $client->handleWebhook($rawBody, $headers);
} catch (WebhookError) {
    http_response_code(401);
    exit;
}
if (!seen($change->id)) {          // idempotency — same rule as the pump
    applyChange($change);
    recordSeen($change->id);
}
http_response_code(204);
```

### PSR-7 (Slim/Mezzio/…)

```php
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Allus\CompanyData\Errors\WebhookError;

$app->post('/allus/webhook', function (Request $request, Response $response) use ($client) {
    try {
        $change = $client->handleWebhook((string) $request->getBody(), $request->getHeaders());
    } catch (WebhookError) {
        return $response->withStatus(401);
    }
    if (!seen($change->id)) {
        applyChange($change);
        recordSeen($change->id);
    }
    return $response->withStatus(204);
});
```

Split the steps if you prefer:

```php
if (!$client->verifyWebhook($rawBody, $headers)) {
    http_response_code(401);
    exit;
}
$change = $client->parseWebhook($rawBody, $headers);
```

## Config-driven secrets

Per-webhook HMAC secrets live in the config `webhooks` map, keyed by webhook id;
the SDK reads `X-Allus-Webhook-Id` and looks up the matching secret. A
single-webhook service can use the flat `"webhook_secret": "…"` shortcut (or
`ALLUS_WEBHOOK_SECRET`). An unknown/unconfigured id ⇒ `verifyWebhook` returns
`false` (and `handleWebhook` throws `WebhookError`).

## The `encrypt_payload` account-key envelope

If a webhook has `encrypt_payload` enabled, the whole body is a `{"_enc":1,…}`
envelope encrypted to your company **account** key, and the HMAC is over that
envelope. `parseWebhook`/`handleWebhook`:

1. Unwrap the envelope with the configured `account_private_key` + `account_passphrase`.
2. Parse the inner payload (JSON or XML per `format`).
3. Decrypt the inner field `value` (a service-key wrapper) with the service key.

So an `encrypt_payload` `Change` is identical to a plain one. Receiving such a
webhook without an `account_private_key` configured throws `WebhookError`. The
`Client` loads the account key **once** at construction and reuses it, so an
`encrypt_payload` webhook never re-reads the PEM + re-runs PBKDF2 (~100k iters) per
request.

> The envelope uses RSA-OAEP-**SHA1** (OpenSSL's default), distinct from the
> OAEP-SHA256 used for person field values. The SDK handles this difference
> internally — you only supply the account key in config.

## XML safety (XXE)

When the body is XML (or `format: "xml"`), it is parsed with a hardened
`DOMDocument`: `LIBXML_NONET` (no network), any `<!DOCTYPE>` is rejected outright,
and entity substitution is never enabled. The HMAC is always computed over the
**raw bytes**, never the parsed tree.
