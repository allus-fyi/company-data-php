# Flow example — run a contract flow (PHP SDK)

A runnable website that demonstrates a **contract flow** end-to-end through the
`allus/company-data` **PHP SDK**: trigger a flow run, drive the company party
through it with type-checked step filling, hand a turn to the person's phone, and
on completion read the decrypted answers and — for the contract fixture — download
the generated signed document. Like the [identity example](../identity/), ~90 % of
the logic is a shared frontend fetched from a pinned release; this directory is the
thin PHP backend that implements the
[demo-backend contract](https://github.com/allme-sdk/example-test-suite)
(`CONTRACT.md`, flow family — contract v2).

Everything the handler does goes through the SDK's **intended top-level flow
functions** — `identity()`, `triggerFlowRun()`, `flowRun()`, `processFlowRun()`,
`flowRunAnswers()`, `flowRunDocument()` — never internals, never raw platform HTTP.

---

## Run it — one command

```bash
cd sdks/php/examples/flow
composer start
```

That runs `bin/start.php`, which wipes `.runtime/` (fresh state every boot), runs
`composer install` if `vendor/` is missing, fetches + **sha256-verifies** the
pinned frontend release named in `frontend.lock` into `.frontend/` (a present,
verified bundle is a cache hit), checks the bundle's `contract.json` version
against the backend's, refuses a busy port with a clear message, then serves
`http://localhost:8091` — a **single-worker** `php -S` (do not set
`PHP_CLI_SERVER_WORKERS`).

Open **http://localhost:8091** and pick the **Run a contract flow** scenario. From
there the browser and the allus portal are the only surfaces you touch. The
scenario's **Save** button POSTs your settings to the backend, which writes them to
a canonical SDK **config file** (`.runtime/config/{id}.json`, the service PEM under
`.runtime/config/keys/`) — the same shape a real integrator wires by hand. The
panel shows the written path so you can open and read the real config; **Trigger**
then builds the SDK from that file (`Client::fromConfig`) and runs off it. You
still never hand-create or edit the file — the backend writes it from your browser
inputs; it is there to be read.

**Port.** `8091` is the default, overridable with `PORT` (e.g. `PORT=8092 composer
start`). The default is deliberately the **same across all six SDK examples** (one
browser origin ⇒ your localStorage setup carries across SDKs) — the documented
consequence is that only one example runs at a time.

**Requirements:** PHP ≥ 8.1 with `ext-json` + `ext-openssl`, Composer, and `curl` +
`tar` on `PATH` (used to fetch/unpack the frontend bundle).

---

## The scenario — set up, then run

A contract flow is a company-authored graph of steps. The demo ships **two
fixtures** you import into the portal (`fixtures/`):

| Fixture zip | Shape |
|---|---|
| `fixtures/info-gathering.zip` | `data_only` — a few company steps (text, an **email** validation-demo step, an address composite) then one person turn. |
| `fixtures/contract.zip` | `document` — a company step, then a signature leaf that generates a document. |

The scenario's setup checklist names the exact portal steps. In short:

1. In the **allus portal**, register a **data client** (client_credentials) for the
   service — its whitelist auto-grants `/api/company-data/*`. Create/reuse the
   **service** and download its **private key (PEM)** (it decrypts the answers +
   document).
2. **Import** the chosen fixture zip (service settings → Flows → Import) and
   **publish** the imported flow.
3. In the browser, enter the data-client id/secret, pick the service PEM + its
   passphrase, enter the **published flow id** and the target **connection id**, and
   pick the same **fixture** you imported. **Save**, then **Trigger the flow run**.

What you then observe:

- The **flow-run log** accumulates one row per company step as the SDK drives it:
  the `email` step is submitted once with a bad value → rejected (the SDK's
  `ValidationError`, shown ✗), then re-submitted valid → accepted ✓. The other
  steps submit valid and advance.
- When the flow reaches the person's turn it shows **"waiting — answer on your
  phone"**; polling resumes automatically once the person answers (and, for the
  contract fixture, **signs**) in the allme app.
- On completion the **decrypted answers** appear, and for the contract fixture the
  **document** is downloaded via `flowRunDocument()`.
- **"What just happened"** lists the exact SDK methods the run called.

