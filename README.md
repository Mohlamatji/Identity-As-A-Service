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
