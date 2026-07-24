# Company-data example — connections / request fields / change feed / webhooks / documents (PHP SDK)

A runnable website that demonstrates the **regular company-data surface** a company
uses — reading connected people, request-field definitions, the change feed,
webhooks, and documents — through the `allus/company-data` **PHP SDK**. Like the
[identity example](../identity), ~90 % of the logic is a shared frontend fetched
from a pinned release; this directory is the thin PHP backend that implements the
[demo-backend contract](https://github.com/allme-sdk/example-test-suite)
(`CONTRACT.md`) for the five `companydata:*` scenarios.

Everything the handlers do goes through the SDK's **intended top-level functions**
— `Client::connections()`, `requestFields()`, `processChanges()` / `drainBatch()`,
`verifyWebhook()` + `parseWebhook()`, `createDocument()` — never internals, never
raw platform HTTP.

---

## Run it — one command

```bash
cd sdks/php/examples/company-data
composer start
```

That runs `bin/start.php`, which:

1. wipes `.runtime/` (fresh state every boot),
2. runs `composer install` if `vendor/` is missing,
3. on first run, downloads the **pinned** frontend release named in
   `frontend.lock`, **verifies its sha256**, and unpacks it to `.frontend/<tag>/`
   (a present, verified bundle is a cache hit — nothing is re-fetched),
4. checks the bundle's `contract.json` version against the backend's,
5. refuses a busy port with a clear message, then
6. serves `http://localhost:8091` — a **single-worker** `php -S` (do not set
   `PHP_CLI_SERVER_WORKERS`).

Open **http://localhost:8091** and pick a scenario. Each scenario's setup panel
has a **Save** button: it POSTs your settings to the backend, which writes them to
a canonical SDK **config file** (`.runtime/config/{sid}.json`, the service PEM
under `.runtime/config/keys/`) — the same shape a real integrator wires by hand.
The panel shows the written path so you can open and read the real config; **Run**
then builds the SDK from that file (`Client::fromConfig`) and runs off it. You
never hand-create or edit the file — the backend writes it from your browser
inputs; it is there to be read.

**Port.** `8091` is the default, overridable with the `PORT` env var
(`PORT=8092 composer start`). The default is the **same across all six SDK
examples** (one browser origin ⇒ your localStorage setup carries across SDKs), so
only one runs at a time.

**Requirements:** PHP ≥ 8.1 with `ext-json` + `ext-openssl`, Composer, and
`curl` + `tar` on `PATH` (used to fetch/unpack the frontend bundle).

---

## The five scenarios

| Scenario | SDK call | What it shows |
|---|---|---|
| **Read connected people** | `Client::connections()` | each connected person's decrypted values, grouped one card per person (two people who filled the same slug stay distinguishable) |
| **Request-field definitions** | `Client::requestFields()` | your request slugs → label / type / the folded `mandatory` flag + `one_time` |
| **Change-feed pump** | `Client::processChanges()` | a crash-safe drain of the change feed (idempotent per event on `Change.id`), shown as a batch |
| **Webhook receiver** | `verifyWebhook()` + `parseWebhook()` | a public `POST /webhook` (401 on a bad HMAC, 200 otherwise) **plus** a change-feed fallback so it works with no tunnel |
| **Create the six document types** | `Client::createDocument()` | broadcast JSON / broadcast PDF / per-person file / private file / contract-requiring-signature / contract-requiring-acceptance |

Every scenario uses the **service role**, so the service PEM + passphrase are a
required input on all five (the SDK loads the key at `Client` construction).

---

## Default target — the deployed AWS platform

The scenario **advanced inputs default to the deployed platform** (owner decision
2026-07-24: pre-launch, the cluster is the test environment): API url
`https://api.allme.fyi`. No environment setup of any kind. You register the demo's
**service + data client** in the **allus portal at `portal.allus.fyi`**; each
scenario's setup checklist names the exact portal steps (create the service +
download its PEM, register a data client on it, configure request fields, connect
a test person).

> **Portal prerequisite / interim (2026-07-24).** `portal.allus.fyi` is **not
> deployed yet** — the portal deploy is the prerequisite for this deployed-default
> recipe. Until it lands, the documented interim is to run the **local portal UI
> against the cluster API**: set `VITE_API_URL=https://api.allme.fyi` in
> `allus/.env` and start the portal locally, so every portal step still lands on
> the deployed platform the scenarios run against.

---

## The webhook scenario — deployed (tunnel) vs local (native)

The webhook receiver is dual-mode:

