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

- [ ] Implement webhook endpoint.
- [ ] Implement signature verification.
- [ ] Implement duplicate event protection.
- [ ] Handle out-of-order events.
- [ ] Handle early webhook delivery.
- [ ] Implement successful generation processing.
- [ ] Implement failed generation processing.
- [ ] Implement safe financial recording.
- [ ] Add polling and recovery.

**Deliverable:** Reliable asynchronous generation lifecycle.

## Phase 4 — User interface

- [ ] Build generation submission form.
- [ ] Add size and quality selection.
- [ ] Add reference URL inputs.
- [ ] Display estimated cost.
- [ ] Build generation history.
- [ ] Display generation statuses.
- [ ] Display successful image previews.
- [ ] Add loading, empty and error states.
- [ ] Verify responsive behaviour.

**Deliverable:** Complete user-facing generation experience.

## Phase 5 — Financial verification

- [ ] Verify exact decimal calculations.
- [ ] Verify 25% customer markup.
- [ ] Verify failed generations are free.
- [ ] Verify duplicate events cannot duplicate charges.
- [ ] Verify generation-specific rates are preserved.
- [ ] Implement provider usage reconciliation.
- [ ] Add financial edge-case tests.

**Deliverable:** Verified financial correctness.

## Phase 6 — End-to-end testing

- [ ] Test registration and authentication.
- [ ] Test generation submission.
- [ ] Test successful generation.
- [ ] Test failed generation.
- [ ] Test duplicate submission.
- [ ] Test duplicate and out-of-order webhooks.
- [ ] Test missed webhook recovery.
- [ ] Test user authorization.
- [ ] Test frontend production build.
- [ ] Run complete automated test suite.

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

- [ ] Implement admin daily reporting.
- [ ] Implement per-user financial reporting.
- [ ] Implement provider usage reconciliation dashboard.
- [ ] Implement simulated credit purchases.
- [ ] Implement credit balance validation.
- [ ] Add optional feature tests.

**Deliverable:** Additional assessment features.

---

## Current progress

**Current phase:** Phase 3 — Generation lifecycle

Phase 0 (inspection), Phase 1 (application foundation) and Phase 2 (Pixelforge integration) are complete.

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
- Test suite: 77 passing (PHPUnit, 251 assertions).

Known non-Phase-2 lint debt: `pint --test` still reports style issues in pre-existing starter-kit files, and `prettier --check .` reports pre-existing files. Phase 2 files are formatted.

Do not mark a task complete without verifying it.

Update this section as implementation progresses.