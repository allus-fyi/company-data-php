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

> **Example:** a runnable website demonstrating every identity scenario
> (Sign in with allme, OIDC login, 2FA by allme) through this SDK lives in
> [`examples/identity/`](examples/identity/) — one command (`composer start`) and
> a browser. See its [README](examples/identity/README.md).

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

### Key rotation — `key_rotated` and the public-key cache

Every client caches the RSA public keys it fetches: a person's key is immutable — until they
**rotate** it. A person learns of a rotation from a silent push; your service gets no pushes, so the
`key_rotated` change is your **only** signal. Without it a long-running worker keeps encrypting to
the rotated-away key for its whole lifetime, and the person can never read those values.

**On the pump this is automatic** — the cached key is dropped as the change passes through, before
your handler sees it. **Over a webhook it is not:** the signature verifier is static and has no
client instance, so it cannot reach the cache. Call the invalidator yourself — noting that the two
clients key their caches **differently**: the service client by `share_code`, the customer client by
the person's **user id**. Passing a share code to the customer client removes nothing and leaves you
encrypting to the old key. Both identifiers ride every change, alongside `public_key_sha256` — the
fingerprint of the person's new key.

```php
if ($change->event === 'key_rotated') {
    $client->invalidatePublicKey($change->shareCode);     // service Client — keyed by SHARE CODE
    $customer->invalidatePublicKey($change->personId);    // CustomerClient — keyed by PERSON USER ID
    // $change->publicKeySha256 = fingerprint of the NEW key, if you want to verify the refetch
}
```

This is **eventual, not fail-closed** — nothing rejects a document encrypted to a stale key, so a
window remains between the rotation and your next drain. Drain often if that window matters.

### `service_key_rotated` — the same thing, the other way round

The customer client also caches the **service's** public key, the one you encrypt your consent
answers and documents *to*, keyed `"companyCode/serviceCode"`. When that company replaces its
service keypair, the `service_key_rotated` change on your account feed is your only signal — you
receive no pushes. Same shape, same guarantees, same automatic handling on the pump:

```php
if ($change->event === 'service_key_rotated') {
    // Automatic on the pump. Over a webhook, from the raw event body:
    $customer->invalidateServiceKey($body['company_share_code'], $body['service_share_code']);
    // $body['service_public_key_sha256'] = fingerprint of the service's NEW key
}
```

Also **eventual, not fail-closed**. Note the identifiers are **share codes**, not the ids used by
`invalidatePublicKey` — the two caches are keyed differently and the wrong call removes nothing.

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

## Company documents

The service can also publish **documents** — contracts, statements, terms, or any
structured/binary payload — either **broadcast** to everyone connected or addressed
to **one person**. The encryption rule is simple and automatic:

* **A per-person document is ALWAYS end-to-end encrypted** to that recipient's
  public key — for *any* value of `is_private`. The SDK fetches the recipient key
  (from the `share_code`, or resolved from the `connection_id` / `person_user_id`)
  and encrypts before sending. As always, **no method takes a key or secret
  argument** — keys come from your config.
* **A broadcast document (no target) is plaintext.** It cannot be locked, so
  `is_private=true` without a per-person target **throws** `ConfigError`.
* `is_private` is **device-display-only** (it tells the recipient's app to show a
  lock / decrypt-on-load instead of rendering inline) — it does **not** change the
  value shape or whether encryption happens. Per-person ⇒ encrypted, broadcast ⇒
  plaintext, regardless of `is_private`.

`payload_kind` is either `'json'` (a structured value) or `'file'` (raw bytes,
optionally with a MIME type; for `'file'` the metadata row is created first, then
the bytes are uploaded — encrypted for a per-person target, raw for a broadcast).

### `createDocument(array $opts)`

```php
createDocument(array $opts): Document
```

