# Devpost write-up draft - Identity Vault

(Check the official hackathon page for current rules, categories, deadline and any required PayPal products before submitting.)

## Tagline
Stop fraud *before* the payment rail: live, verified, explainable approval in front of PayPal.

## Inspiration
In South Africa and across emerging markets, synthetic identities and account takeover drain fintechs, and every bank rebuilds the same KYC and fraud plumbing. We wanted a plug-and-play identity layer that proves *a live person who matches Home Affairs records is making this exact payment*, and does it in a way a risk team and a regulator can audit.

## What it does
Identity Vault sits between a buyer and PayPal. The buyer completes a random liveness challenge and face match; the server returns a single-use verification token bound to the amount and payee. A decision engine combines Home Affairs bureau results, selfie-vs-ID-photo match, liveness and behavioural risk (spending anomalies, velocity, typing cadence) into an explainable score with amount-tiered thresholds and hard gates. **PayPal's Orders API is only called if the payment is approved**, and capture needs a signed approval receipt. Every decision shows its factors, and an AI risk analyst (Claude) turns them into an analyst summary, a recommended action and a customer-safe message that never reveals which signals fired.

## How we built it
PHP 8 / SQLite-MySQL backend, vanilla JS SDK with face-api.js in the browser, PayPal Orders v2 + webhooks, Anthropic Messages API for the analyst. Security by construction: server-issued challenges, single-use tokens, AES-256-GCM, hash-chained audit log, signed receipts and webhooks, idempotency keys, scoped API keys.

## Where AI and PayPal are used
- **PayPal:** Orders v2 create/capture with idempotency, decision-gated order creation, receipt-gated capture, signature-verified webhook reconciliation.
- **AI:** Claude as an advisory fraud-operations analyst over structured, PII-free decision factors; the deterministic engine stays the decision-maker so outcomes remain reproducible and auditable. On-device face models handle liveness and matching.

## Challenges
Our v1 trusted the browser's claim that liveness had passed - anyone with the API key could skip biometrics. Rebuilding the trust boundary so the *server* issues, validates and spends verification state was the core work. We also found and fixed an XSS, a leaked API key in page HTML, and a scoring hole where failed liveness could still approve.

## Accomplishments
87 automated checks (unit, HTTP end-to-end including attack cases, production fail-closed), a credential-free demo mode, and a real-browser verified checkout flow.

## What we learned
Explainability is a product feature, not a report: showing *why* a payment was held makes step-up flows and analyst work faster.

## What's next
Server-side presentation-attack detection or a certified liveness vendor behind the same interface, threshold calibration on labelled data, PayPal Agent/MCP tooling for an analyst copilot, and additional bureaus.

## 3-minute demo script
1. `/checkout`, mock mode: pay R250 - show liveness prompts, factor waterfall, "Continue to PayPal", capture.
2. Tick "Simulate unfamiliar device" and pay again - held; show **PayPal was not called**.
3. Pay R30,000 from a fresh account - tiered threshold and "large first-time value" factors.
4. Dashboard: score histogram, click a decision, **Explain with AI**, audit-chain badge.
5. Show `tests/e2e.php` output: the forged-liveness attack now fails.
