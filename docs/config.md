# Config reference

`Allus\CompanyData\Config`.

A single JSON file holds the whole SDK configuration. **Config-only key handling
is a hard rule:** no SDK method ever takes a key, passphrase, or secret as an
argument — everything cryptographic (decrypting the service PEM, decrypting field
values, verifying the webhook HMAC, unwrapping the account-key envelope) is driven
entirely by this config. Your only key responsibility is putting the right values
here.

## Fields

| Field (JSON key) | Property | Required | Default | Meaning |
|------------------|----------|----------|---------|---------|
| `api_url` | `apiUrl` | yes | — | API base, e.g. `https://api.allme.fyi`. |
| `client_id` | `clientId` | yes | — | The `client_credentials` client id (scoped to one service). |
| `client_secret` | `clientSecret` | yes | — | The client secret. |
| `service_private_key` | `servicePrivateKey` | yes | — | Path to the OpenSSL-encrypted PKCS#8 PEM (downloaded from the portal). |
| `key_passphrase` | `keyPassphrase` | yes | — | Decrypts the service PEM in memory at startup. |
| `account_private_key` | `accountPrivateKey` | no | `null` | Path to the company **account** key PEM — only needed to receive `encrypt_payload` webhooks. |
| `account_passphrase` | `accountPassphrase` | no | `null` | Decrypts the account PEM. |
| `webhooks` | `webhooks` | no | `[]` | Per-webhook HMAC secrets, keyed by webhook id (matched via the `X-Allus-Webhook-Id` header). |
| `cache_dir` | `cacheDir` | no | `"./allus-cache"` | Durable local buffer dir for the changes pump. Must be writable + durable. |
| `format` | `format` | no | `"json"` | Wire format: `"json"` or `"xml"`. Invisible in the output. |

All properties are `public readonly`. The PEM is PBES2 (PBKDF2-HMAC-SHA256 +
AES-256-CBC, 100k iters); it is decrypted in memory at `Client` construction (via
phpseclib) and never written back to disk in plaintext.

## Constructors

```php
Allus\CompanyData\Config::fromFile(string $path): Config   // load JSON; ALLUS_* env vars override file values
Allus\CompanyData\Config::fromEnv(): Config                 // build entirely from ALLUS_* env vars
```

In practice you build the client directly:

```php
use Allus\CompanyData\Client;

$client = Client::fromConfig('allus.json');   // == new Client(Config::fromFile('allus.json'))
$client = Client::fromEnv();                   // == new Client(Config::fromEnv())
```

## Env overrides

Every scalar field can be overridden by its `ALLUS_*` env var (so secrets needn't
live in the file). An env value, when set (and non-empty), wins over the file
value.

| Field | Env var |
|-------|---------|
| `api_url` | `ALLUS_API_URL` |
| `client_id` | `ALLUS_CLIENT_ID` |
| `client_secret` | `ALLUS_CLIENT_SECRET` |
| `service_private_key` | `ALLUS_SERVICE_PRIVATE_KEY` |
| `key_passphrase` | `ALLUS_KEY_PASSPHRASE` |
| `account_private_key` | `ALLUS_ACCOUNT_PRIVATE_KEY` |
| `account_passphrase` | `ALLUS_ACCOUNT_PASSPHRASE` |
| `cache_dir` | `ALLUS_CACHE_DIR` |
| `format` | `ALLUS_FORMAT` |
| flat single-webhook secret | `ALLUS_WEBHOOK_SECRET` |

## Webhook secrets

```json
"webhooks": { "wh_abc123": "secret_a", "wh_def456": "secret_b" }
```

Keyed by webhook id; the SDK reads `X-Allus-Webhook-Id` off the incoming request
and looks up the matching secret. A service with a single webhook can use the flat
shortcut instead of the map:

```json
"webhook_secret": "the_one_secret"
```

(stored internally under the reserved key `Config::SINGLE_WEBHOOK_KEY` and used as
the fallback when there is no id-specific match). `ALLUS_WEBHOOK_SECRET` overrides
the flat shortcut.

`Config::webhookSecret(?string $webhookId = null): ?string` resolves the secret for
an id (falling back to the single-webhook shortcut). The webhook helpers call this
for you — you never pass a secret in.

## Validation

* A missing required field (`api_url`, `client_id`, `client_secret`, `service_private_key`, `key_passphrase`) throws `ConfigError` listing what's missing.
* A `format` other than `json`/`xml` throws `ConfigError`.
* A malformed/missing config file throws `ConfigError`.
* An unreadable `service_private_key` PEM, or a wrong `key_passphrase`, throws `ConfigError` at `Client` construction (fail fast — a bad key is a config problem, not a runtime decrypt error).