```php
use Allus\CompanyData\Client;

$client = Client::fromConfig('allus.json');

// A BROADCAST plaintext JSON document — visible to everyone connected, no target.
$terms = $client->createDocument([
    'name'         => 'Terms of Service v3',
    'payload_kind' => 'json',
    'json_value'   => ['version' => 3, 'effective' => '2026-07-01', 'url' => 'https://acme.example/tos'],
    // no connection_id / person_user_id  → broadcast → plaintext
    // is_private MUST stay false here (a broadcast can't be locked)
]);
echo $terms->id, ' ', $terms->status, PHP_EOL;

// A PER-PERSON document — automatically end-to-end encrypted to the recipient.
// Address it with ONE of: connection_id, person_user_id, or share_code.
$contract = $client->createDocument([
    'name'          => 'Service Agreement',
    'payload_kind'  => 'json',
    'json_value'    => ['plan' => 'pro', 'monthly' => 4900, 'currency' => 'EUR'],
    'connection_id' => $someConnectionId,   // or 'person_user_id' => …, or 'share_code' => 'AB12CD'
    'is_private'    => true,                 // device-display-only; encryption happens regardless
    'status'        => 'offering',
    'metadata'      => ['ref' => 'AGR-2026-118'],
]);

// Read a per-person JSON document back — decryption happens transparently with
// the SDK's own service key (only for the per-person, encrypted shape):
$plain = $client->document($contract->id)->json();   // ['plan' => 'pro', …]

// A file document (raw bytes). Per-person → encrypted; broadcast → plaintext.
$signed = $client->createDocument([
    'name'          => 'Signed PDF',
    'payload_kind'  => 'file',
    'file_bytes'    => file_get_contents('/tmp/agreement.pdf'),
    'file_mime'     => 'application/pdf',
    'person_user_id'=> $personUserId,        // per-person → bytes encrypted on upload
]);
```

* **Options** (associative array): `name` (required), `payload_kind` (`'json'`|`'file'`, required), `is_private` (default `false`), `kind` (default `'document'`), `description`, `status`, `metadata`, and **one** target — `connection_id`, `person_user_id`, or `share_code` (omit all three for a broadcast). For `'json'`: `json_value`. For `'file'`: `file_bytes` (+ optional `file_mime`).
* **Returns:** the created `Document`.
* **Throws:** `ConfigError` (missing `name`, bad `payload_kind`, `is_private=true` with no target, or a missing `json_value`/`file_bytes`); `AuthError`, `ApiError`, `RateLimitError`.

### `listDocuments(...)` / `document($id)`

```php
listDocuments(?string $personUserId = null, ?string $status = null, int $limit = 100, int $offset = 0): array  // list<Document>
document(string $documentId): Document
documentFile(string $documentId): string   // #491: the file BYTES
```

```php
foreach ($client->listDocuments(status: 'active', limit: 50) as $doc) {
    echo $doc->id, ' ', $doc->name, ' [', $doc->status, ']', PHP_EOL;
}

$doc = $client->document($documentId);
$value = $doc->payloadKind === 'json' ? $doc->json() : $doc->value;   // json() decrypts per-person docs
```

