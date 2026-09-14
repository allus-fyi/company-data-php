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
    public readonly ?string $type;   // the field type — a row in the served registry, never a fixed list
    public readonly bool   $oneTime; // a one-time snapshot vs a live (auto-updating) answer
    public readonly bool   $mandatory; // mandatory-to-provide OR mandatory-to-stay-connected (folded)
    public readonly bool   $verified;  // this row DEMANDS a verified answer (mutually exclusive with $oneTime)
    public readonly ?int   $verifiedMaxAgeDays; // oldest verification accepted; null = no age limit
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
    public readonly bool $verified;                   // the hash recomputes over the plaintext AND the verification has not lapsed
    public readonly ?\DateTimeImmutable $verifiedAt;        // when the answering field was verified
    public readonly ?\DateTimeImmutable $verifiedExpiresAt; // when that verification lapses; null = it does not
    public readonly ?string $verifiedMethod;   // HOW allme bound it: email_code|sms_code|sumsub_id|sumsub_address
    public readonly ?string $verifiedProvider; // WHO established the proof: allme|sumsub
    public readonly ?string $verificationId;   // the proof id to quote back to allme in a dispute
    public readonly array $raw;
}
```

### `value` types — from the type's RESOLVED definition

A contact-field TYPE is a ROW in the served field-type registry (`GET /api/contact-field-types`),
which the client fetches beside the request-field catalog and holds for its life. A value's shape
follows the type's resolved storage LANE and PRIMITIVE, so a type added as a row types itself with
no SDK release.

| The type's resolved… | PHP `value` | Notes |
|----------------------|-------------|-------|
| storage lane `photo` / `document` | `BinaryHandle` | Lazy — nothing fetched/decrypted until `->bytes()`/`->save()`. |
| primitive `composite` | `array` | The decrypted plaintext is a JSON object → parsed. A non-JSON value throws `DecryptError`. |
| primitive `date` | `DateTimeImmutable` | Parsed from ISO `YYYY-MM-DD` (the leading 10 chars), date-only at UTC midnight; falls back to the raw string if unparseable. |
| primitive `multilist` | `array` | The chosen option strings, parsed from the JSON array. |
| anything else, and a type the registry does not carry | `string` | The decrypted plaintext. |
| unanswered / no value | `null` | The slot has no answer. |
For the seeded types that is, unchanged: `email`/`phone`/`url`/`text` and the three numeric types →
a string; `country`/`nationality` → an ISO 3166-1 alpha-2 code string; `address`/`bank`/`creditcard`
→ the parsed object; `date`/`date_of_birth` → the date type; `photo`, `document`,
`legal_document`, `passport`, `photo_id` and `drivers_license` → the lazy binary handle. A request
row of a PARENT type MAY be answered by a field of any DESCENDANT, but that matching happens in
the API: the answer still arrives keyed by YOUR slug and shaped by the SLOT's own type, because
the person's source field — and therefore its type — is never exposed. A binary slot's
slot → source → file resolution is likewise the API's.


## `BinaryHandle`

A lazy handle for a binary value (`Allus\CompanyData\Crypto\BinaryHandle`). No
network or decryption happens at construction.

```php
final class BinaryHandle {
    public function valueUrl(): ?string;        // the opaque slot-keyed file URL (read-only)
    public function bytes(): string;            // fetch (if needed) → the primary file bytes
    public function save(string $path): int;    // write bytes() to path crash-safely; returns bytes written
    public function pages(): array;             // list<BinaryPage>, in envelope order ([] for a single-file one)
    public function metadata(): array;          // array<string,?string> — the type's declared entries
    public function contentSha256(): ?string;   // X-Allus-Content-Sha256 for the SERVED ARTIFACT
    public function contentType(): ?string;     // the Content-Type the answer arrived with
}

