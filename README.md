# allus/company-data (PHP)

The PHP SDK for the **allus company-data API**. Point it at a JSON config file and
it hands back typed, plaintext, **your-slug-keyed conclusions**: for each connected
person, a map of *your request-field slug → plaintext value* (plus whether the
value is live and when it last changed).

The SDK hides everything else — the OAuth token, the field catalog, the id
plumbing, the hybrid decryption, binary fetching, the changes-queue mechanics,
JSON-vs-XML. The platform is **zero-knowledge**: the API only ever holds
ciphertext, so all decryption happens inside the SDK with your service private
key. **The person's own field choices are never exposed** — you only ever see the
request slots you configured.

> This SDK is one of six language ports that share an identical API surface.
> This manual is the PHP view of it.

**Contents:** [TL;DR — fetch new updates](#tldr--fetch-new-updates) ·
[Quickstart](#quickstart) · [Every call](#every-call) ·
[The typed value model](#the-typed-value-model) ·
[The changes pump](#the-changes-pump) · [Webhooks](#webhooks) ·
[Rate limits](#rate-limits) · [Errors](#errors) ·
[How it's wired](#how-its-wired)

Deeper reference pages live in [`docs/`](docs/):
[config](docs/config.md) · [model](docs/model.md) · [pump](docs/pump.md) ·
[webhooks](docs/webhooks.md) · [errors](docs/errors.md).

---

## TL;DR — fetch new updates

```bash
composer require allus/company-data
```

Point a config.json at your service keys:

```json
{
  "api_url": "https://api.allme.fyi",
  "client_id": "svc_xxx",
  "client_secret": "xxx",
  "service_private_key": "/path/to/service.pem",
  "key_passphrase": "xxx",
  "cache_dir": "./allus-cache"
}
```

Drain everything new, handled one update at a time:

```php
<?php
require 'vendor/autoload.php';

use Allus\CompanyData\Client;
use Allus\CompanyData\Model\Change;

$client = Client::fromConfig('config.json');

$client->processChanges(function (Change $change): void {
    // one update at a time: event, person, slug, value, live, at
    printf("%s %s %s %s %s %s\n",
        $change->event, $change->personId, $change->slug ?? '—',
        is_scalar($change->value) ? (string) $change->value : '…',
        $change->live ? 'live' : 'snapshot',
        $change->at?->format('c') ?? '—',
    );
});   // returns when the feed is empty
```

`processChanges` pulls every pending change, decrypts it, and hands them to your
callback ONE BY ONE, acking each only after your code returns. Crash mid-batch?
The next run replays exactly what wasn't acked — nothing is lost, and the API
keeps no backlog of its own. Run it on a schedule (cron / systemd timer); there
is no daemon/follow mode by design. Connections, binary values, and webhooks are
documented below.

---

## Quickstart

Requires **PHP ≥ 8.1**, with `ext-openssl` and `ext-json` (both standard).

```bash
composer require allus/company-data
# or, working from this repo:  composer install     # from sdks/php/
php -r 'require "vendor/autoload.php"; echo Allus\CompanyData\Client::class, PHP_EOL;'
```

The package is **PSR-4 autoloaded** (namespace `Allus\CompanyData\` → `src/`), so
`require 'vendor/autoload.php'` and you're done — no manual includes.

### 1. Write a config file

A single JSON file holds everything. Any field can be overridden by an `ALLUS_*`
env var, so secrets needn't live in the file. **No SDK method ever takes a key,
passphrase, or secret as an argument** — they all come from here.

`allus.json`:

```json
{
  "api_url": "https://api.allme.fyi",
  "client_id": "svc_1a2b3c…",
  "client_secret": "…",
  "service_private_key": "./service-CRM.pem",
  "key_passphrase": "…",

  "account_private_key": "./account.pem",
  "account_passphrase": "…",

  "webhooks": {
    "wh_abc123": "hmac_secret_for_that_webhook"
  },

  "cache_dir": "./allus-cache",
  "format": "json"
}
```

| Field | Required | Meaning |
|-------|----------|---------|
| `api_url` | yes | API base, e.g. `https://api.allme.fyi`. |
| `client_id` / `client_secret` | yes | The registered `client_credentials` credentials for **one** service. |
| `service_private_key` | yes | Path to the OpenSSL-encrypted PKCS#8 PEM you downloaded from the portal. |
| `key_passphrase` | yes | Decrypts that PEM in memory at startup. |
| `account_private_key` / `account_passphrase` | only for `encrypt_payload` webhooks | The company **account** key, used to unwrap an encrypted webhook envelope. |
| `webhooks` / `webhook_secret` | webhook auth — HMAC (default) | Per-webhook HMAC secrets keyed by webhook id (matched via the `X-Allus-Webhook-Id` header). A single-webhook service can use a flat `"webhook_secret": "…"` instead of the map. |
| `webhook_bearer_token` | webhook auth — bearer | Verify `Authorization: Bearer <token>` deliveries. |
| `webhook_basic` | webhook auth — basic | `{"username","password"}` — verify HTTP Basic deliveries. |
| `webhook_header` | webhook auth — header | `{"name","value"}` — verify a custom-header delivery. |
| `webhook_auth_none` | webhook auth — none | `true` — explicit opt-out; `verifyWebhook` always passes (use only behind your own gateway). **Configure at most one** webhook auth method (two+ → `ConfigError`). |
| `cache_dir` | no (default `./allus-cache`) | Durable local buffer for the changes pump. Must be writable + durable. |
| `format` | no (default `json`) | Wire format `json` or `xml`. Invisible in the output. |

Env overrides use the `ALLUS_` prefix of the field name, e.g.
`ALLUS_CLIENT_SECRET`, `ALLUS_KEY_PASSPHRASE`, `ALLUS_ACCOUNT_PASSPHRASE`,
`ALLUS_WEBHOOK_SECRET`. A missing/invalid config (or an unreadable PEM / wrong
passphrase) throws `ConfigError` at construction — fail fast.

### 2. First call — list a connection's values

```php
<?php
require 'vendor/autoload.php';

use Allus\CompanyData\Client;

$client = Client::fromConfig('allus.json');

// Iterate every connected person (lazy, auto-paged Generator).
foreach ($client->connections() as $conn) {
    echo $conn->displayName, ' ', $conn->personId, PHP_EOL;
    foreach ($conn->values as $slug => $val) {
        printf("  %s = %s  (live=%s, updated=%s)\n",
            $slug,
            is_scalar($val->value) ? (string) $val->value : json_encode($val->value),
            $val->live ? 'true' : 'false',
            $val->updatedAt?->format('c') ?? '—',
        );
    }
    break; // just the first one for the demo
}
```

Or fetch one connection by id:

```php
$conn  = $client->connection('019xxxxxxxxxxxxxxxxxxxxxxxxx');
$email = $conn->values['work_email']->value;   // "alice@acme.com"  (a string)
```

`$client = Client::fromEnv();` builds the same client entirely from `ALLUS_*` env
vars (no file).

---

## Every call

`Client` is the only object you construct. Build it from config, then:

```php
Client::fromConfig(string $path, ?HttpClient $http = null, ?Logger $logger = null, ?callable $sleep = null): Client
Client::fromEnv(?HttpClient $http = null, ?Logger $logger = null, ?callable $sleep = null): Client
```

The optional args are advanced: `$http` (an injected `HttpClient`), `$logger` (a
`Allus\CompanyData\Pump\Logger`), `$sleep` (a `callable(float): void`, for tests).

### `requestFields()`

```php
requestFields(): array  // list<RequestField>
```

Your request-field **definitions** — fetched once from
`GET /api/company-data/request-fields` and cached for the life of the client (it
types every value). Returns *your* request config, never the person's fields.

* **Params:** none.
* **Returns:** `list<RequestField>` — each `RequestField` has `slug`, `label`, `type`, `oneTime`, `mandatory`, `raw`. `mandatory` is true when the field is mandatory-to-provide **or** mandatory-to-stay-connected.
* **Throws:** `AuthError`, `ApiError`, `RateLimitError`.

```php
foreach ($client->requestFields() as $f) {
    $flag = $f->mandatory ? 'mandatory' : 'optional';
    printf("%-20s %-10s %s%s\n", $f->slug, $f->type, $flag, $f->oneTime ? ' (one-time)' : '');
}
```

### `connections(limit, offset)`

```php
connections(int $limit = 100, int $offset = 0): \Generator   // Generator<Connection>
```

A **lazy generator** that auto-pages `GET /api/company-data/connections?limit&offset`
and yields one typed `Connection` at a time (bounded memory for a large book).
Each `$conn->values[$slug]` is already decrypted (or a lazy binary handle).

* **Params:** `$limit` — page size (default 100); `$offset` — starting offset.
* **Returns:** `\Generator<int, Connection>`.
* **Throws:** `AuthError`, `ApiError`, `DecryptError` (per value, at access), `RateLimitError` (after the iterator's bounded internal backoff — see [Rate limits](#rate-limits)).

> **Heavily rate-limited.** Use for the initial full sync + occasional
> reconciliation only — never as a poll substitute for the changes feed. The
> generator paces itself within the limit (backs off on `Retry-After`).

```php
// Initial full sync, streaming so a 100k-connection book never lands in memory.
foreach ($client->connections(limit: 200) as $conn) {
    upsertLocalRecord($conn);
}
```

### `connection(id)`

```php
connection(string $id): Connection
```

Fetch one connection by its connection id (`GET /api/company-data/connections/{id}`).

* **Params:** `$id` — the connection id (`Connection->id`).
* **Returns:** one `Connection`. Note: this endpoint returns `{connection_id, user_id, values}` and **no** `display_name`/`connected_at`, so those identity fields are `null` here (the list endpoint carries them).
* **Throws:** `AuthError`, `ApiError` (404 if unknown), `DecryptError`, `RateLimitError`.

```php
$conn  = $client->connection($connId);
$phone = $conn->values['mobile'] ?? null;
if ($phone !== null) {
    echo $phone->value, ' ', $phone->live ? 'live' : 'snapshot', PHP_EOL;
}
```

### `logs(limit, offset)`

```php
logs(int $limit = 50, int $offset = 0): array   // list<LogEntry>
```

The service's activity log (`GET /api/company-data/logs?limit&offset`) — **ops
events only** (email / purge / webhook), never person field data.

* **Params:** `$limit` (default 50), `$offset` (default 0).
* **Returns:** `list<LogEntry>` — each `LogEntry` has `type`, `message`, `metadata`, `at`, `raw`.
* **Throws:** `AuthError`, `ApiError`, `RateLimitError`.

```php
foreach ($client->logs(limit: 20) as $entry) {
    echo $entry->at?->format('c'), ' ', $entry->type, ' ', $entry->message, PHP_EOL;
}
```

### `processChanges(handler, ...$options)`

```php
processChanges(
    callable $handler,                 // callable(Change): void
    int $batchSize = 100,              // clamped to ≤ 500
    int $maxRetries = 3,
    string $onError = 'deadletter',    // 'deadletter' | 'halt'
    ?callable $backoff = null,         // callable(int $attempt): float (seconds)
): void
```

The crash-safe changes pump: drains the feed through `$handler` **one `Change` at
a time**, durably buffering each batch before delivery, with per-item ack and
retry → dead-letter → continue. Runs **until the feed is empty, then returns** —
there is **no follow/daemon mode** (you schedule re-runs yourself). Delivery is
**at-least-once**, so your handler **must be idempotent** (dedup on `Change->id`).
See [The changes pump](#the-changes-pump) for the full model.

* **Params:** `$handler` — your callback; called with one `Change`. A normal return is an ack; a thrown exception triggers retry.
* **Options:** `$batchSize` (clamped to ≤ 500, default 100), `$maxRetries` (default 3), `$onError` (`'deadletter'` — default — or `'halt'`), `$backoff` (`callable(int): float`, attempt → seconds).
* **Returns:** `void` (when the feed is empty + the buffer is drained).
* **Throws:** `AuthError`, `ApiError`, `RateLimitError` (during a drain); `InvalidArgumentException` (bad `$onError`); whatever the handler throws if `$onError='halt'` and retries are exhausted.

```php
$client->processChanges(function (\Allus\CompanyData\Model\Change $change): void {
    if (alreadyProcessed($change->id)) {   // idempotency — dedup on the stable id
        return;
    }
    match ($change->event) {
        'field_updated'                       => store($change->personId, $change->slug, $change->value),
        'field_deleted', 'connection_deleted' => remove($change->personId, $change->slug),
        default                               => null,
    };
    markProcessed($change->id);
});                                          // returns when the feed is empty
```

> `$logger` is **not** a `processChanges` option in this SDK — pass it once to the
> `Client` constructor (`Client::fromConfig('allus.json', logger: $myLogger)`).

### Advanced changes primitives

```php
drainBatch(int $max = 100): array                      // list<Change> — raw, UNBUFFERED (you own durability)
deadLetters(): array                                   // list<array> — the local dead-letter store
retryDeadLetters(callable $handler, ...$options): int  // re-drive dead-lettered events; returns count re-driven
```

* `drainBatch($max)` — fetches one batch (clamped ≤ 500) and returns the decrypted `Change`s directly. It does **not** persist anything, so a crash loses what the API already deleted. Prefer `processChanges` for safe consumption.
* `deadLetters()` — each entry is the stored (ciphertext) event plus a flattened `error` and `attempts` (and the event's `id`).
* `retryDeadLetters($handler, ...)` — same `$maxRetries` / `$onError` / `$backoff` options as `processChanges`; on success a record is removed, on repeated failure it stays dead-lettered (or re-throws under `'halt'`). Dead letters are never re-fetched from the API — the local store is their only home.

```php
foreach ($client->deadLetters() as $dl) {
    printf("stuck: %s %s after %d attempts\n", $dl['id'], $dl['error'], $dl['attempts']);
}

$n = $client->retryDeadLetters($handler);   // after you've fixed the bug
echo "re-drove {$n} dead letters", PHP_EOL;
```

### Webhook helpers (on the client)

The webhook receiver helpers are also exposed as `Client` methods (they delegate
to `Allus\CompanyData\Webhooks\Webhooks`, fully config-driven — no key/secret
arguments):

```php
$client->verifyWebhook(string $rawBody, array $headers): bool
$client->parseWebhook(string $rawBody, array $headers):  Change
$client->handleWebhook(string $rawBody, array $headers): Change   // verify + parse
```

* `verifyWebhook` — recomputes `HMAC-SHA256($rawBody, secret)` and constant-time-compares it (`hash_equals`) to `X-Allus-Signature`. Returns `true`/`false`; **never throws** for a bad signature.
* `parseWebhook` — body → a typed `Change`. Does **not** verify. Handles JSON, XML, and the `encrypt_payload` account-key envelope. Throws `WebhookError` on a malformed/unparseable body.
* `handleWebhook` — verify **then** parse; throws `WebhookError` on a bad/unknown signature, otherwise returns the `Change`. The typical one-liner inside a route.

The same three are available as static functions on `Allus\CompanyData\Webhooks\Webhooks`,
which take the `Config` and the decrypt/type closures explicitly — but inside an
app you'll almost always use the client methods. See [Webhooks](#webhooks).

---

## The typed value model

You work with these objects and nothing else (`use Allus\CompanyData\Model\…`):

```text
RequestField { slug, label, type, oneTime, mandatory }       // YOUR request config
Connection   { id, personId, displayName, connectedAt, values: array<slug, Value> }
Value        { value, live, updatedAt }
Change       { id, event, personId, slug?, value?, live?, at }
LogEntry     { type, message, metadata, at }
```

All model properties are `public readonly`.

### Keyed by *your* slug

`$conn->values['work_email']->value` → `"alice@acme.com"`. The key is the stable,
explicit slug you set per request field in the portal — rename the label freely,
the slug is the contract. **The person's source field is never exposed**: no
source slug, no `field_id`, not even via `->raw`.

### `Value`

| Property | Meaning |
|----------|---------|
| `value` | The typed plaintext (see the table below). |
| `live` | `true` if the person chose "keep connected" (auto-updates); `false` for a one-time snapshot. |
| `updatedAt` | `?DateTimeImmutable` of when this answer last changed (per-answer, rides on the `Value`). |

### Value types (from the field's `type`)

| Field type | PHP `value` |
|------------|-------------|
| `email`, `phone`, `url`, `text` | `string` |
| `address`, `bank`, `creditcard` | `array` — the decrypted plaintext is a JSON object, parsed for you |
| `date`, `date_of_birth` | `DateTimeImmutable` (date-only, UTC midnight; falls back to the raw string if it can't be parsed) |
| `photo`, `document`, `legal_document` | a lazy `BinaryHandle` — see below |
| unanswered / no value | `null` |

```php
$addr = $conn->values['home_address']->value;   // array, e.g. ['street' => '...', 'city' => '...', ...]
$dob  = $conn->values['birthday']->value;         // DateTimeImmutable
```

### Binary fields — the lazy `BinaryHandle`

A photo/document value is a `BinaryHandle`. Nothing is fetched or decrypted until
you call `->bytes()` or `->save()`:

```php
$handle = $conn->values['passport_scan']->value;   // BinaryHandle (no network yet)

$data = $handle->bytes();                           // GET the slot file → decrypt → file bytes (string)
$n    = $handle->save('/tmp/passport.jpg');         // same, written to disk; returns bytes written
echo $handle->valueUrl();                            // the opaque slot-keyed URL it fetches from
```

`->bytes()` GETs the slot-keyed file endpoint, unwraps the API's
`{"encrypted": true, "value": <wrapper>}` envelope, decrypts with your service
key, parses the inner JSON envelope (`{"full": "data:…"}` for photos,
`{"file": "data:…"}` for documents) and base64-decodes the data URI into the file
bytes. The result is cached on the handle, so repeated calls don't re-fetch.
`->save()` writes crash-safely (temp file → fsync → atomic rename).

### `Change`

A change-feed / webhook event.

| Property | Meaning |
|----------|---------|
| `id` | **The stable server change-row id — your dedup key** (captured before the server delete). |
| `event` | `connection_created`, `connection_deleted`, `field_updated`, `field_deleted`, `consent_accepted`, `consent_declined`. |
| `personId` | The person the change is about (may be `null`). |
| `slug`, `value`, `live` | Present only on `field_updated`; `value` is typed exactly like `Value->value` (incl. a lazy `BinaryHandle` for binaries). Connection/consent events carry no slot/value. |
| `at` | `?DateTimeImmutable` of the change. (There is no separate `updatedAt` on a change.) |

### `->raw`

Every model carries `->raw` — the underlying *hardened* API array — for debugging
or an edge case the SDK didn't model. It still never contains the person's source
field.

See [`docs/model.md`](docs/model.md) for the full reference.

---

## The changes pump

The changes feed is a server-side **drain-on-fetch queue**:
`GET /api/company-data/changes?limit=N` returns up to N events (default 100, max
500) **and deletes exactly those rows in the same transaction** — no
offset/cursor, and the API keeps no copy afterward. So consumption can't be a
plain list: a consumer crash mid-batch would lose events the API already deleted,
and a huge backlog must not materialize in memory. `processChanges` solves both.

**Per run, repeating until the feed is empty then returning:**

1. **Replay first.** Deliver any un-acked events already in the local buffer (from a previous crashed run), oldest-first.
2. **Drain.** When the buffer is empty, fetch one batch and **persist it to the durable file buffer (fsync) BEFORE handing anything out.** This is the backup the API no longer has.
3. **Deliver one-by-one.** For each buffered event, oldest-first: decrypt its value *at delivery* (never on disk), build the typed `Change`, call `$handler`.
4. **Ack / retry / dead-letter.** On success, remove the event from the buffer (ack). On a handler error, retry with backoff up to `maxRetries`; then either move it to the dead-letter store and continue (`onError='deadletter'`, default — one poison event never wedges the stream) or stop and re-throw (`onError='halt'`). A `DecryptError` on a buffered event (corrupt/truncated ciphertext, rotated key) is **dead-lettered immediately** — re-decrypting can't fix it, so it does *not* burn retries (under `onError='halt'` it re-throws). Either way it never propagates out and wedges replay.
5. Repeat until a drain returns empty **and** the buffer is drained → return.

### The durable buffer

* Plain files under `cache_dir` (zero extra dependencies): `pending/` for un-acked events, `deadletter/` for ones that exhausted retries.
* Stored events keep their **ciphertext** value — **no plaintext PII is ever written to disk**. Decryption happens only at delivery.
* Writes are crash-safe (temp file → `fsync` → atomic `rename` → dir `fsync`). Files are named with a monotonic, zero-padded sequence so they replay oldest-first.

### Crash safety, at-least-once, and idempotency

A batch is durably buffered *before* any delivery, and acked per-item only *after*
the handler succeeds. The ack can't be atomic with your side-effects — a crash
between your handler's success and its ack re-delivers that event on the next run.
That makes delivery **at-least-once**, so:

> **Your handler must be idempotent. Dedup on `Change->id`.**

`Change->id` is the stable server change-row id, captured before the server delete,
so it survives crash + replay unchanged.

### No follow mode

`processChanges` returns when the feed empties. **You** schedule re-runs — a cron
job, a `while (true) { $client->processChanges($handler); sleep(5); }` loop, a
worker queue, whatever fits. The feed is cheap to poll (see
[Rate limits](#rate-limits)).

### Worked example

```php
<?php
require 'vendor/autoload.php';

use Allus\CompanyData\Client;
use Allus\CompanyData\Model\Change;

$client = Client::fromConfig('allus.json');

$handle = function (Change $change): void {
    if (seen($change->id)) {          // idempotent: skip anything already applied
        return;
    }
    match ($change->event) {
        'field_updated'  => storeValue($change->personId, $change->slug, $change->value, $change->live),
        'field_deleted'  => clearValue($change->personId, $change->slug),
        'connection_deleted' => dropPerson($change->personId),
        'connection_created', 'consent_accepted', 'consent_declined'
                         => noteEvent($change->personId, $change->event, $change->at),
        default          => null,
    };
    recordSeen($change->id);
};

// Schedule your own re-runs; processChanges itself returns when empty.
while (true) {
    $client->processChanges($handle, batchSize: 200, maxRetries: 5);
    sleep(5);
}
```

If a handler keeps failing, the event lands in the dead-letter store instead of
blocking the stream; inspect with `$client->deadLetters()` and re-drive with
`$client->retryDeadLetters($handle)` after fixing the cause. See
[`docs/pump.md`](docs/pump.md).

---

## Webhooks

Webhooks are the lower-latency push alternative to polling the changes feed. The
platform POSTs each change event to your configured webhook URL with:

* `X-Allus-Webhook-Id` — which webhook this is (selects the HMAC secret from config).
* `X-Allus-Signature` — `HMAC-SHA256(rawBody, secret)` as lowercase hex.
* the body — the same slug-keyed `Change` shape as the pull feed (JSON or XML).

All secrets/keys come from config; the helpers take **no key or secret
arguments**. Use the raw request body bytes (do not re-serialize a parsed body —
the HMAC is over the exact bytes the platform sent, and the SDK parses XML in an
XXE-safe way over those raw bytes).

### In a web route — framework-agnostic (raw PHP)

```php
<?php
require 'vendor/autoload.php';

use Allus\CompanyData\Client;
use Allus\CompanyData\Errors\WebhookError;

$client = Client::fromConfig('allus.json');

$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];   // ['X-Allus-Signature' => '…', …]

try {
    $change = $client->handleWebhook($rawBody, $headers);
} catch (WebhookError) {
    http_response_code(401);   // bad / unknown signature, or unparseable envelope
    exit;
}

// Same idempotency rule as the pump: dedup on $change->id.
if (!seen($change->id)) {
    applyChange($change);
    recordSeen($change->id);
}
http_response_code(204);
```

If you only have `$_SERVER` (no `getallheaders()`), reconstruct the headers the
SDK needs — it only reads `X-Allus-Webhook-Id` and `X-Allus-Signature` (lookup is
case-insensitive):

```php
$headers = [
    'X-Allus-Webhook-Id' => $_SERVER['HTTP_X_ALLUS_WEBHOOK_ID'] ?? '',
    'X-Allus-Signature'  => $_SERVER['HTTP_X_ALLUS_SIGNATURE'] ?? '',
];
```

### In a PSR-7 route (e.g. Slim)

```php
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Allus\CompanyData\Errors\WebhookError;

$app->post('/allus/webhook', function (Request $request, Response $response) use ($client) {
    $rawBody = (string) $request->getBody();
    // PSR-7 getHeaders() returns array<string, string[]>; the SDK looks up
    // X-Allus-* case-insensitively and takes the first value of an array.
    try {
        $change = $client->handleWebhook($rawBody, $request->getHeaders());
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

`verifyWebhook` / `parseWebhook` let you split the steps if you prefer:

```php
if (!$client->verifyWebhook($rawBody, $headers)) {
    http_response_code(401);
    exit;
}
$change = $client->parseWebhook($rawBody, $headers);
```

### Config-driven secrets

Per-webhook HMAC secrets live in the config `webhooks` map, keyed by webhook id;
the SDK reads `X-Allus-Webhook-Id` off the request and looks up the matching
secret. A single-webhook service can use the flat `"webhook_secret": "…"` shortcut
(or `ALLUS_WEBHOOK_SECRET`). An unknown/unconfigured id ⇒ verification returns
`false` (and `handleWebhook` throws `WebhookError`).

### The `encrypt_payload` account-key envelope

If a webhook has `encrypt_payload` enabled, the body is **replaced** by a
`{"_enc":1,…}` envelope encrypted to your company **account** key (and the HMAC is
over that envelope — the final bytes sent). `parseWebhook`/`handleWebhook` unwrap
it transparently using the configured `account_private_key` +
`account_passphrase`, then decrypt the inner field value with the service key — so
an encrypted-payload `Change` is identical to a plain one. If you receive such a
webhook without an `account_private_key` configured, you get a `WebhookError`.

> The account-key envelope uses OAEP-**SHA1** (OpenSSL's default), distinct from
> the OAEP-SHA256 used for person field values — the SDK handles this difference
> internally; you only supply the account key in config.

See [`docs/webhooks.md`](docs/webhooks.md).

---

## Rate limits

| Endpoint | Limit | Use it for |
|----------|-------|-----------|
| `changes` (the pump) | **generous** | Poll **as often as you like** — it's a cheap drain-on-fetch queue. |
| `request-fields`, `logs` | moderate | Occasional reads. |
| `connections`, `connection(id)`, binary `/file` | **heavily limited** | Initial full sync + occasional reconciliation **only** — never as a poll substitute. |

A 429 carries `Retry-After`. The SDK backs off and retries automatically:

* The transport (`HttpClient`) retries a 429 a bounded number of times honoring `Retry-After`, then throws `RateLimitError`.
* The `connections(...)` generator additionally backs off per `Retry-After` on a surfaced `RateLimitError` and retries the page a bounded number of times before re-throwing — so it paces itself within the limit instead of hammering.

If you catch a `RateLimitError`, its `->retryAfter` is the seconds to wait (or
`null` when the header was absent).

---

## Errors

All under `Allus\CompanyData\Errors\…`. Same taxonomy + names across all six SDKs.

| Error | When |
|-------|------|
| `ConfigError` | Missing/invalid config, unreadable key file, or wrong passphrase — at construction (fail fast). |
| `AuthError` | Token fetch/refresh failed (bad `client_id`/`secret`, revoked client); or a 401 survives the one automatic refresh-and-retry. |
| `ApiError` | Any non-2xx from the API; carries `->status`, `->errorKey` (when present), and the message. |
| `DecryptError` | A ciphertext wrapper is malformed, the key is wrong, or the GCM tag mismatches. Surfaces when a value is accessed/decrypted. |
| `WebhookError` | Signature verification failed, or an envelope couldn't be unwrapped/parsed. |
| `RateLimitError` | A 429 from a rate-limited endpoint. Subclass of `ApiError` (status fixed at 429); carries `->retryAfter` (seconds, or `null`). |

```php
use Allus\CompanyData\Client;
use Allus\CompanyData\Errors\{ConfigError, AuthError, ApiError, DecryptError, WebhookError, RateLimitError};

try {
    $client = Client::fromConfig('allus.json');
    foreach ($client->connections() as $conn) {
        // …
    }
} catch (ConfigError $e) {
    // fix the config / key file
} catch (RateLimitError $e) {
    waitSeconds($e->retryAfter ?? 60);
} catch (ApiError $e) {
    log($e->status, $e->errorKey, $e->getMessage());
}
```

`ApiError`/`RateLimitError` are not `final` (the latter extends the former);
`ConfigError`, `AuthError`, `DecryptError`, `WebhookError` are `final`.

See [`docs/errors.md`](docs/errors.md).

---

## How it's wired

Everything below is what the SDK hides so your code only ever sees conclusions.

**Auth / token.** An `HttpClient` owns a `client_credentials`-only token. On the
first call (or when the cached token nears expiry) it POSTs
`client_id`/`client_secret` to `{api_url}/oauth2/token` and caches the bearer
token + its expiry; refresh is automatic. A mid-flight 401 triggers exactly one
refresh-and-retry, then `AuthError`. The token is scoped server-side to **one**
service, so every call is implicitly that service's data. The HTTP layer goes
through a small `Transport` seam (`CurlTransport` by default; tests inject a fake).

**Slug resolution.** `requestFields()` is fetched once and cached; its slug→type
map types every value (so `address` parses to an array, `photo` becomes a lazy
binary handle, etc.). The connection/changes endpoints return values keyed by
**your** request slug — the person's source field is dropped server-side and
never reaches the SDK.

**Decryption (zero-knowledge).** The service private key is loaded **once** at
construction from the configured encrypted PEM + passphrase into an in-memory
phpseclib RSA key. A `decryptValue` closure over it is handed to every model
factory and the pump — the key never appears in a method signature. Each value is
a hybrid wrapper (`{"_enc":1,"k":rsa_oaep_sha256(aesKey),"iv":…,"d":aes256gcm(…)}`);
the SDK RSA-OAEP-SHA256 (MGF1-SHA256) unwraps the AES key via **phpseclib**
(PHP's `openssl_private_decrypt` can only do SHA-1 OAEP), then AES-256-GCM
decrypts the payload via the openssl ext. **The platform only ever holds
ciphertext — it never sees your plaintext.**

**Binary fetch.** A binary value is a lazy `BinaryHandle` over a slot-keyed
`value_url`. On `->bytes()`/`->save()` it GETs that file endpoint, unwraps the
`{"encrypted":true,"value":<wrapper>}` envelope, runs the same service-key decrypt
to a JSON file-envelope, and base64-decodes its data URI to the file bytes.
(Slot-keyed, never source-field-keyed.)

**The drain-on-fetch feed.** `processChanges` delegates to a `Pump` wired to a
`fetchChanges` closure (`GET /changes?limit=`, returning raw ciphertext events)
and a `decrypt` closure (builds a typed `Change`). Because the fetch deletes the
rows it returns, the pump persists each batch to the durable file buffer
(ciphertext at rest) before delivery, acks per-item after your handler succeeds,
and replays the buffer on restart — see [The changes pump](#the-changes-pump).

**XML safety.** When `format: "xml"`, responses (and webhook bodies) are parsed
with a hardened `DOMDocument` (XXE-safe: `LIBXML_NONET`, DOCTYPE rejected, no
entity substitution). The webhook HMAC is always computed over the raw bytes,
never the parsed tree.

---

## Development

```bash
composer install        # pulls phpseclib3 + phpunit
composer test           # vendor/bin/phpunit
```

The test suite proves crypto parity with the other five SDKs against a shared,
cross-language decryption fixture: it loads the PBES2 service PEM, decrypts a text
wrapper to its known plaintext, and decrypts a binary wrapper through the envelope
to the expected inner-bytes hash. It also runs an independent `openssl` CLI
cross-check, so the crypto is proven platform-correct, not merely self-consistent.