* `listDocuments` filters optionally by `personUserId` and/or `status` and pages with `limit`/`offset`.
* `document($id)` fetches one. Call `->json()` on a `'json'` document to get the plaintext (it transparently decrypts a per-person, encrypted document; a broadcast doc is already plaintext).
* `documentFile($id)` (#491) downloads a `'file'` document's BYTES — the metadata methods don't include them. A **broadcast** (plaintext) document's bytes are returned as-is; a **per-person / private** document is encrypted to the *recipient's* key (not your service key), so `documentFile` fails clearly with `documents.recipient_encrypted` rather than a doomed decrypt. For a generated flow contract's own copy use `flowRunDocument($runId)` below (that copy IS service-key-encrypted).

### Contract flows & identity (#491)

```php
flowRunAnswers(FlowRun|string $run): array    // #491 gap 1 — a completed run's DECRYPTED answers {slug: plaintext}
flowRunDocument(string $runId): string        // #491 gap 2 — the company's own copy of a run's generated contract (plaintext bytes)
identity(): array                             // #491 gap 3 — this client's {company_user_id, service_id}
```

* `flowRunAnswers($run)` returns a completed run's decrypted `{slug => plaintext}` answers (accepts a fetched `FlowRun` or a run id). It is the public accessor for a finished run's answers, which `processFlowRun` returns untouched.
* `flowRunDocument($runId)` downloads the company's own service-key-encrypted copy of a run's generated contract and returns the plaintext file bytes (`404` until the run generates a document) — the honest completion step (fill → complete → `flowRunAnswers` → `flowRunDocument`).
* `identity()` returns this client's `{company_user_id, service_id}` from `GET /api/company-data/whoami`, so a `triggerFlowRun` binding's **company** party can bind to `company_user_id` (the person party's user_id comes from the connection).

> **Example:** a runnable website that drives a contract flow end-to-end through
> these calls — trigger, type-checked step filling, a person turn on the phone, then
> the decrypted answers + downloaded document — is in
> [`examples/flow/`](examples/flow/): one command (`composer start`) and a browser.
> See its [README](examples/flow/README.md).

### `updateDocumentStatus` / `updateDocumentMetadata` / `deleteDocument`

```php
updateDocumentStatus(string $documentId, string $status): Document
updateDocumentMetadata(string $documentId, ?array $metadata = null, ?string $name = null, ?string $description = null): Document
deleteDocument(string $documentId): void
```

```php
$client->updateDocumentStatus($documentId, 'ready_to_sign');   // offering | ready_to_sign | active | active_but_ending | ended
$client->updateDocumentMetadata($documentId, name: 'Service Agreement (rev B)', metadata: ['ref' => 'AGR-2026-118b']);
$client->deleteDocument($documentId);                          // also removes the on-disk file
```

* `updateDocumentStatus` moves a document through its lifecycle (`offering` → `ready_to_sign` → `active` → `active_but_ending` → `ended`).
* `updateDocumentMetadata` updates `name`, `description`, and/or `metadata` — pass at least one (else `ConfigError`).
* `deleteDocument` deletes the document and its stored file.

### Reacting to a status change in the pump

When a document's lifecycle status changes, the feed/webhook emits a
`document_status_changed` `Change` carrying `documentId` + the new `status` (and
the usual `personId` / `shareCode` / `at`). Handle it alongside your field events:

```php
$client->processChanges(function (\Allus\CompanyData\Model\Change $change): void {
    if (alreadyProcessed($change->id)) {
        return;
    }
    match ($change->event) {
        'field_updated'           => store($change->personId, $change->slug, $change->value),
        'document_status_changed' => onDocumentStatus($change->documentId, $change->status, $change->personId),
        default                   => null,
    };
    markProcessed($change->id);
});
```

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
| `email`, `phone`, `url`, `text` | `string` — `phone` is a single E.164-style string (`+` and digits) |
| `country`, `nationality` | `string` — an ISO 3166-1 alpha-2 code (e.g. `'US'`, `'NL'`); not a display name |
| `address`, `bank`, `creditcard` | `array` — the decrypted plaintext is a JSON object, parsed for you |
| `date`, `date_of_birth` | `DateTimeImmutable` (date-only, UTC midnight; falls back to the raw string if it can't be parsed) |
| `photo`, `document`, `legal_document` | a lazy `BinaryHandle` — see below |
| unanswered / no value | `null` |

`country`/`nationality` values are 2-letter ISO codes, and an `address`'s
`country`/`state` sub-fields are an ISO alpha-2 code / USPS 2-letter state code
respectively. `FieldValidation::isFieldValueValid($type, $value)` validates these
against the bundled country dataset; `FieldValidation::isValidCountryCode($code)` /
`FieldValidation::dialCodeFor($code)` check a code or look up its E.164 dial code.

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
| `event` | `connection_created`, `connection_deleted`, `field_updated`, `field_deleted`, `consent_accepted`, `consent_declined`, `document_status_changed`. |
| `personId` | The person the change is about (may be `null`). |
| `shareCode` | The person's profile share code — present on every event (may be `null`). |
| `slug`, `value`, `live` | Present only on `field_updated`; `value` is typed exactly like `Value->value` (incl. a lazy `BinaryHandle` for binaries). Connection/consent events carry no slot/value. |
| `documentId`, `status` | Present only on `document_status_changed` — the document's id and its new lifecycle status. |
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

applyChange($change);   // see "Delivery contract" below before adding dedup
http_response_code(200);   // 200 — the ONLY status allus counts as delivered
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
    applyChange($change);
    return $response->withStatus(200);
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

### Delivery contract — effectively unique, rarely replayed

Each queued event is POSTed **once**, and **only HTTP `200` counts as delivered** — a
`202`, a `204`, a 3xx redirect and every 4xx/5xx are all treated as a failure. On anything
other than `200` (or a timeout or connection error) the event is **not retried in place**:
it and the rest of the webhook's queue move to a durable server-side backlog and the
webhook is marked bad. The backlog is delivered later, either automatically when
the webhook next probes healthy, or when you drain it yourself with
`GET /api/company-data/changes?webhook_id=…` (delete-on-read).

So deliveries are **effectively unique** — with one rare exception. If your endpoint
processed an event but the platform never saw your `200` (your response timed out, or
you crashed after committing but before responding), the event is treated as failed
and replayed on recovery, so you receive it **again**. Nothing caps that at two: a
failed probe leaves its backlog row in place, so every later recovery attempt whose
`200` is likewise lost replays the same event once more. Inside that window the
contract is **at-least-once** — plan for one or more repeats, not for exactly one.

> **Do not use `$change->id` as an idempotency key here.** On the webhook path the id is
> neither reliably stable nor reliably fresh, and a receiver cannot tell which one it is
> holding. A **live** delivery is built with no change row behind it, so its id is minted
> for that single POST — the later replay of the same event is rebuilt from a durable
> backlog row and therefore carries a **different** id. But a replayed delivery carries
> **that row's** id, and the row stays in place until it is delivered successfully, so a
> re-attempted replay arrives with the **same** id — which changes again if the event is
> re-backlogged after a further failure. An id check therefore misses the duplicate you
> are most likely to see and matches only a rarer one; it is not a contract. If you need
> strict idempotency, key on the **content** — event + person + slug/document + payload —
> never on the id.

**Webhooks and the pull feed are alternative integrations — consume one, never both.**
The id-dedup guidance under [The changes pump](#the-changes-pump) applies to the pump
only, where `$change->id` is the real server change-row id.

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

## Sign in with allme (OAuth, #195)

```php
use Allus\CompanyData\OAuthClient;

$oauth = OAuthClient::fromConfig('idw-config.json');
$url = $oauth->authorizeUrl('signin', state: $state, codeChallenge: $ch);
// ...user approves; your redirect receives ?code=...
$res = $oauth->completeSignIn($code, $verifier); // $res['user'], $res['mode'], $res['values']
```

Modes: `signin` | `one_time` (claim values decrypted for you) | `connect` |
`2fa_enroll` (opt a person into 2FA — see below). `pollResult($state)` drives the detached mode.

## 2FA by allme (#436, #481)

Ask a connected person to approve a login inside the allme app. On the same service data client (no new
config), via the `twoFactor()` sub-client:

```php
use Allus\CompanyData\Client;

$client = Client::fromConfig('allus.json');

// Raise a challenge. The idempotency key is REQUIRED — a repeat with the same key within the TTL returns
// the SAME challenge and sends no second push. The context is plain text shown on the person's card.
$ch = $client->twoFactor()->challenge('2I6UF3', 'login-8f3c1a', 'Sign-in from Chrome');
if ($ch->matchingDigits !== null) {                // number matching is on for this service
    showOnLoginPage($ch->matchingDigits);          // the person types these back into the app; the server checks them
}

// Wait for the terminal outcome — polls result() for you (defaults: 600s timeout, 2s interval),
// throws ApiError on timeout.
$res = $client->twoFactor()->waitForResult($ch->challengeId); // or result($ch->challengeId) to poll once yourself
if ($res->status === 'approved') {
    grantLogin();
}
```

- **Burn-on-read.** The first read of a terminal state (`approved` | `denied` | `expired` | `revoked`)
  delivers it and burns it — a later read is `gone`. Read it once and persist your own outcome;
  `waitForResult` returns that first terminal read and never re-reads a consumed challenge.
- **Webhook variant.** The `2fa_challenge_completed` change/webhook carries the same terminal `status`, so a
  webhook consumer need not poll. **Expiry fires no webhook/Change** — only `approved`/`denied`/`revoked`
  reach the feed, so a lapsed challenge is observable only by polling.
- **Enrollment.** Only an enrolled person can be challenged (an un-enrolled `share_code` is `404`).
  Enrollment is a one-time consent on the `web.allme.fyi/auth` surface via the OAuth helper's `2fa_enroll`
  mode — a redirect button (`$oauth->authorizeUrl('2fa_enroll', state: $state)`), or server-to-server with
  `responseMode: 'detached'` + `pollResult($state)`, which returns `['enrolled' => true, 'state' => ...]`
  once the person confirms.
- **Errors.** `404` (unknown / not-enrolled share code). A `429` is either the plain rate limit (retried with
  backoff → `RateLimitError`) or `twofa.pending_cap` (too many challenges already open for this person) — the
  latter surfaces immediately as `ApiError` and is never retried, since a retry cannot clear it.
