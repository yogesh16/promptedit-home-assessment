# PromptEdit — Implementation Plan

## Working rules

- Complete phases in order.
- Keep implementation simple.
- Do not start optional features before core functionality works.
- Update this document when a phase is completed.
- Do not repeat technical specifications from `PROJECT.md` or the Pixelforge skill.
- Ask for approval before making major architectural changes.

## Phase 0 — Repository inspection

- [x] Inspect repository structure.
- [x] Identify Laravel, PHP, Vue and Inertia versions.
- [x] Identify database and frontend package manager.
- [x] Review existing authentication and starter components.
- [x] Check existing tests and coding conventions.
- [x] Read official Pixelforge documentation through the `pixelforge-api` skill.
- [x] Identify deployment environment and configuration requirements.
- [x] Propose the simplest implementation architecture.

**Deliverable:** Inspection report and implementation approach.

Do not modify application code before the initial inspection and plan are approved.

## Phase 1 — Application foundation

- [x] Configure application environment.
- [x] Configure database.
- [x] Implement or reuse authentication.
- [x] Establish generation data model.
- [x] Add database migrations and constraints.
- [x] Create basic authenticated application layout.
- [x] Add foundational tests.

**Deliverable:** Working authenticated application.

## Phase 2 — Pixelforge integration

- [x] Implement provider API client.
- [x] Implement pricing retrieval.
- [x] Implement generation cost estimation.
- [x] Implement generation submission.
- [x] Persist provider identifiers and request information.
- [x] Handle provider errors and uncertain submissions.
- [x] Add focused integration tests.

**Deliverable:** Reliable generation submission and cost estimation.

Refer to the `pixelforge-api` skill for all provider-specific requirements.

## Phase 3 — Generation lifecycle

- [x] Implement webhook endpoint.
- [x] Implement signature verification.
- [x] Implement duplicate event protection.
- [x] Handle out-of-order events.
- [x] Handle early webhook delivery.
- [x] Implement successful generation processing.
- [x] Implement failed generation processing.
- [x] Implement safe financial recording.
- [x] Add polling and recovery.

**Deliverable:** Reliable asynchronous generation lifecycle.

## Phase 4 — User interface

- [x] Build generation submission form.
- [x] Add size and quality selection.
- [x] Add reference URL inputs.
- [x] Display estimated cost.
- [x] Build generation history.
- [x] Display generation statuses.
- [x] Display successful image previews.
- [x] Add loading, empty and error states.
- [x] Verify responsive behaviour.

**Deliverable:** Complete user-facing generation experience.

## Phase 5 — Financial verification

- [x] Verify exact decimal calculations.
- [x] Verify 25% customer markup.
- [x] Verify failed generations are free.
- [x] Verify duplicate events cannot duplicate charges.
- [x] Verify generation-specific rates are preserved.
- [x] Implement provider usage reconciliation.
- [x] Add financial edge-case tests.

**Deliverable:** Verified financial correctness.

## Phase 6 — End-to-end testing

- [x] Test registration and authentication.
- [x] Test generation submission.
- [x] Test successful generation.
- [x] Test failed generation.
- [x] Test duplicate submission.
- [x] Test duplicate and out-of-order webhooks.
- [x] Test missed webhook recovery.
- [x] Test user authorization.
- [x] Test frontend production build.
- [x] Run complete automated test suite.

**Deliverable:** Stable core application.

## Phase 7 — Deployment

- [ ] Configure production environment.
- [ ] Configure production database.
- [ ] Configure queue workers.
- [ ] Configure scheduler.
- [ ] Configure HTTPS.
- [ ] Configure production Pixelforge credentials.
- [ ] Register production webhook URL.
- [ ] Run migrations.
- [ ] Verify production generation flow.
- [ ] Verify logs and error handling.

**Deliverable:** Publicly accessible working application.

## Phase 8 — Optional assessment features

Only after core functionality is complete:

- [x] Implement admin daily reporting.
- [x] Implement per-user financial reporting.
- [x] Implement provider usage reconciliation dashboard.
- [x] Implement simulated credit purchases.
- [x] Implement credit balance validation.
- [x] Add optional feature tests.

**Deliverable:** Additional assessment features.

---

## Current progress

**Current phase:** Phase 7 — Deployment (still outstanding)

Phases 0–6 and optional Phase 8 are complete. Phase 8 was implemented at the user's request ahead of deployment.

Phase 1:

- Docker Compose environment: `app`, `node`, `db` (PostgreSQL 17), `queue`, `scheduler`.
- Laravel 12 + official Vue/Inertia starter kit with authentication.
- `generations` data model with unique idempotency key and provider ID constraints.
- Generation history page with per-user scoping.

Phase 2:

- `PixelforgeClient` covers `GET /pricing`, `POST /generations`, `GET /generations/{id}`, `GET /generations` and `GET /usage` (the last three are needed by Phase 3 recovery/reconciliation).
- Auth header, JSON accept, timeouts, bounded retries and structured `PixelforgeApiException` (retryable/uncertain flags).
- `PricingService` estimates from live pricing rules using bcmath only; estimates are stored and displayed as **customer charges including the 25% markup**.
- `FinancialService` computes provider cost and customer charge with exact 9-decimal bcmath arithmetic.
- `SubmitGenerationJob` reuses one persisted idempotency key, respects `Retry-After` plus backoff, fails fast on permanent errors, and re-reads generation state under `lockForUpdate` so early webhooks are never clobbered.
- Exhausted uncertain submissions are marked `failed` with `submission_uncertain` and remain free; Phase 3 webhook recovery must reconcile these via provider `metadata.generation_id`.
- Tests use SQLite in memory. `phpunit.xml` overrides both `<env>` and `<server>` because Docker injects `$_SERVER` values that Laravel's dotenv reader prefers.

