# Error model

Same taxonomy + names across all six SDKs. All under
`Allus\CompanyData\Errors\…`.

```php
use Allus\CompanyData\Errors\{
    ConfigError, AuthError, ApiError, DecryptError, WebhookError, RateLimitError
};
```

| Error | Raised when |
|-------|-------------|
| `ConfigError` | Missing/invalid config, an unreadable key file, or a wrong passphrase — at construction (fail fast). |
| `AuthError` | The `client_credentials` token fetch/refresh failed (bad `client_id`/`secret`, revoked client); or a mid-flight 401 survived the one automatic refresh-and-retry. |
| `ApiError` | Any non-2xx from the API. |
| `DecryptError` | A ciphertext wrapper is malformed, the key is wrong, or the GCM tag mismatches. |
| `WebhookError` | Signature verification failed, or a webhook envelope couldn't be unwrapped/parsed. |
| `RateLimitError` | A 429 from a rate-limited endpoint. Subclass of `ApiError`. |

All extend `\RuntimeException` (so a broad `catch (\RuntimeException)` catches them
all). `ConfigError`, `AuthError`, `DecryptError`, `WebhookError` are `final`;
`ApiError` is not (so `RateLimitError` can extend it).

## `ApiError`

```php
class ApiError extends \RuntimeException {
    public readonly int     $status;    // the HTTP status
    public readonly ?string $errorKey;  // the platform error_key, when the body provided one
    public readonly array   $details;   // every OTHER field of the error body, verbatim ([] when none)
    // getMessage() is "HTTP <status> (<error_key>): <message>"
}
```

A transport failure (no HTTP response — e.g. a connection error) surfaces as
`ApiError` with `status === 0`.

`$details` exists because some responses carry data beside the key. The one that
matters today is a **410 `company_data.file_expired`** from a binary slot's file
endpoint — a Share-once answer whose 90-day retention has elapsed — which returns
the expired answer's `content_sha256` and `expired_at`, so you can still identify
what you once held:

```php
try {
    $bytes = $handle->bytes();
} catch (ApiError $e) {
    if ($e->status === 410 && $e->errorKey === 'company_data.file_expired') {
        $digest    = $e->details['content_sha256'] ?? null;
        $expiredAt = $e->details['expired_at'] ?? null;
    }
}
```

A **421 `region.rebase_required`** never reaches you when the platform is reachable: it is the global front door telling the SDK to send the call to the caller's home region, which the SDK does automatically (README, **How it's wired** → Regions). It surfaces as `ApiError` only when the base the refusal names is absent or empty — in which case no base was stored and no retry was made.

## 503 `db.writes_paused` — saving is paused, retry

While the platform cannot complete a save in every region, a call can answer
**503** with `error_key` **`db.writes_paused`** (`"Saving data is not possible
right now"`) and the header `Retry-After: 30`. **Nothing was written**, so the call
is safe to repeat exactly as it was. Reads keep working.

It surfaces as a plain `ApiError` (`$e->status === 503`, `$e->errorKey ===
'db.writes_paused'`); the SDK does not retry it. `ApiError` does not carry the
`Retry-After` header: wait 30 seconds, then repeat the same call.

Where it can come from:

* every company-data and customer call that is not a GET — creating, updating or
  deleting documents, flow-run starts, answers, uploads and generation, consent
  answers, connect requests, messages, 2FA challenges, `/api/keys/batch`;
* the change-feed drains `GET /api/company-data/changes` and
  `GET /api/customer/changes` (`processChanges`, `drainBatch`): nothing was
  drained, the events stay queued on the server and arrive on a later run, and the
  local buffer is untouched;
* `OAuthClient::pollResult` (`POST /oauth2/result`): the result is not consumed;
  poll again.

The token request (`POST /oauth2/token`) does not answer it: token grants keep
working while saving is paused.

```php
} catch (ApiError $e) {
    if ($e->status === 503 && $e->errorKey === 'db.writes_paused') {
        sleep(30);
        // repeat the same call
    }
}
```

## 503 `platform.out_of_order` — the platform is out of order, retry

While the region serving a call is being rebuilt, the call answers **503** with
`error_key` **`platform.out_of_order`** (`"allme is temporarily out of order.
Please try again later."`) and the header `Retry-After: 300`. **The request was
not processed**, so the call is safe to repeat exactly as it was. The platform
answers normally again once the region is back in service.

It surfaces as a plain `ApiError` (`$e->status === 503`, `$e->errorKey ===
'platform.out_of_order'`); the SDK does not retry it. `ApiError` does not carry the
`Retry-After` header: wait 300 seconds, then repeat the same call.

Where it can come from:

* every company-data and customer call, reads included — connections, request
  fields, binary fetches, documents, flow runs, consent answers, connect requests,
  messages, 2FA challenges and results, `/api/keys`;
* the change-feed drains `GET /api/company-data/changes` and
  `GET /api/customer/changes` (`processChanges`, `drainBatch`): nothing was
  drained, the events stay queued on the server and arrive on a later run, and the
  local buffer is untouched;
* every `OAuthClient` call — `exchangeCode`, `userinfo`, `pollResult` (the
  result is not consumed; poll again).

The `client_credentials` token request (`POST /oauth2/token`) the service and
customer clients make does not answer it, so the SDK still holds a token and the
503 arrives on the call itself. Every other grant at `POST /oauth2/token` — the
`OAuthClient` code exchange, a refresh-token grant — answers it.

```php
} catch (ApiError $e) {
    if ($e->status === 503 && $e->errorKey === 'platform.out_of_order') {
        sleep(300);
        // repeat the same call
    }
}
```

## `RateLimitError`

```php
final class RateLimitError extends ApiError {   // status is always 429
    public readonly ?float $retryAfter;          // seconds from the Retry-After header, or null
}
```

The SDK already retries a 429 with backoff before surfacing this:

* the transport (`HttpClient`) retries a bounded number of times honoring `Retry-After`;
* the `connections(...)` generator additionally backs off + retries a page a bounded number of times.

For the heavily-limited connections endpoints it surfaces after that backoff so
you don't accidentally hammer them; on the changes feed it auto-backs-off within
reason. If you catch it, wait `$err->retryAfter` (or a default) before retrying.

## Where each surfaces

| Layer | Common errors |
|-------|---------------|
| `Client::fromConfig` / `fromEnv` / `new Client(...)` | `ConfigError` |
| Token / any call (auth) | `AuthError` |
| `connections`, `connection`, `requestFields`, `logs`, pump drains | `ApiError`, `RateLimitError` |
| Value access / `BinaryHandle::bytes()` / pump delivery | `DecryptError` |
| `verifyWebhook` / `parseWebhook` / `handleWebhook` | `WebhookError` (`verifyWebhook` returns `false` rather than throwing on a bad signature) |

## Example

```php
use Allus\CompanyData\Client;
use Allus\CompanyData\Errors\{ConfigError, AuthError, ApiError, DecryptError, WebhookError, RateLimitError};

try {
    $client = Client::fromConfig('allus.json');
    foreach ($client->connections() as $conn) {
        process($conn);
    }
} catch (ConfigError) {
    // fix the config / key file
} catch (AuthError) {
    // bad/revoked credentials
} catch (RateLimitError $e) {
    sleep((int) ($e->retryAfter ?? 60));
} catch (DecryptError) {
    // wrong service key or corrupt data
} catch (ApiError $e) {
    log($e->status, $e->errorKey, $e->getMessage());
}
```
