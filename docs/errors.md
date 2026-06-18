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
    // getMessage() is "HTTP <status> (<error_key>): <message>"
}
```

A transport failure (no HTTP response — e.g. a connection error) surfaces as
`ApiError` with `status === 0`.

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
