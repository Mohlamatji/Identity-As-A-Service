# Identity Vault

**Identity-as-a-Service for banks and fintechs.** Drop-in liveness, Home Affairs identity verification, behavioural fraud scoring and an explainable decision engine - now with a **PayPal checkout gate** and an **AI risk analyst**.

> v2 rebuilt the trust model: the server - not the browser - now decides whether liveness and face-match passed. See [`IMPROVEMENTS.md`](IMPROVEMENTS.md) for everything that changed and why, and [`SUBMISSION.md`](SUBMISSION.md) for hackathon-ready write-up text.

## Quickstart (2 minutes)

```bash
php -S localhost:8000 -t public        # needs PHP 8.1+ with pdo_sqlite, curl, openssl
# Console:          http://localhost:8000/
# PayPal checkout:  http://localhost:8000/checkout     <- the hackathon demo
# Dashboard:        http://localhost:8000/dashboard
# API docs:         http://localhost:8000/api-docs
php tests/run.php && php tests/e2e.php && php tests/prod.php     # 87 checks
```

Default mode is `APP_ENV=development`: open API, simulated PayPal, demo console. Production is fail-closed - see **Going to production** below.

## How a verified payment works (v2)

```
Browser/SDK                    Identity Vault (server)                  PayPal
    |  POST /api/verify/challenge  ->  random actions, single-use, 120s TTL
    |  perform blink / turn / open mouth, sample face descriptors
    |  POST /api/verify/session    ->  validates the evidence server-side, matches the
    |                                  enrolled face, returns a SINGLE-USE verification_token
    |
Merchant backend
    |  POST /api/paypal/orders {verification_token, amount_cents, ...}
    |        -> consent -> spend token -> ID check -> DHA -> behaviour -> explainable decision
    |        -> approved?  yes: create PayPal order (Orders v2)   no: PayPal is never called  --------->
    |  buyer approves at PayPal
    |  POST /api/paypal/orders/{id}/capture  -> re-checks the signed receipt, then captures -------->
```

### Integrating (curl)

```bash
# 0. Mint a key for your backend (shown once, stored hashed)
php bin/create-api-client.php --name="Demo Bank" --scopes=verify,consent,transactions
KEY=ivk_live_...

# 1. Your backend mints a 15-minute BROWSER token (never ship the secret key to a browser)
curl -X POST localhost:8000/api/client-token -H "X-Api-Key: $KEY" -d '{"scopes":["verify","consent"]}'

# 2. Browser: use the SDK (public/js/identity-vault.js) - it runs the challenge for you
#    IdentityVault.verify({ clientToken, userId, amountCents: 50000, video })  ->  { verificationToken }

# 3. Backend: approve (or create the PayPal order) with the token. Idempotency-Key makes retries safe.
curl -X POST localhost:8000/api/transaction/approve -H "X-Api-Key: $KEY" -H "Idempotency-Key: order-1042" \
  -d '{"user_id":1,"id_number":"8001015009087","amount_cents":500000,"verification_token":"ivs_..."}'
```

Biometric results are **never** accepted in the request body - `verification_token` is the only proof.

## Going to production

Set these (via `config/local.php`, see the `.example`): `APP_ENV=production`, `APP_ENCRYPTION_KEY` (32+ random chars), `ADMIN_PASSWORD_HASH`, and mint a per-client key. In production the app refuses to start crypto without a real key, requires auth on every endpoint, locks the admin area until a password is set, disables the demo console/checkout and simulated liveness, ignores client-supplied country/device overrides, and requires `Idempotency-Key` on money-moving calls. Behind a CDN, set `TRUST_PROXY_HEADERS=1`. The first request after upgrading runs idempotent schema migrations automatically (SQLite and MySQL).

## PayPal integration

