# allus SDK examples (PHP) — one server, all three scenario families

A runnable website that demonstrates the `allus/company-data` **PHP SDK** across
its three scenario families, all served by **one command on one port**:

- **Identity** — Sign in with allme (redirect / detached / one-time claims /
  connect), OIDC login + continue-on-your-phone, and standalone 2FA by allme.
- **Company-data** — read connected people, request-field definitions, the change
  feed, a webhook receiver, and the six document/contract types.
- **Flow** — trigger and drive a contract flow end-to-end, then read the decrypted
  answers and download the generated signed document.

~90 % of the logic is a shared frontend fetched from a pinned release; this
directory is the thin PHP backend that implements the
[demo-backend contract](https://github.com/allme-sdk/example-test-suite)
(`CONTRACT.md`, **contract v3**) for **all 14 scenarios**. Everything the handlers
do goes through the SDK's **intended top-level functions** — never internals, never
raw platform HTTP; the OIDC scenarios use the standard third-party
`facile-it/php-openid-client` library, which is the point of the OIDC demonstration.

---

## Run it — one command

```bash
git clone https://github.com/allus-fyi/company-data-php
cd company-data-php/examples
composer start
```

`composer start` runs `bin/start.php`, which fetches the pinned portal bundle and
serves the example test suite — **all three scenario families** — on
**http://localhost:8091**. In detail it:

1. wipes `.runtime/` (fresh state every boot),
2. runs `composer install` if `vendor/` is missing,
3. on first run, downloads the **pinned** frontend release named in
   `frontend.lock`, **verifies its sha256**, and unpacks it to `.frontend/<tag>/`
   (a present, verified bundle is a cache hit — nothing is re-fetched),
4. checks the bundle's `contract.json` version against the backend's (v3),
5. refuses a busy port with a clear message, then
6. serves `http://localhost:8091` — a **single-worker** `php -S` (do not set
   `PHP_CLI_SERVER_WORKERS`).

Open **http://localhost:8091** and pick any scenario. Each scenario's setup panel
has a **Save** button: it POSTs your settings to the backend, which writes them to
a canonical SDK **config file** (`.runtime/config/{id}.json`, any PEM under
`.runtime/config/keys/`) — the same shape a real integrator wires by hand. The
panel shows the written path so you can open and read the real config; **Run** /
**Trigger** then builds the SDK from that file (`OAuthClient::fromConfig` /
`Client::fromConfig`) and runs off it. You never hand-create or edit the file — the
backend writes it from your browser inputs; it is there to be read.

**Port.** `8091` is the default, overridable with the `PORT` env var
(`PORT=8092 composer start`). The default is the **same across all six SDK
examples** (one browser origin ⇒ your localStorage setup carries across SDKs), so
only one runs at a time.

### Prerequisites

- **PHP 8.1+** with the `json` and `openssl` extensions.
- **Composer** on your `PATH`.
- **`curl`** and **`tar`** on your `PATH` (used to fetch/unpack the frontend
  bundle).

No other setup — the scenarios' advanced inputs default to the deployed AWS
platform (`https://api.allme.fyi`; identity's authorize base
`https://web.allme.fyi/auth`), so OIDC discovery is correct as-is and a phone
reaches everything naturally.

---

## Set up the demo in the portal

You register the demo's OAuth apps / data clients / service in the **allus portal at
[https://portal.allus.fyi](https://portal.allus.fyi)**. Each scenario's setup
checklist in the UI names the exact portal pages and any person-account
prerequisites (e.g. the demo person having TOTP or email 2FA enabled for the 2FA
scenarios, or being connected to your service for the connect / company-data / flow
scenarios).

For the **identity** OAuth scenarios, register the redirect URI
**`http://localhost:8091/callback`** on every OAuth app you create (adjust the port
if you set `PORT`).

For the **flow** scenario, import one of the two flow packages in `fixtures/` into
the portal (service settings → Flows → Import) and **publish** it, then enter the
published flow id + the target connection id in the browser. The person's turn — and
the contract fixture's signature — are completed on a phone with the allme app,
signed in as the connected demo person.

---

## The webhook scenario — set up first; tunnel optional

The company-data **webhook** scenario needs a registered webhook **before** you run
it: its run is keyed by the **webhook id** and verifies deliveries with the
**HMAC secret** shown once at registration. Set it up first:

1. In the portal, register a webhook on your service. Set **`encrypt_payload` OFF**
   (this example holds no account private key, so an encrypted body cannot be
   decrypted here).
2. Copy the **webhook id** and the one-time **HMAC secret** into the scenario's
   inputs and **Save**, then **Run**.

Inbound delivery to your `localhost` requires a public URL, which is **optional**:

- The same run **also polls the change feed** as an always-works fallback (rows are
  labelled `feed` vs `webhook`), so events appear even with **no tunnel**.
- To receive real webhook deliveries, open a tunnel and register its public URL with
  `/webhook` appended:

  ```bash
  cloudflared tunnel --url http://localhost:8091
  ```

  (A local stack whose delivery worker can reach `localhost` directly can register
  `http://localhost:8091/webhook` without a tunnel.)

---

## Bumping the frontend pin

The frontend ships as a checksummed release asset; the pin lives in `frontend.lock`
(`{"tag":"v0.4.0","sha256":"<sha256 of dist.tar.gz>"}`) and covers the whole
examples tree. To move to a newer release: note the release **tag** and its
`dist.tar.gz` checksum (`shasum -a 256 dist.tar.gz`) from
`github.com/allme-sdk/example-test-suite`, set `tag` + `sha256` in `frontend.lock`,
`rm -rf .frontend/`, then `composer start`. A **contract-version change** means the
backend must be updated in the same step; the startup guard refuses a mismatch
loudly.

---

## Using the published SDK package

By default this example resolves `allus/company-data` from a **path repository**
(`..` — the SDK source tree in this repo), symlinked in. To point at the
**published** package instead, delete the `repositories` block from `composer.json`
(Composer then resolves `"allus/company-data": "*"` from Packagist; pin a version if
you want one), then `rm -rf vendor composer.lock && composer install`.

---

## Secondary target — a local stack

Running against a **local stack** is an optional secondary target. In the browser,
switch the advanced **API url** to `http://localhost:8070` (and, for identity, the
authorize base to `http://localhost:5174/auth`); no file in this example changes.
The phone must be able to reach the local API (e.g. `adb reverse tcp:8070 tcp:8070`
on Android, or the machine's LAN address). For OIDC against a local API set
`OIDC_ISSUER` and `VITE_WEB_URL` in that API's environment so it advertises itself in
OIDC discovery. A `localhost…` detached-sign-in QR is unreachable from a phone — use
the link-click path for that scenario locally.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| **`port 8091 is busy`** at startup | Another example (or process) holds the port — one browser origin is shared across SDK examples, so only one runs at a time. Stop it, or run `PORT=<n> composer start`. |
| **Stale / wrong frontend** after a pin bump | The present bundle is a cache hit and is not re-fetched. `rm -rf .frontend/` and `composer start` to re-download the pinned release. |
| **`contract mismatch: bundle contractVersion=… backend implements …`** | The pinned bundle's `contract.json` version differs from this backend (v3). Bump `frontend.lock` to a matching release (and re-fetch), or update the backend. |
| **`frontend checksum MISMATCH`** | The downloaded `dist.tar.gz` doesn't match `frontend.lock`'s `sha256`. Fix the `sha256` (from `shasum -a 256 dist.tar.gz` on the real release) or re-download; the example refuses to serve an unverified bundle. |
| **`could not download the pinned frontend release`** | The release/tag doesn't exist yet, or no network. If unpublished, seed the bundle into `.frontend/<tag>/` manually (the error prints the exact commands). |
| **`dependencies not installed`** on a request | `vendor/` is missing — run `composer install` (or just `composer start`, which does it). |
| **Webhook deliveries never arrive** | Your `localhost` isn't publicly reachable — open the `cloudflared` tunnel above and register the printed URL with `/webhook` appended. The change-feed fallback still shows events meanwhile. |
| **A per-person / contract document errors** | Those types target a connected person — set the **target person share code** in the documents scenario's setup, then re-run. Broadcast documents need no target. |
| **`start_failed` naming a missing connection / person** (flow) | The connection id is wrong or the person isn't connected to the service — check the connection in the portal. |

---

## What's in here

| Path | What it is |
|---|---|
| `composer.json` | The one composer sub-project — the SDK via path repo + the OIDC library. **Excluded from the published SDK package** (`archive.exclude`). |
| `bin/start.php` | The one-command launcher (steps above). |
| `router.php` | `php -S` router — serves the static bundle + the whole contract API + the public `POST /webhook` (company-data) + `GET /callback` (identity). |
| `src/Server.php` | The **shared scaffolding**: HTTP dispatch, the aggregate `/api/meta`, static-bundle serving, and routing each scenario to its family by id. Contains no SDK calls. |
| `src/Runtime.php` | The **shared** cross-request state: config files + run store + the webhook routing record + the pump cache dir (one `.runtime/` for the whole server). |
| `src/Pkce.php` · `src/Response.php` · `src/Family.php` | Shared helpers: PKCE pair, the handler response value object, the family-handler contract. |
| `src/Identity/Handlers.php` | The identity scenario handlers — the SDK calls for sign-in / OIDC / 2FA. |
| `src/CompanyData/Handlers.php` | The company-data scenario handlers — connections / request fields / change feed / webhook / documents. |
| `src/Flow/Handlers.php` | The flow scenario handler — trigger / drive / type-check / answers + document. |
| `fixtures/` | The two importable flow packages (portal-export zips) for the flow scenario. |
| `frontend.lock` | The single pinned frontend release for the whole examples tree (`{tag, sha256}`). |
| `.frontend/` · `.runtime/` · `vendor/` | Git-ignored — the fetched bundle, written config/run state (wiped every boot, `0700`), and vendored libraries never land in the repo. |
