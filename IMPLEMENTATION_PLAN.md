# PromptEdit — Implementation Plan

## Working rules

- Complete phases in order.
- Keep implementation simple.
- Do not start optional features before core functionality works.
- Update this document when a phase is completed.
- Do not repeat technical specifications from `PROJECT.md` or the Pixelforge skill.
- Ask for approval before making major architectural changes.

## Phase 0 — Repository inspection

- [ ] Inspect repository structure.
- [ ] Identify Laravel, PHP, Vue and Inertia versions.
- [ ] Identify database and frontend package manager.
- [ ] Review existing authentication and starter components.
- [ ] Check existing tests and coding conventions.
- [ ] Read official Pixelforge documentation through the `pixelforge-api` skill.
- [ ] Identify deployment environment and configuration requirements.
- [ ] Propose the simplest implementation architecture.

**Deliverable:** Inspection report and implementation approach.

Do not modify application code before the initial inspection and plan are approved.

## Phase 1 — Application foundation

- [ ] Configure application environment.
- [ ] Configure database.
- [ ] Implement or reuse authentication.
- [ ] Establish generation data model.
- [ ] Add database migrations and constraints.
- [ ] Create basic authenticated application layout.
- [ ] Add foundational tests.

**Deliverable:** Working authenticated application.

## Phase 2 — Pixelforge integration

- [ ] Implement provider API client.
- [ ] Implement pricing retrieval.
- [ ] Implement generation cost estimation.
- [ ] Implement generation submission.
- [ ] Persist provider identifiers and request information.
- [ ] Handle provider errors and uncertain submissions.
- [ ] Add focused integration tests.

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

**Current phase:** Phase 0 — Repository inspection

Do not mark a task complete without verifying it.

Update this section as implementation progresses.