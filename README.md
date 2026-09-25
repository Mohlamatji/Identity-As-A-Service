# Identity Vault - MVP

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

ce`) uses an env var, not a
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
  user `SELECT, INSERT` only on that table (see the comment at the top of
  `database/schema.mysql.sql`).
