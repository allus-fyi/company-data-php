# Output model reference

The conclusions — the only objects you work with, under `Allus\CompanyData\Model\…`
(plus `Allus\CompanyData\Crypto\BinaryHandle`). Each carries `->raw` (the
underlying hardened API array; never contains the person's source field). All
properties are `public readonly`.

## `RequestField`

Your request-field **definition** — your config, never the person's fields.
Returned by `$client->requestFields()`.

```php
final class RequestField {
    public readonly ?string $slug;   // the stable, company-set key — the contract for value access
    public readonly ?string $label;  // the human label (rename freely; the slug stays)
    public readonly ?string $type;   // email|phone|url|text|address|bank|creditcard|date|date_of_birth|photo|document|legal_document
    public readonly bool   $oneTime; // a one-time snapshot vs a live (auto-updating) answer
    public readonly bool   $mandatory; // mandatory-to-provide OR mandatory-to-stay-connected (folded)
    public readonly array  $raw;
}
```

## `Connection`

A connected person — identity + the slug-keyed value map. No source field
anywhere; `values` is keyed by **your** request slug.

```php
final class Connection {
    public readonly ?string $id;
    public readonly ?string $personId;
    public readonly ?string $displayName;        // null on connection($id) (the list endpoint carries it)
    public readonly ?\DateTimeImmutable $connectedAt; // likewise null on connection($id)
    public readonly array  $values;              // array<your_slug, Value>
    public readonly array  $raw;
}
```

```php
$conn->values['work_email']->value;        // "alice@acme.com"
$conn->values['mobile'] ?? null;            // null if the person didn't answer that slot
```

## `Value`

One answer for one of your request slots.

```php
final class Value {
    public readonly string|array|\DateTimeImmutable|BinaryHandle|null $value; // typed plaintext (see below)
    public readonly bool $live;                       // true = "keep connected" (auto-updates); false = one-time snapshot
    public readonly ?\DateTimeImmutable $updatedAt;   // when this answer last changed
    public readonly array $raw;
}
```

### `value` types (resolved from the field's `type`)

| Field type | PHP `value` | Notes |
|------------|-------------|-------|
| `email`, `phone`, `url`, `text` | `string` | The decrypted plaintext. |
| `address`, `bank`, `creditcard` | `array` | The decrypted plaintext is a JSON object → parsed. A non-JSON structured value throws `DecryptError`. |
| `date`, `date_of_birth` | `DateTimeImmutable` | Parsed from ISO `YYYY-MM-DD` (the leading 10 chars), date-only at UTC midnight; falls back to the raw string if unparseable. |
| `photo`, `document`, `legal_document` | `BinaryHandle` | Lazy — nothing fetched/decrypted until `->bytes()`/`->save()`. |
| unanswered / no value | `null` | The slot has no answer. |

## `BinaryHandle`

A lazy handle for a binary value (`Allus\CompanyData\Crypto\BinaryHandle`). No
network or decryption happens at construction.

```php
final class BinaryHandle {
    public function valueUrl(): ?string;        // the opaque slot-keyed file URL (read-only)
    public function bytes(): string;            // fetch (if needed) → the file bytes, either shape
    public function save(string $path): int;    // write bytes() to path crash-safely; returns bytes written
    public function contentSha256(): ?string;   // X-Allus-Content-Sha256 for the fetched bytes
    public function contentType(): ?string;     // the Content-Type they arrived with
}
```

On first `->bytes()`/`->save()` it GETs the slot-keyed file endpoint, which has
**two 200 shapes** decided by whether the *person's* source field is private —
their setting, changeable at any time and not exposed to you:

- **`application/json`** → `{"encrypted": true, "value": <wrapper>}`. Decrypt the
  inner `{"_enc":1,…}` wrapper with the service key → a JSON file-envelope string
  (`{"full": "data:…", "thumb": …}` photos, `{"file": "data:…", …}` documents) →
  base64-decode the primary data URI (`full` / `file`) → the file bytes.
- **any other `Content-Type`** → the body IS the file. Nothing is decrypted, and a
  handle with no decrypt wiring still works.

The shapes are told apart on `Content-Type`, never by sniffing the body: reading a
wrapper as bytes would write ciphertext to disk with nothing to signal it, so a
missing header resolves to the JSON path, which fails loudly instead. The result
is cached on the handle (repeated calls don't re-fetch).

`->save()` is crash-safe (temp file → `fsync` → atomic `rename`). An unanswered
binary slot yields an empty handle; calling `->bytes()` on it throws `DecryptError`.
A **Share once** answer whose 90-day retention has elapsed throws `ApiError`
(status 410, `errorKey` `company_data.file_expired`) whose `->details` carry
`content_sha256` and `expired_at`.

## `Change`

A change-feed / webhook event. Returned by the pump (`processChanges`,
`drainBatch`) and the webhook helpers.

```php
final class Change {
    public readonly ?string $id;        // the pull feed's server change-row id — your dedup key THERE only
    public readonly ?string $event;     // see the event table
    public readonly ?string $personId;
    public readonly ?string $shareCode; // the person's profile share code — every event (may be null)
    public readonly ?string $slug;      // field_updated/field_deleted/consent_* only
    public readonly string|array|\DateTimeImmutable|BinaryHandle|null $value; // field_updated only; typed like Value->value
    public readonly ?bool   $live;      // field_updated only
    public readonly ?\DateTimeImmutable $at; // the change time (no separate updatedAt on a change)
    public readonly array   $raw;
}
```

### Events

| `event` | Carries |
|---------|---------|
| `connection_created` | identity only (no slot/value) |
| `connection_deleted` | identity only (no slot/value) |
| `field_updated` | `slug` + decrypted `value` (+ `live`); binary → a lazy `BinaryHandle` |
| `field_deleted` | `slug`, no value |
| `consent_accepted` / `consent_declined` | `slug` |

`Change->id` is captured before the server's drain-delete, so it survives a crash
+ replay unchanged — dedup on it.

> **On the webhook path this id is NOT a dedup key.** A live webhook delivery has no change row behind it, so its id is minted for that single POST; a delivery replayed from the server-side backlog is rebuilt from a durable row and carries that row's id instead — the same id on every re-attempt of that row. The id is therefore sometimes stable across a duplicate and sometimes not, with no way for the receiver to tell, which is what makes it unusable as an idempotency key. Webhooks and the pull feed are alternative integrations; see `webhooks.md` for the webhook delivery contract and what to key on instead ($change->id is not it).

## `LogEntry`

A service activity-log entry — ops events only (email / purge / webhook), never
person field data.

```php
final class LogEntry {
    public readonly ?string $type;
    public readonly ?string $message;
    public readonly mixed   $metadata;
    public readonly ?\DateTimeImmutable $at;
    public readonly array   $raw;
}
```

## `->raw`

Every model has a `->raw` property: the underlying (hardened) API array, for
debugging or an edge case the SDK didn't model. It never contains the person's
source field — the hardened API doesn't return it.

## Share codes — what you may send, what you always receive

A profile can carry a second, human-readable **custom share code** assigned by an
allme operator, beside the generated code the person's app displays. Both resolve
to the same person.

- **Both places this SDK takes a share code as input accept either**:
  `$client->sendConnectRequest($shareCode)` (`POST /api/company-data/connect-requests`)
  and `$client->twoFactor()->challenge($shareCode, $idempotencyKey, $context)`
  (`POST /api/service-2fa/challenges`). Same parameter, same type, same shape —
  nothing in the SDK changes, and a customer who gives you `ACME` instead of
  `2I6UF3` simply works.
- **Every `share_code` the API emits is the GENERATED code** — `Connection->shareCode`,
  `Change->shareCode` and every webhook body. So a code handed to you by a customer
  may differ from the one you read back for that same person, and anything you key
  on the emitted value (a public-key cache, your own customer record) stays
  internally consistent.