> **Phone required.** The person's turn — and the contract fixture's signature — are
> completed on a **physical phone** with the allme app, signed in as the connected
> demo person (project practice: physical devices).

---

## Default target — the deployed AWS platform

The scenario's advanced input (**API url**) defaults to the deployed platform
(`https://api.allme.fyi`) — **no environment setup**. You register the data client,
create the service, and import + publish the flow in the **allus portal at
`portal.allus.fyi`**.

> **Portal prerequisite / interim (2026-07-24).** `portal.allus.fyi` is **not
> deployed yet**. Until it lands, the documented interim is to run the **local
> portal UI against the cluster API**: set `VITE_API_URL=https://api.allme.fyi` in
> `allus/.env` and start the portal locally (it proxies `/api` to that URL), so
> every portal step still lands on the same deployed platform the run executes
> against. A physical phone with the allme app reaches the deployed platform
> naturally.

---

## Secondary target — a local stack

Running against a **local stack** is a documented secondary option (see
`docs/reference/software.html`). In the browser, switch the advanced **API url** to
`http://localhost:8070`; no file in **this** example changes. The phone must be able
to reach the local API (project practice: `adb reverse tcp:8070 tcp:8070` on
Android, or the machine's LAN address).

---

## Bumping the frontend pin

The frontend ships as a checksummed release asset; the pin lives in `frontend.lock`
(`{tag, sha256}`). This example pins the **flow family bundle (contract v2)**. To
move to a newer release: note the release **tag** and its `dist.tar.gz` checksum
(`shasum -a 256 dist.tar.gz`) from `github.com/allme-sdk/example-test-suite`, set
`tag` + `sha256` in `frontend.lock`, `rm -rf .frontend/`, then `composer start` — it
re-fetches, verifies the checksum, and checks the bundle's `contract.json` version
against the backend (a mismatch refuses loudly). A pin bump is a **per-example
commit**.

---

## Using the published SDK package

By default this example resolves `allus/company-data` from a **path repository**
(`../..` — the SDK source tree in this repo). To point it at the **published**
package instead, delete the `repositories` block from `composer.json` (Composer then
resolves `"allus/company-data"` from Packagist), then `rm -rf vendor composer.lock &&
composer install`.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| **`port 8091 is busy`** at startup | Another example holds the port — one origin is shared across SDK examples, so only one runs at a time. Stop it, or `PORT=<n> composer start`. |
| **`contract mismatch: bundle contractVersion=… backend implements …`** | The pinned bundle's `contract.json` version differs from this backend (flow = v2). Bump `frontend.lock` to a matching release (and re-fetch), or update the backend. |
| **`frontend checksum MISMATCH`** | The downloaded `dist.tar.gz` doesn't match `frontend.lock`'s `sha256`. Fix the `sha256` (from `shasum -a 256 dist.tar.gz` on the real release) or re-download. |
| **`could not download the pinned frontend release`** | The `v0.2.0` release isn't published yet, or no network. If unpublished, seed the bundle into `.frontend/<tag>/` manually (build `example-test-suite`, `tar -xzf dist.tar.gz -C .frontend/<tag>`, `printf %s <sha> > .frontend/<tag>/.sha`). |
| **`start_failed: could not load service private key`** | The service PEM / passphrase is wrong — re-pick the key and re-save. |
| **`start_failed`** naming a missing connection / person | The connection id is wrong or the person isn't connected to the service — check the connection in the portal. |

---

## What's in here

| Path | What it is |
|---|---|
| `composer.json` | This example's composer sub-project — the SDK via path repo, nothing else. **Excluded from the published SDK package** (`archive.exclude`). |
| `bin/start.php` | The one-command launcher. |
| `router.php` | `php -S` router — serves the static bundle + the contract's API endpoints. |
| `src/` | The backend: the `flow:run` handler, config files + run stash, SDK wiring. |
| `fixtures/` | The two importable flow packages (portal-export zips). |
| `frontend.lock` | The pinned frontend release (`{tag, sha256}`). |
| `.frontend/` · `.runtime/` · `vendor/` | Git-ignored — the fetched bundle, written config/run state (wiped every boot, `0700`), and vendored libraries never land in the repo. |