final class BinaryPage {
    public readonly ?string $label;   // front | back | additional
    public readonly ?string $name;    // the original filename
    public readonly ?string $mime;    // the server-derived media type
    public readonly string $bytes;    // the decoded page bytes
}
```

On the first `->bytes()`/`->pages()`/`->metadata()`/`->save()` it GETs the slot-keyed
file endpoint, which has **three 200 shapes** decided by whether the *person's* source
field is private AND by the TYPE of the field they answered with — neither yours to
choose, both changeable, and not exposed to you:

- **`application/json`, `{"encrypted": true, "value": <wrapper>}`** (private source) →
  decrypt the inner `{"_enc":1,…}` wrapper with the service key → the JSON ENVELOPE
  string.
- **`application/json`, `{"encrypted": false, "value": "<envelope>"}`** (a non-private
  source whose type stores more than one file or declares metadata entries — the
  ID-document subtypes and `legal_document`) → the same envelope in the clear.
  Nothing is decrypted.
- **any other `Content-Type`** → the body IS the file. Nothing is decrypted, and a
  handle with no decrypt wiring still works.

The raw-bytes shape is told apart on `Content-Type`, never by sniffing the body:
reading a wrapper as bytes would write ciphertext to disk with nothing to signal it,
so a missing header resolves to the JSON path, which fails loudly instead. Inside a
JSON body it is `encrypted` that decides.
The envelope is a photo's `{"full": "data:…", "thumb": …}`, a single-file document's
`{"file": "data:…", …}`, or a multi-page document's
`{"pages": [{"label": …, "file": "data:…", …}], …}`, with every entry the type declares
beside it.

`->pages()` answers the pages of a multi-page envelope in order, and `[]` for a
single-file one. `->metadata()` answers every string-keyed envelope member other than
`pages`, `file`, `full`, `thumb`, `original_name`, `mime_type` and `size`, so a
passport's `document_number`, `expiry_date`, `issuing_country` and `name` are all
there; **it carries no ordering guarantee** — read the envelope string yourself if you
need the declared order. **`->bytes()`/`->save()` throw
`DecryptError('multi-page envelope: use pages')` on a multi-page envelope** rather
than handing back the front page as though it were the whole document.

All of the accessors share ONE lazy fetch: whichever is called first performs it, and
the result is cached (repeated calls don't re-fetch). The digest header
`X-Allus-Content-Sha256` is the sha256 of the **served artifact** — the raw bytes on the
bytes shape, the served `value` string on either JSON shape — not "the sha256 of what
`->bytes()` returns", which is false on a multi-page envelope. There is no variant
selection.


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
    public readonly ?string $connectionId;    // message_received only — the connection to reply/ack on
    public readonly ?string $messageId;       // message_received only — the ack boundary
    public readonly ?string $personPublicKey; // message_received only — base64 SPKI for the reply
    public readonly ?string $messageBody;     // message_received only — the DECRYPTED text
    public readonly bool    $verified;  // field_updated only; hash recomputes AND the verification has not lapsed
    public readonly ?\DateTimeImmutable $verifiedAt;        // when the answering field was verified
    public readonly ?\DateTimeImmutable $verifiedExpiresAt; // when that verification lapses; null = it does not
    public readonly ?string $verifiedMethod;   // HOW allme bound it: email_code|sms_code|sumsub_id|sumsub_address
    public readonly ?string $verifiedProvider; // WHO established the proof: allme|sumsub
    public readonly ?string $verificationId;   // the proof id to quote back to allme in a dispute
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
| `message_received` | `connectionId`, `messageId`, `personPublicKey` + `messageBody` (the DECRYPTED message text); no slot. Person→company only — a broadcast raises no event |

The event's ciphertext is carried under `body`. It is never `value`: on every other
event `value` means field ciphertext, and a message body is not one.

**Answering one.** `sendMessage` answers **201** with the created message carrying
`message_id`, which is what it returns — hand that id, or the inbound event's `messageId`,
to `markMessagesRead` as the acknowledgement boundary.

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