Phase 3:

- `POST /webhooks/pixelforge` is registered outside the `web` middleware group (no CSRF/session), verifies the raw body HMAC-SHA256 signature and 300-second timestamp window, deduplicates by provider event id via a unique constraint, and returns `204` after durable acceptance.
- `webhook_events` stores every event with status/attempts/error; a `ping` event is acknowledged without storage.
- `GenerationProcessor` owns state transitions under `lockForUpdate`: early webhooks correlate through `metadata.generation_id`, terminal states are never downgraded, provider-failed generations are never revived, and only `submission_uncertain` failures may be reconciled by a late webhook.
- Success records exact provider cost and customer charge once; duplicates and out-of-order events cannot create a second charge.
- Generated images are downloaded to the private `local` disk as validated PNGs and served to the owner only via `GET /generations/{generation}/image`.
- `generations:recover` polls non-terminal generations and retries missing images; scheduled every minute via the `scheduler` service.
- Tests: 98 passing (342 assertions), including signature, duplicate, out-of-order, early-webhook, financial-idempotency, image and polling-recovery cases.

Phase 4:

- `/generations` combines a submission form and history. Size/quality selects, up to four reference URL rows, prompt counter and inline validation errors.
- Estimated cost is fetched (debounced) from the existing estimate endpoint and displayed with the 25% markup note before submission.
- History is shaped server-side to expose only client-relevant fields (`image_url` instead of storage path; no provider id, idempotency key or provider cost).
- Status badges, estimated/actual amounts, created/completed timestamps, failure messages and owner-only image previews.
- History auto-refreshes every 5 seconds while any generation is queued or processing; loading, empty and error states are handled.
- Desktop table and mobile card layouts share the same data.
- Flash messages are now shared through Inertia.
- Sidebar navigation fixed (items used `url` while links read `href`); GitHub/Documentation footer links removed; the default Welcome landing page was deleted and `/` now redirects guests to login and authenticated users to the dashboard.
- Tests: 102 passing (363 assertions); `npm run build` and `npm run lint` pass.

Test database isolation:

- `phpunit.xml` forces SQLite `:memory:` via both `<env force>` and `<server>` overrides (Docker injects real database settings through `$_SERVER`).
- `tests/TestCase.php` aborts any test that is not using the in-memory SQLite driver.
- `TestIsolationTest` asserts the connection, cache and queue drivers and that writes do not persist between tests.
- CI no longer creates an on-disk SQLite file, since tests are fully in memory.

Phase 5:

- Money stays in 9-decimal nanodollar strings via bcmath only; no float arithmetic exists in `app/`.
- Provider cost and customer charge are rounded half-up to the nanodollar (documented rule) so 25% markup stays representable and exact for documented rates.
- Tests cover one-micro-dollar token costs, exact million-token totals, half-up edge cases, documented worked examples and 25% markup.
- Webhook tests prove failed generations stay free (even with usage present), duplicate successes never double-charge or overwrite historical rates/usage, and generation-specific rates drive cost.
- `ProviderUsageReconciler` compares internal provider costs grouped by UTC completion date against `GET /usage`, preserves the provider's exact decimal string, enforces the 92-day limit, and reports per-day and total differences.
- `php artisan pixelforge:reconcile --start=YYYY-MM-DD --end=YYYY-MM-DD` exits non-zero on discrepancies and never modifies records.
- Tests: 115 passing (406 assertions).

Phase 6:

- `EndToEndGenerationFlowTest` covers register → estimate → submit → signed success webhook → history, and failed generations shown as free.
- Webhook tests additionally prove raw-body signature verification (re-encoded JSON rejected), route absence from the `web` middleware group, and that the endpoint only persists and queues work.
- Duplicate submission retries reuse the provider generation and cannot create a second local generation or charge.
- Recovery tests cover polling, terminal skips and avoiding re-polling recently updated rows.
- Image store tests validate PNG content, reject failed/non-PNG downloads and skip existing files.
- Production frontend build (`npm run build`) and lint pass.
- Full suite: 127 passing (506 assertions).
- Live Pixelforge `[succeed]`/`[fail]`/`[duplicate]` smoke tests are pending real credentials; `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET` are not configured locally.

Phase 8:

- `users.is_admin` and `users.credit_balance` (9-decimal) plus a `credit_transactions` ledger with a unique generation reference.
- `CreditService` performs atomic purchases and charges with bcmath; charges are idempotent and recorded only for successful generations.
- Submission is rejected unless the balance covers the estimated maximum customer charge; the actual charge is deducted on success.
- `/credits` shows the balance, simulated purchases and the user's transaction history.
- Admin-only `/admin/reports` provides daily and per-user provider cost, customer charges and profit, plus on-demand Pixelforge usage reconciliation with per-day differences.
- `php artisan users:promote {email}` grants administrator access.
- `php artisan migrate --seed` creates an idempotent default administrator from `ADMIN_NAME`/`ADMIN_EMAIL`/`ADMIN_PASSWORD` (defaults: `admin@promptedit.test` / `password`).
- Tests: 148 passing (680 assertions); frontend build and lint pass.

Known non-Phase-2 lint debt: `pint --test` still reports style issues in pre-existing starter-kit files, and `prettier --check .` reports pre-existing files. Phase 2 files are formatted.

Do not mark a task complete without verifying it.

Update this section as implementation progresses.