`PAYPAL_MODE=mock` (default) simulates PayPal so the demo always runs. For the real thing: create a sandbox REST app at developer.paypal.com, then set `PAYPAL_MODE=sandbox`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` (and `PAYPAL_WEBHOOK_ID` + point a PayPal webhook at `/api/paypal/webhook` to reconcile async events). Uses Orders v2 (`/v2/checkout/orders`, `/capture`) with `PayPal-Request-Id` idempotency. Confirm PayPal supports your settlement currency; the PayPal leg defaults to USD with an indicative ZAR rate. Request construction is unit-tested against a stubbed transport; run one sandbox payment yourself before submitting.

## AI risk analyst

On any transaction the dashboard's **Explain with AI** produces an analyst summary, a recommended next action, and a customer-safe message that never reveals which signals fired. It is advisory only - the deterministic engine decides - and the model sees only factor codes and points, never names, ID numbers or biometrics. Set `ANTHROPIC_API_KEY` (model via `ANTHROPIC_MODEL`); without it a deterministic template analyst answers in the same shape.

---

# Original documentation

The sections below predate v2. Where they conflict with the above (notably the curl examples, which assumed the old client-reported liveness model, and the "Security" section), the v2 sections win.


Biometric-first KYC / fraud-prevention pilot skeleton. PHP MVC backend, plain
JS frontend, SQLite for zero-setup local testing (swap to MySQL for
production - see `database/schema.mysql.sql`). All transaction amounts are
in **ZAR (South African Rand)**, stored as integer cents.

## Requirements

- PHP 8.1+ with `pdo_sqlite` and `curl` extensions enabled
- A browser (for the camera capture step; the flow also works without a
  camera using a placeholder frame)
- If deploying via Apache/XAMPP instead of the built-in server: mod_rewrite
  enabled and `AllowOverride All` for the project folder (both are XAMPP
  defaults) so `public/.htaccess` can route pretty URLs

No Composer install is required - a tiny autoloader (`vendor_autoload.php`)
maps the `App\` namespace to `/app`.

## Setting real secrets (API keys, passwords)

Copy `config/local.php.example` to `config/local.php` and fill in your real
values there - it's loaded automatically if present, before any other
config. This is the one place actual secrets belong in this project.

**`config/local.php` should never be committed, zipped up, or pasted
anywhere (including into a chat) - treat it like a password file.**
`.gitignore` already excludes it if you put this under version control.

```bash
cp config/local.php.example config/local.php
# then open config/local.php and replace the placeholder values yourself
```

## Running it locally

**Option A — PHP's built-in server (recommended, zero config):**

```bash
cd identity-vault
php -S localhost:8000 -t public
```

Open **http://localhost:8000** for the test console, or **http://localhost:8000/dashboard**
for the admin dashboard. The SQLite database auto-creates at
`storage/database.sqlite` on first request.

**Option B — Apache / XAMPP:**

Drop the whole project folder into `htdocs` (e.g. `htdocs/identity-vault`)
and open `http://localhost/identity-vault/` in a browser. The root
`index.php` redirects you straight into the app - no need to navigate into
`/public` manually - and `public/.htaccess` (mod_rewrite, on by default in
XAMPP) routes pretty URLs like `/dashboard` and `/api/...` correctly no
matter how deep the folder sits in `htdocs`. Both deployment modes hit the
same router and work identically; nothing else to configure.

## Architecture

**Services** (`app/Services/`):
- `BiometricService.php` - orchestrates capture: derives a template from the
  image, runs liveness, and calls VaultService to enroll/match.
