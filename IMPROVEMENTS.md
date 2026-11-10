# Identity Vault v2 - what changed and why

Everything below was verified by running it: `php tests/run.php` (50 unit checks), `php tests/e2e.php` (31 HTTP end-to-end checks incl. attack cases), `php tests/prod.php` (6 production fail-closed checks), plus a real-browser run of the checkout flow.

## A. Vulnerabilities found in v1 and fixed

| # | Finding in v1 | Severity | Fix in v2 |
|---|---|---|---|
| 1 | `/api/transaction/approve` trusted `liveness` and `vault_match` objects **from the request body**. Anyone with the API key could send `{"liveness":{"passed":true},"vault_match":{"matched":true}}` and skip biometrics entirely. | Critical | Server records its own verification session; approval accepts only a single-use `verification_token`. Client-reported results are ignored. |
| 2 | The long-lived `API_KEY` was embedded in the HTML of the console and dashboard (`window.__API_KEY__`), i.e. handed to every visitor. | Critical | Browsers use 15-minute, scope-limited client tokens (`ivt_`). Secret keys (`ivk_`) are hashed in the DB and never sent to a browser. Dashboard uses a session login. |
| 3 | Stored XSS: names and server messages were put into `innerHTML` unescaped (dashboard, result cards). Enrolling as `<img onerror=...>` ran script in the admin's browser. | High | All dynamic output escaped; verified in a real browser with a payload name. Plus a Content-Security-Policy. |
| 4 | Liveness could fail yet the payment still approve: DHA 50 + photo 20 + vault 10 = 80 ≥ 70 threshold. | High | Liveness, vault mismatch, DHA no-match and invalid ID are **hard gates** no score can override. |
| 5 | A failed-liveness first capture still **enrolled** a face template (spoof becomes the user's permanent face), and the response leaked the match result (match oracle). | High | No enrollment and no match result on failed liveness. |
| 6 | Admin endpoints (`/api/transactions`, `/api/audit-log`, `/api/dha-verifications`, `/api/admin/retention/run`) and data-subject export/delete were guarded only by the shared key. | High | Admin session (hashed password, throttled, CSRF) or `admin`-scope key; data-subject endpoints need their own scope and a bound token can only touch its own user. |
| 7 | SA ID lookup used plain SHA-256 of a 13-digit structured number - brute-forceable in minutes. | Medium | Keyed HMAC blind index; legacy rows re-keyed transparently on first lookup. Erasure now tombstones the identifier. |
| 8 | AES-256-CBC with no integrity check, and a hard-coded fallback key used silently. | Medium | AES-256-GCM (tamper-detecting), HKDF subkeys per purpose, production refuses a missing/weak key. Legacy ciphertext still decrypts. |
| 9 | `id_number` in approve was never tied to `user_id` (could run DHA on someone else's ID). | Medium | Must match the enrolled user. |
| 10 | Fraud-simulation flags (`new_device`, `ip_country_mismatch`) were honoured from any caller - assert `false` and dodge the check. | Medium | Honoured only in `DEMO_MODE`; country comes from a trusted proxy header; device is bound to the verification session. |
| 11 | Face matching used cosine ≥ 0.92 on face-api.js embeddings (not the metric they're trained for). | Medium | Euclidean distance (the documented metric), configurable threshold, confidence derived from distance, per-user lockout against hill-climbing. |
| 12 | Audit log was append-by-convention only. | Medium | SHA-256 hash chain; `/api/admin/audit/verify` finds the first tampered row; dashboard shows the chain status. |

## B. New capabilities

**Liveness (server-validated).** Server issues a random sequence of 3 from {blink, turn left, turn right, open mouth}; single-use, 120 s TTL. The server checks the sequence, human-plausible timing, that the claimed capture fits the wall-clock since issue, that several face descriptors are the *same person* (face-swap) yet not *identical* (static photo). Baseline-relative thresholds in the browser adapt to each face/camera.

**Behavioural scoring v2.** Named, explainable factors: velocity (count and rolling 24h value), amount z-score against the user's own history, new-account-large-payment, local-time odd hours, recent failures, device/location vs baseline, and behavioural biometrics (keystroke cadence - timing only, never key contents - scripted-input detection, paste detection, deviation from the user's learned typing baseline).

**Decision engine v2.** Amount-tiered thresholds (R5k → 78, R25k → 88), structured `factors[]` and `gates[]`, `required_action` (`step_up` / `manual_review` / `retry_verification`), fail-closed on bureau outage, paid DHA call skipped when a gate will reject anyway, signed decision receipts (HMAC) bank/PayPal-side can verify.

**PayPal.** `POST /api/paypal/orders` runs the whole pipeline and calls PayPal Orders v2 **only if approved** (declined payments never touch the rail); capture requires a valid signed approval receipt; `PayPal-Request-Id` idempotency; webhook receiver verified through PayPal's verify-webhook-signature; `mock` mode for credential-free demos; a full `/checkout` demo page.

**AI risk analyst.** Claude explains a decision for analysts and drafts a customer-safe message. Advisory only; PII-free input (verified by test); schema-validated output; deterministic fallback.

**Platform.** Per-client scoped keys + CLI, browser SDK (`public/js/identity-vault.js`), amount/payee-bound verification tokens, `Idempotency-Key`, per-client rate limiting, HMAC-signed outbound webhooks, security headers, automatic idempotent migrations (SQLite + MySQL), POPIA retention extended to verification sessions.

**Dashboard.** Approval rate, value held back, score histogram, outcome mix, click-through factor waterfall per transaction, PayPal status, AI explain, audit-chain badge.

## C. Honest limitations (say these in your submission - judges respect it)

- Browser-side liveness is **not certified presentation-attack detection**. A determined attacker who controls the client can fabricate evidence. v2 closes replay, forged-result and static-photo attacks; regulated production needs server-side PAD or a certified vendor SDK behind the same interface.
- Thresholds (match distance 0.5, descriptor variance, amount tiers, point weights) are sensible starting values, **not calibrated** on real data.
- The DHA bureau is a mock unless you configure VerifyNow/Datanamix.
- PayPal sandbox/live paths are unit-tested against a stubbed transport and follow PayPal's documented Orders v2 API, but I could not call PayPal from my environment - **run one real sandbox payment before you submit**. Confirm your settlement currency is supported (the PayPal leg defaults to USD).
- The AI path was tested against a stubbed Anthropic transport, not a live key.
- The PayPal gate is only as strong as the merchant integration: a merchant able to call PayPal directly can bypass it. Route capture through the service in production.
- CSP still allows inline scripts (the demo pages use them); move to nonces before a regulated deployment.
