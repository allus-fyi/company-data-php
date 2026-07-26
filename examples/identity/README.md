# Identity example — sign-in / OIDC / 2FA (PHP SDK)

A runnable website that demonstrates **every identity scenario** of the allme
platform — Sign in with allme, OIDC login, and 2FA by allme — through the
`allus/company-data` **PHP SDK**. It is the reference example the other five
SDKs' examples are modelled on: ~90 % of the logic is a shared frontend fetched
from a pinned release; this directory is the thin PHP backend that implements the
[demo-backend contract](https://github.com/allme-sdk/example-test-suite) (`CONTRACT.md`).

Everything the handlers do goes through the SDK's **intended top-level functions**
(never internals, never raw platform HTTP); the OIDC scenarios use the standard
third-party `facile-it/php-openid-client` library — that is the point of the OIDC
demonstration.

---

## Run it — one command

```bash
git clone https://github.com/allus-fyi/company-data-php
cd company-data-php/examples/identity
composer start
```

That runs `bin/start.php`, which:

1. wipes `.runtime/` (fresh state every boot),
2. runs `composer install` if `vendor/` is missing,
3. on first run, downloads the **pinned** frontend release named in
   `frontend.lock`, **verifies its sha256**, and unpacks it to `.frontend/`
   (a present, verified bundle is a cache hit — nothing is re-fetched),
4. checks the bundle's `contract.json` version against the backend's,
5. refuses a busy port with a clear message, then
6. serves `http://localhost:8091` — a **single-worker** `php -S` (do not set
   `PHP_CLI_SERVER_WORKERS`).

Open **http://localhost:8091** and pick a scenario. From there the browser and
the allus portal are the only surfaces you touch. Each scenario's setup panel has
a **Save** button: it POSTs your settings to the backend, which writes them to a
canonical SDK **config file** (`.runtime/config/{id}.json`, any PEM under
`.runtime/config/keys/`) — the same shape a real integrator wires by hand. The
panel shows the written path so you can open and read the real config; **Run**
then builds the SDK from that file (`OAuthClient::fromConfig` /
`Client::fromConfig`) and runs off it. You still never hand-create or edit the
file — the backend writes it from your browser inputs; it is there to be read.

**Port.** `8091` is the default, overridable with the `PORT` env var:

```bash
PORT=8092 composer start
```

The default is deliberately the **same across all six SDK examples** (one browser
origin ⇒ your localStorage setup carries across SDKs) — the documented
consequence is that only one example runs at a time.

**Requirements:** PHP ≥ 8.1 with `ext-json` + `ext-openssl`, Composer, and
`curl` + `tar` on `PATH` (used to fetch/unpack the frontend bundle).

---

## Default target — the deployed AWS platform

The scenario **advanced inputs default to the deployed platform** (owner decision
2026-07-24: pre-launch, the cluster is the test environment):

| Advanced input | Default |
|---|---|
| API url | `https://api.allme.fyi` |
| Authorize base | `https://web.allme.fyi/auth` |

Against these defaults OIDC discovery is correct as-is and a phone reaches
everything naturally — **no environment setup of any kind**. You register the
demo's OAuth apps / data clients in the **allus portal at `portal.allus.fyi`**;
each scenario's setup checklist names the exact portal pages and any
person-account prerequisites (e.g. the demo person having TOTP or email 2FA
enabled on their allme account for the 2FA scenarios).

Register the redirect URI **`http://localhost:8091/callback`** on every OAuth app
you create for these scenarios (adjust the port if you set `PORT`).

A physical phone with the allme app reaches the deployed platform naturally — no
setup needed.

---

## Secondary target — a local stack

Running against a **local stack** instead is an optional secondary target. In the browser, switch the advanced inputs to
the local URLs (API `http://localhost:8070`, authorize base
`http://localhost:5174/auth`). No file in **this** example changes.

Two things must be true of the **local API** for OIDC to work — set them in the
local stack's `api/.env` (NOT here):

```dotenv
OIDC_ISSUER=http://localhost:8070
VITE_WEB_URL=http://localhost:5174
```

`OIDC_ISSUER` makes the local API advertise itself in OIDC discovery.
It must be **`VITE_WEB_URL`, not `WEB_URL`** — the api loads `.env` into `$_ENV`
only, and `WebUrl::base()` reads `$_ENV['VITE_WEB_URL'] ?? getenv('WEB_URL')`, so
a `WEB_URL` line in `.env` is inert.

**Phone-reachability caveats** for the local variant:

- The app on the phone must be able to reach the local API — per project
  practice, `adb reverse tcp:8070 tcp:8070` (Android) or use the machine's LAN
  address.
- **Scenario 2 (detached sign-in):** a `localhost…` QR code is unreachable from a
  phone, so the QR is not useful against a local stack — use the **link-click**
  path as the local test. (Against the deployed default the QR works naturally.)

---

## Bumping the frontend pin

The frontend ships as a checksummed release asset; the pin lives in
`frontend.lock`:

```json
{"tag":"v0.1.0","sha256":"<sha256 of dist.tar.gz>"}
```

To move to a newer frontend release:

1. In `github.com/allme-sdk/example-test-suite`, note the release **tag** and its
   `dist.tar.gz` checksum — `shasum -a 256 dist.tar.gz`.
2. Edit `frontend.lock`: set `tag` and `sha256` to those values.
3. Remove the cached bundle so it re-fetches: `rm -rf .frontend/`.
4. `composer start` — it downloads the new tag, verifies the checksum, and checks
   the bundle's `contract.json` version against the backend. A **contract-version
   change** means the backend must be updated in the same step; the startup guard
   refuses a mismatch loudly.

A pin bump is a **per-example commit** — every other SDK example keeps fetching
its own pinned release (assets stay downloadable indefinitely).

---

## Using the published SDK package

By default this example resolves `allus/company-data` from a **path repository**
(`../..` — the SDK source tree in this repo), symlinked in:

```json
"repositories": [
    { "type": "path", "url": "../..", "options": { "symlink": true } }
]
```

To point the example at the **published** `allus/company-data` package instead
(e.g. to test a released version), delete that `repositories` block from
`composer.json` — Composer then resolves the `"allus/company-data": "*"` requirement
from Packagist — pin a version if you want one (`"allus/company-data": "^1.0"`),
then:

```bash
rm -rf vendor composer.lock
composer install
```

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| **`port 8091 is busy`** at startup | Another example (or process) holds the port — one browser origin is shared across SDK examples, so only one runs at a time. Stop it, or run `PORT=<n> composer start`. |
| **Stale / wrong frontend** after a pin bump | The present bundle is a cache hit and is not re-fetched. `rm -rf .frontend/` and `composer start` to re-download the pinned release. |
| **`contract mismatch: bundle contractVersion=… backend implements …`** | The pinned bundle's `contract.json` version differs from what this backend implements. Bump `frontend.lock` to a release whose `contract.json` matches this backend (and re-fetch), or update the backend. |
| **`frontend checksum MISMATCH`** | The downloaded `dist.tar.gz` doesn't match `frontend.lock`'s `sha256`. Fix the `sha256` in `frontend.lock` (from `shasum -a 256 dist.tar.gz` on the real release) or re-download; the example refuses to serve an unverified bundle. |
| **`could not download the pinned frontend release`** | The release/tag doesn't exist yet, or no network. If the release isn't published yet, seed the bundle into `.frontend/` manually. |
| **`dependencies not installed`** on a request | `vendor/` is missing — run `composer install` (or just `composer start`, which does it). |

---

## What's in here

| Path | What it is |
|---|---|
| `composer.json` | This example's own composer sub-project — the SDK via path repo, the OIDC library, nothing else. **Excluded from the published SDK package** (`archive.exclude`). |
| `bin/start.php` | The one-command launcher (steps above). |
| `router.php` | `php -S` router — serves the static bundle + the contract's API endpoints. |
| `src/` | The backend: contract endpoints, config files + run stash, SDK + OIDC wiring. |
| `frontend.lock` | The pinned frontend release (`{tag, sha256}`). |
| `.frontend/` | The fetched, verified frontend bundle (git-ignored). |
| `.runtime/` | The written SDK config files (`config/{id}.json` + `config/keys/`) and per-run state (`runs/`), git-ignored, wiped every boot; `0700`. |

`.runtime/`, `.frontend/`, and `vendor/` are git-ignored — the fetched bundle and
vendored libraries never land in the repo.