- **Local stack** — the local API's delivery worker reaches `localhost` directly,
  so register **`http://localhost:8091/webhook`** as the service webhook. This is
  the only mode where inbound webhooks work **without a tunnel**.
- **Deployed platform** — the cluster cannot reach your `localhost`, so open one
  tunnel and register its public URL:

  ```bash
  cloudflared tunnel --url http://localhost:8091
  ```

  Register the printed public URL with **`/webhook`** appended as the service
  webhook. Set **`encrypt_payload` OFF** (this example holds no account private
  key; an encrypted body cannot be decrypted here). Copy the **webhook id** and the
  one-time **HMAC secret** shown at registration into the scenario's inputs.

Either way, the same run **also polls the change feed** as an always-works
fallback (labeled `feed` vs `webhook`), so events still appear even with no tunnel.
Note the two paths differ in shape: the webhook stream delivers each event, while
the pull feed is a dedup-upsert **state** feed (one latest-state row per identity),
so the fallback can look like it "collapsed" events — that is expected.

---

## Secondary target — a local stack

Running against a **local stack** instead is a documented secondary option (see
`docs/reference/software.html`). In the browser, switch the advanced **API url** to
`http://localhost:8070`. No file in **this** example changes.

---

## Bumping the frontend pin

The frontend ships as a checksummed release asset; the pin lives in
`frontend.lock` (`{"tag":"v0.3.0","sha256":"<sha256 of dist.tar.gz>"}`). To move to
a newer release: note the release **tag** and its `dist.tar.gz` checksum
(`shasum -a 256 dist.tar.gz`), set `tag` + `sha256` in `frontend.lock`,
`rm -rf .frontend/`, then `composer start`. A **contract-version change** means the
backend must be updated in the same step; the startup guard refuses a mismatch
loudly. A pin bump is a **per-example commit**.

---

## Using the published SDK package

By default this example resolves `allus/company-data` from a **path repository**
(`../..` — the SDK source tree in this repo), symlinked in. To point at the
**published** package instead, delete the `repositories` block from
`composer.json` (Composer then resolves `"allus/company-data": "*"` from
Packagist; pin a version if you want one), then `rm -rf vendor composer.lock &&
composer install`.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| **`port 8091 is busy`** at startup | Another example (or process) holds the port — one browser origin is shared across SDK examples, so only one runs at a time. Stop it, or run `PORT=<n> composer start`. |
| **Stale / wrong frontend** after a pin bump | The present bundle is a cache hit and is not re-fetched. `rm -rf .frontend/` and `composer start` to re-download the pinned release. |
| **`contract mismatch: bundle contractVersion=… backend implements …`** | The pinned bundle's `contract.json` version differs from what this backend implements. Bump `frontend.lock` to a release whose `contract.json` matches this backend (and re-fetch), or update the backend. |
| **`frontend checksum MISMATCH`** | The downloaded `dist.tar.gz` doesn't match `frontend.lock`'s `sha256`. Fix the `sha256` (from `shasum -a 256 dist.tar.gz` on the real release) or re-download; the example refuses to serve an unverified bundle. |
| **`could not download the pinned frontend release`** | The release/tag doesn't exist yet, or no network. If the release isn't published yet, seed the bundle into `.frontend/<tag>/` manually (the error prints the exact commands). |
| **Webhook deliveries never arrive (deployed)** | The cluster cannot reach `localhost` — you need the `cloudflared` tunnel above, and the registered URL must end in `/webhook`. The change-feed fallback still shows events meanwhile. |
| **A per-person / contract document errors** | Those types target a connected person — set the **target person share code** in the documents scenario's setup, then re-run. Broadcast documents need no target. |

---

## What's in here

| Path | What it is |
|---|---|
| `composer.json` | This example's own composer sub-project — the SDK via path repo, nothing else. **Excluded from the published SDK package** (`archive.exclude`). |
| `bin/start.php` | The one-command launcher (steps above). |
| `router.php` | `php -S` router — serves the static bundle + the contract's API endpoints + the public `POST /webhook`. |
| `src/Server.php` | The backend: the five scenario handlers, the webhook receiver, the Change projection. |
| `src/Runtime.php` | Cross-request state: config files + run store + the single webhook routing record + the pump cache dir. |
| `frontend.lock` | The pinned frontend release (`{tag, sha256}`). |
| `.frontend/` | The fetched, verified frontend bundle (git-ignored). |
| `.runtime/` | The written SDK config files, per-run state, webhook routing record, and pump cache — git-ignored, wiped every boot; `0700`. |

`.runtime/`, `.frontend/`, and `vendor/` are git-ignored — the fetched bundle and
vendored library never land in the repo.