- `VaultService.php` - encrypts and stores biometric templates; compares new
  captures against the enrolled template (the flowchart's "Identity Vault
  Match" step).
- `BehaviorService.php` - transaction velocity scoring, plus device/location
  baseline tracking and anomaly detection.
- `DecisionEngine.php` - rule-based fraud decision: vault match gate, then a
  weighted score from DHA match + liveness + vault + behavioral risk.
- `EncryptionService.php` - shared AES-256-CBC helper (random IV per call)
  used for phone numbers and biometric templates.
- `VerificationAdapters/` - pluggable DHA-accredited bureau clients (mock,
  Datanamix, VerifyNow stubs) behind `VerificationAdapterInterface`.

**Database tables** (`database/schema.sqlite.sql` / `schema.mysql.sql`):
`users` (includes a mocked `dha_id_mock` reference), `biometric_templates`
(encrypted at rest), `behavior_profiles` (device/location baseline),
`transactions` (ZAR cents + currency column), `audit_log` (append-only).

## API Endpoints

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/enroll` | POST | Create user (if needed) + capture/enroll biometric template |
| `/api/authenticate` | POST | Verify a biometric sample against the vault (no transaction) |
| `/api/transaction/approve` | POST | Full pipeline: DHA + behavioral + Decision Engine, records a transaction |
| `/api/behavior/update` | POST | Set/refresh a user's device+location baseline |
| `/api/fraud/check` | GET | Standalone anomaly check against the baseline, no transaction recorded |
| `/api/consent/grant` | POST | Record explicit consent for DHA verification (reason + validity period) |
| `/api/consent/status` | GET | Check a user's current consent status |
| `/api/consent/revoke` | POST | Revoke a user's active consent |
| `/api/data-subject/export` | GET | Full data export for a user (POPIA s23-24) |
| `/api/data-subject/delete-request` | POST | Process an erasure request (POPIA s25) |
| `/api/admin/retention/run` | POST | Run the configured retention policy on demand |
| `/api/transactions` | GET | Recent transactions across all users (dashboard) |
| `/api/audit-log` | GET | Append-only audit trail (dashboard) |
| `/api/dha-verifications` | GET | Full (decrypted) bureau response history for one user - compliance/investigation |
| `/api/webhook/notify/{id}` | POST | Fires the simulated bank API call or fraud alert for a transaction |
| `/dashboard` | GET | Admin dashboard - fraud alerts, scores, recent actions |

### Example: `POST /api/authenticate` response

```json
{
  "status": "approved",
  "confidence": 0.92,
  "message": "Biometric match successful"
}
```

## Testing the full flow

The home page (`/`) is a plain test console (not production UI) walking
through: **Enroll -> Authenticate -> Transaction Approve -> Fraud Check ->
Webhook**. It includes two checkboxes to simulate a different device or
location on a transaction, which route into `BehaviorService`'s anomaly
scoring.

### Testing with curl

Use the v2 flow in the section at the top. A full scripted version of it (challenge -> evidence -> token -> approve -> PayPal -> capture, plus the attack cases) lives in `tests/e2e.php`.

## Identity Vault Match

Distinct from DHA verification: it proves *this capture is the same person
who originally enrolled*, not *the enrolled person is who their ID document
says*. Implemented in `VaultService`, called from `BiometricService`, run
before DHA verification in `/api/transaction/approve`:

- **First capture for a user** - enrolls the template.
- **Subsequent captures** - compared against the enrolled template (never
  overwritten). A mismatch short-circuits `DecisionEngine` to an automatic
  `rejected`, regardless of DHA/behavioral scores.

**MVP caveat:** the template is a SHA-256 hash of raw image bytes, not a
real face embedding, so comparison is exact-match only. Swap in real
embedding + cosine-similarity matching before relying on this for genuine
repeat-user verification.

## Behavioral baseline & fraud simulation

`behavior_profiles` stores one row per user: their last-known device
fingerprint and IP country. `BehaviorService`:
- compares a new attempt's device/location against that baseline
  (`checkFraud`, backing `/api/fraud/check`)
- folds a mismatch into the Decision Engine's risk score
  (`score`, used inside `/api/transaction/approve`)
- refreshes the baseline only after an **approved** transaction
  (`updateBaseline`) - so a fraudster's first attempt can't just become the
  new "normal"

For demos, `/api/transaction/approve` also accepts explicit `new_device` /
`ip_country_mismatch` booleans that override the baseline comparison - this
is what the test console's simulation checkboxes use, so you can trigger the
anomaly path without a second physical device.

## Real liveness detection

Client-side (`public/js/capture.js`) uses [face-api.js](https://github.com/justadudewhohacks/face-api.js)
(loaded from a CDN, models loaded lazily on first capture) to run genuine
face-landmark analysis over a ~3 second window:
- **Blink detection** - tracks eye-aspect-ratio (EAR) from eye landmarks;
  a dip below threshold followed by recovery counts as a blink.
- **Head-turn detection** - tracks the face bounding box's horizontal
  center across frames; lateral movement past a threshold counts as a turn.

The result is sent to the API as `mode: 'real'` with `blink_detected`,
`head_turn_detected`, and `frames_analyzed`, and `LivenessService` requires
both signals plus a sane frame count/duration to pass.

**Fallback:** if face-api.js or its models fail to load (offline, restricted
network) or no camera/face is available, capture.js falls back to the old
timing-based heuristic, tagged `mode: 'simulated'`. This keeps the app
usable for headless/curl testing and is honest about being weaker - it is
explicitly labeled as not real anti-spoofing evidence, both in the code
comments and in the liveness result's `reason` field.

## Real biometric matching

`VaultService` supports two template types, tracked per-record via
`biometric_templates.algorithm_version`:
- **`face-descriptor-v1`** - a real 128-float face-api.js face-recognition
  embedding, extracted client-side and compared by cosine similarity
  (threshold 0.92 - **illustrative, not calibrated against real capture
  data yet**, since face-api.js's own examples use Euclidean distance
  rather than cosine similarity; tune this before trusting it beyond a demo).
- **`mvp-sha256-stub-v1`** - the original hash-of-image-bytes stub, exact-match
  only. Used automatically when a descriptor isn't available (no camera, no
  face-api.js, or extraction failed).

A capture can only be compared against an enrollment made with the *same*
algorithm - if they differ (e.g. a user enrolled before descriptors were
available, then authenticates with a browser that now supports them), the
match fails closed with a clear "re-enroll to upgrade" reason rather than
guessing across incompatible formats.

`DecisionEngine`'s vault-match score now scales with the real confidence
value (cosine similarity) instead of a flat +10 - a borderline-but-passing
match contributes less than a near-certain one.

## Security

See `IMPROVEMENTS.md` for the full list. In short: scoped, hashed, revocable per-client API keys; short-lived browser client tokens (the long-lived key is never sent to a browser); server-issued single-use liveness challenges and verification tokens; AES-256-GCM at rest; keyed-hash ID lookups; hash-chained audit log; admin session login with throttling and CSRF; CSP and other security headers; per-client rate limiting; idempotency keys; HMAC-signed webhooks and decision receipts.

## UI

The console is a guided six-step wizard (Enroll -> Consent -> Authenticate
-> Approve -> Fraud check -> Notify), not a flat stack of dev-console
panels - only the active step is expanded, prior steps collapse to a
compact state, and the stepper at the top (click any icon to jump to that
step) tracks progress. Results render as human-readable outcome cards
(status icon, plain-language summary, key fields) with the raw JSON tucked
behind a "Show raw response" toggle rather than dumped by default -
`public/js/ui.js` owns this (`renderResultCard`, the `Wizard` controller,
and a small set of inline SVG icons shared across the console and
dashboard). The one deliberately bold visual moment is a scanning glow/
sweep around the camera feed while biometric capture is analyzing -
everything else stays quiet by comparison.

## Production CSS build

The UI previously used Tailwind's Play CDN (`cdn.tailwindcss.com`), which
Tailwind itself flags as unsuitable for production (compiles at runtime in
the browser, larger payload, console warning). It's now a compiled static
stylesheet at `public/css/app.css` instead.

If you change class names in `app/Views/*.php` **or `public/js/*.js`**
(`ui.js` generates Tailwind classes dynamically for result cards/stat
tiles, so it's scanned too), rebuild it:

```bash
cd build
npm install   # first time only
npm run build:css
```

`build/` is dev-only tooling (Tailwind CLI + config) - it's not needed to
run the app, only to regenerate the CSS after markup changes.

## API documentation

Full OpenAPI 3.0 spec at `public/openapi.yaml`, browsable via Swagger UI at
**`/api-docs`** (CDN-loaded, no build step).

## Deploying under Apache / XAMPP

The app can be dropped straight into an `htdocs` folder (see "Running it
locally" above) and works at any nesting depth without configuration beyond
the `public/.htaccess` file already included (needs mod_rewrite + `AllowOverride All`,
both XAMPP defaults). Two things make this work:
- Root `index.php` redirects into `public/` so you don't have to navigate
  there manually.
- `public/index.php`'s router strips whatever subfolder prefix Apache
  reports via `SCRIPT_NAME`, so routes like `/dashboard` resolve correctly
  regardless of where the folder sits in `htdocs`. Verified against both
  `php -S` (no prefix) and a simulated Apache rewrite (nested prefix) - see
  the router's inline comment for the exact reasoning.
- All internal links/asset paths (`js/capture.js`, `api/enroll`, etc.) are
  relative, not absolute (`/js/capture.js`), so they resolve correctly
  under any deployment depth without needing the base-path logic above.

## Swapping in a real DHA-accredited bureau

Set `VERIFICATION_PROVIDER=datanamix` or `verifynow` and fill in the API
key/base URL in `config/local.php` (see "Setting real secrets" above -
don't use `.env.example`/environment variables for this under Apache/XAMPP,
since Apache doesn't inherit your shell's environment).
Everything else talks to `VerificationAdapterInterface`, so nothing outside
`app/Services/VerificationAdapters/` needs to change.

**`VerifyNowAdapter` is confirmed against VerifyNow's real, current
integration guide** (verifynow.co.za/api-docs/integration-guide), not
guessed - base URL, the `x-api-key` auth header, the required
`Idempotency-Key` header, and the `/verify` endpoint's request/response
shape all come directly from their documented examples. Set
`VERIFYNOW_API_KEY` and get an account, and it should work as-is against
their sandbox (`VERIFYNOW_MODE=sandbox`, 0 credits) before switching to
`production`.

One piece is still a documented guess: **the `/facematch` response shape**
isn't shown anywhere in VerifyNow's integration guide (only request
examples are given), so `parseFaceMatchResponse()` in `VerifyNowAdapter`
tries a couple of plausible field names and is flagged accordingly in its
docblock. Confirm it against a real sandbox response before trusting photo-
match confidence scores from this adapter.

**`DatanamixAdapter.php` is still an illustrative stub** - its field names
and endpoint paths haven't been confirmed against real docs the way
VerifyNow's have.

Both adapters check for the `curl` PHP extension before calling out and
return a clear error (not a fatal crash) if it's missing - this was actually
caught the hard way: every adapter test up to this point had only ever
exercised `MockAdapter`, so a genuinely missing `curl` extension in a fresh
environment went unnoticed until the real VerifyNow wiring surfaced it.
Confirmed present with `php -m | grep curl` before relying on either adapter.

## Consent, error handling, and the DHA audit trail

Three gaps closed together, since they all touch the DHA verification step:

**Consent capture** (`ConsentService`, `Consent` model, `consents` table) -
VerifyNow's integration guide requires "explicit consent from the data
subject... with a prescribed reason and length of validity" before running
identity checks. `/api/transaction/approve` now checks
`ConsentService::hasValidConsent()` before calling the bureau adapter at
all, and refuses with a 403 if there's no valid (unexpired, unrevoked)
consent on file. Grant it via `/api/consent/grant`, check it via
`/api/consent/status`, pull it via `/api/consent/revoke` - all reflected in
the test console's new step 2.

**Differentiated error handling** (`VerificationResult::$errorCode`) - a
bureau call failing for an *operational* reason (`insufficient_credits`,
`rate_limited`, `invalid_api_key`, `idempotency_conflict`, `network_error`,
`curl_unavailable`) is no longer indistinguishable from a genuine "this
person's ID didn't verify" (`no_match`). `VerifyNowAdapter` maps VerifyNow's
documented HTTP codes (400/401/402/409/429) to a specific `errorCode`;
`DecisionEngine`'s reasoning text and the API's `dha.error_code` field both
surface it, so a dashboard/ops team can tell "the bureau is out of credits"
from "the person failed verification" - conflating those two was a real gap,
not just a cosmetic one, since only one of them is evidence about the user.

**DHA verification audit trail** (`DhaVerification` model) - the
`dha_verifications` table has existed in the schema since the project's
first build but was never actually written to. Every bureau call now
persists there: provider, match result, error code (if any), cost, and the
**full response payload, encrypted at rest** (same `EncryptionService` as
biometric templates/phone numbers) - names, DOB, transaction IDs, whatever
the bureau actually returned. Pull it via `/api/dha-verifications?user_id=1`.
The mock provider populates a clearly-labeled synthetic response so this is
demonstrable without real bureau credentials.

## POPIA compliance (retention, data subject rights, privacy notice)

Not legal advice - confirm all of this with actual counsel before treating
it as compliant. Four things closed together, since they're all part of
the same picture:

**Retention & deletion** (`config/retention.php`, `RetentionService`) -
biometric templates are purged and DHA verification raw responses are
redacted (row kept, PII-heavy payload dropped) once they pass their
configured retention window. Run on demand via `POST
/api/admin/retention/run`, or schedule `bin/purge-expired-data.php` via
cron for real use. The config file documents a real tension worth
understanding: POPIA says don't keep data longer than necessary, but FICA's
AML record-keeping duties likely require transaction/verification records
to survive longer - POPIA section 14 permits that where another law
requires it.

**Data subject rights** (`DataSubjectService`) - `GET
/api/data-subject/export?user_id=1` returns everything held on a user;
`POST /api/data-subject/delete-request` processes an erasure request. The
deletion is deliberately not a blanket wipe: it deletes what's deletable
now (biometric template, behavior baseline, revokes consent) and
anonymizes direct identifiers, but explicitly reports what's retained
under the same FICA-adjacent reasoning as retention above, rather than
silently keeping data the request seemed to cover.

**Tightened consent capture** - the consent reason field is now read-only,
fixed to "Fraud Prevention/Fraud Detection" to match VerifyNow's own fixed
lawful-purpose wording (their email confirmed it isn't changeable on their
side, so what we record should match what they actually process under). A
separate, required checkbox names the specific data categories (facial
biometric data, ID number) - that affirmative tick is the explicit consent
POPIA s26/27 requires for special personal information, not just clicking
a button next to prefilled text.

**Privacy notice** - a collapsible panel above the console's stepper states
what's collected, why, who's involved (this org as Responsible Party,
VerifyNow as Operator), and links directly to the export/deletion actions.
Satisfies the Openness condition: informing the data subject before
collection, distinct from consent itself.

## What's intentionally stubbed for the MVP

- **Vault match template extraction falls back to a hash-of-image-bytes stub**
  when a real face descriptor isn't available (see "Real biometric matching"
  above for when each path is used).
- **Cosine similarity threshold (0.92) for face descriptors is uncalibrated**
  - a reasonable starting guess, not tested against real same-person/
  different-person capture pairs yet.
- **Encryption key management** (`EncryptionService`) uses an env var, not a
  KMS. Templates and phone numbers are encrypted at rest, but rotate the key
  via a real KMS before production.
- **Bank/webhook integrations** just log a simulated action.
- **`dha_id_mock`** on `users` is generated locally, not returned by a real
  bureau call yet.
- **Head-turn detection threshold** (18px of lateral face-box movement) is a
  rough heuristic, not calibrated against real users/lighting/resolution -
  tune it once you have real capture data.
- **Rate limit thresholds** (10/min authenticate, 20/min transaction/approve)
  are illustrative, not load-tested against real traffic patterns.
- **API key auth is a single shared secret**, not per-integrator keys with
  scoping/revocation - fine for a pilot with one bank partner, not for
  multiple external integrators.

## Data handling notes

- Raw ID numbers are never stored - only a SHA-256 hash, used for lookups.
- Biometric templates and phone numbers are encrypted at rest
  (`EncryptionService`, AES-256-CBC, random IV per record).
- `audit_log` is append-only by convention. On MySQL, grant the app's DB
  user `SELECT, INSERT` only on that table (see the comment at the top of
  `database/schema.mysql.sql`).
