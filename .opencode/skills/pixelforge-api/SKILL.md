---
name: Pixelforge API Integration
description: Use whenever implementing, debugging, testing, or changing Pixelforge API integration, image generation, pricing, usage, webhooks, or provider error handling. Follow the official Pixelforge API contract.
---

# Pixelforge API Integration Skill

## 1. Mandatory documentation

Official API documentation:
https://pixelforge.promptedit.dev/docs

Base URL:
https://pixelforge.promptedit.dev/v1

This documentation is the source of truth.

Before implementing or modifying Pixelforge integration:

1. Read the relevant official documentation section.
2. Verify endpoint paths, HTTP methods, headers, request fields, response fields, error codes, and behaviour.
3. Do not rely on memory, assumptions, examples from other providers, or outdated implementation details.
4. If documentation is unavailable, stop and report the blocker. Do not invent an API contract.
5. Reuse the verified contract during the same task; do not repeatedly reread unchanged sections.
6. If existing code conflicts with the official documentation, report the discrepancy and correct it.

Relevant documentation sections:
- Authentication
- Errors
- Rate limits and quotas
- Idempotency
- Create a generation
- Retrieve a generation
- List generations
- Generation object
- Pricing and token usage
- Usage
- Webhooks
- Images
- Testing

## 2. API contract

### Authentication
- Use `Authorization: Bearer PIXELFORGE_API_KEY`.
- Keep API keys and webhook secrets in environment configuration.
- Use separate API keys for local and deployed environments.
- Each key has its own webhook URL, signing secret, and usage report.

### Generation endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/pricing` | Retrieve current rates and estimation rules |
| POST | `/generations` | Submit a generation |
| GET | `/generations/{id}` | Retrieve generation status and details |
| GET | `/generations` | List generations with cursor pagination |
| GET | `/usage` | Retrieve provider usage and cost by UTC day |

All paths are relative to `/v1`.

### Create generation

`POST /generations`

Supported fields:
- `prompt`: required string, maximum 4,000 characters.
- `size`: `1024x1024`, `1536x1024`, or `1024x1536`.
- `quality`: `low`, `medium`, or `high`.
- `reference_images`: optional array of up to four HTTP(S) URLs.
- `metadata`: optional object with string keys and string values, maximum 20 keys.

Unknown parameters are rejected. Never send undocumented fields.

Use `metadata` to associate the provider generation with the application's internal generation ID. This is essential because a webhook can arrive before the POST response returns.

A successful submission returns HTTP 202 with a generation object.

### Generation lifecycle

Valid lifecycle:

`queued → processing → succeeded`

or

`queued → processing → failed`

Terminal states are immutable.

Never change `succeeded` or `failed` back to `queued` or `processing`.

The generation object contains:
- `id`
- `status`
- `prompt`
- `size`
- `quality`
- `reference_images`
- `metadata`
- `image_url`
- `image_url_expires_at`
- `usage`
- `rates`
- `error`
- timestamps

Use the actual documented response structure. Do not assume optional fields are always populated.

## 3. Financial correctness — highest priority

Never use floating-point arithmetic for money.

Use exact decimal arithmetic or integer-based fixed-point arithmetic.

### Provider pricing

Fetch rates from `GET /pricing`. Do not permanently hardcode provider rates.

The documented token categories are:
- `input_text_tokens`
- `input_image_tokens`
- `output_tokens`
- `reasoning_tokens`

Rates are expressed in USD per million tokens.

Provider cost:

`SUM(token_count × corresponding_rate) / 1,000,000`

Use the generation's own `rates` values for its actual cost. These rates are fixed when the generation is created and protect historical calculations against future price changes.

### Estimated cost

Use the pricing endpoint's token estimation rules.

Documented rules:
- Text estimate: `ceil(Unicode_character_count / 4)`.
- Text token variance: ±15%.
- Each reference image: exactly 765 input image tokens.
- Output tokens: determined by quality and size.
- Reasoning tokens: use the documented minimum and maximum for the selected quality.

Show an estimate range when uncertainty exists. Clearly identify it as an estimate, not an actual charge.

Do not substitute estimated token counts for actual usage.

### Customer charge

Customer charge = actual provider cost × 1.25.

- Markup is 25% of provider cost.
- Calculate the customer charge only after a generation succeeds.
- Failed generations are free.
- Do not charge for rejected submissions.
- Preserve exact precision. Never introduce arbitrary rounding.
- Store provider cost, customer charge, and the rates used for calculation.
- Keep estimated and actual amounts separate.

### Reconciliation

`GET /usage` is the provider source of truth for costs associated with the API key.

- Reconcile internal provider costs against this endpoint.
- Usage is grouped by UTC completion date, not creation date.
- Preserve the provider's exact `cost_usd` decimal string.
- Support the documented maximum 92-day date range.
- Do not assume a generation belongs to the day it was submitted.

Never silently modify historical financial records to force reconciliation. Report discrepancies.

## 4. Idempotency and submission reliability

For every generation submission:
- Generate and persist a unique idempotency key before making the provider request.
- Send it in the `Idempotency-Key` header.
- Retry uncertain submissions using the exact same key and parameters.
- Never generate a new key just because a request timed out.
- Treat `idempotency_key_reused` as a conflict requiring investigation.

The provider documents:
- Same key and same parameters returns the existing generation.
- Same key with different parameters returns HTTP 409.
- Keys are account-scoped and never expire.

Do not create duplicate customer charges or provider generations.

## 5. Webhook security and processing

Webhook endpoint must:
1. Read the raw request body.
2. Verify the signature before decoding or processing JSON.
3. Parse `Pixelforge-Signature` in the documented `t=...,v1=...` format.
4. Calculate HMAC-SHA256 over `timestamp + "." + raw_body`.
5. Use the correct environment's webhook secret.
6. Compare signatures using constant-time comparison.
7. Reject timestamps more than 300 seconds from the current time.
8. Deduplicate using the event's unique `id`.
9. Persist the event or otherwise guarantee safe processing.
10. Return a successful 2xx response quickly after durable acceptance.

Never verify a signature against re-encoded JSON or a normalized request body.

### Event types

- `generation.processing`
- `generation.succeeded`
- `generation.failed`
- `ping`

The full generation object is available in `data.object`.

### Duplicate and out-of-order events

Delivery is at least once and may be out of order.

- Enforce a database uniqueness constraint on provider event ID.
- Duplicate events must not repeat financial operations.
- A delayed `processing` event must not overwrite a terminal state.
- Financial recording must be atomic and idempotent.
- Do not assume webhook delivery order.
- A webhook may arrive before the submission response.

Use the application's internal generation ID from provider `metadata` to correlate early events. Persist the provider generation ID when available. Handle the early-webhook race explicitly; do not discard unmatched events.

A `ping` event must be acknowledged without attempting to update a generation.

### Delivery response

The provider expects a 2xx response within 10 seconds.

Persist the event and dispatch slow work to a queue. Do not perform expensive image downloads or complex processing inside the webhook request.

The provider retries unsuccessful deliveries. Do not rely on retries as a substitute for idempotency.

## 6. Polling and image handling

Webhooks are not guaranteed to arrive.

Implement a bounded polling/recovery mechanism using:

`GET /generations/{id}`

- Poll only non-terminal generations.
- Stop polling after `succeeded` or `failed`.
- Avoid unnecessary frequent requests.
- Do not overwrite newer terminal state with stale responses.
- Recover generations whose webhook was missed.

A successful generation's image URL expires 24 hours after completion.

If the application needs long-term image availability:
- Download the image before expiration.
- Validate the downloaded content.
- Store it using the application's configured storage.
- Save the application's own storage path.
- Do not treat the temporary provider URL as permanent storage.

## 7. Error handling and retry rules

Handle documented errors explicitly:

| HTTP | Error code | Behaviour |
|---|---|---|
| 400 | `content_policy_violation` | Reject; nothing created or billed |
| 401 | `invalid_api_key` | Configuration/authentication failure |
| 404 | `not_found` | Resource unavailable |
| 409 | `idempotency_key_reused` | Investigate conflicting request |
| 422 | `validation_failed` | Correct invalid request |
| 422 | `date_range_too_long` | Reduce usage date range |
| 429 | `rate_limit_exceeded` | Respect `Retry-After` |
| 429 | `concurrency_limit_exceeded` | Wait for a generation to finish |
| 429 | `daily_limit_exceeded` | Stop submissions until quota resets |
| 503 | `overloaded` | Retry safely with bounded backoff |

Rules:
- Respect `Retry-After`.
- Use bounded retries with backoff.
- Never retry forever.
- Do not retry permanent validation or authentication failures.
- Preserve idempotency keys for safe retries.
- Do not assume every 5xx means a generation was created or was not created. Resolve uncertain outcomes safely.

## 8. Testing requirements

Use the provider's documented test prompt keywords.

| Keyword | Expected behaviour |
|---|---|
| `[blocked]` | Rejected with `content_policy_violation` |
| `[overloaded]` | Rejected with `503 overloaded` |
| `[fail]` | Accepted, then fails; no charge |
| `[succeed]` | Accepted and does not fail randomly |
| `[slow]` | Takes 60–90 seconds |
| `[duplicate]` | Webhook event delivered twice |

Test at least:
- Successful generation and exact charge.
- Failed generation with zero charge.
- Duplicate submission.
- Duplicate webhook delivery.
- Out-of-order webhook delivery.
- Webhook arriving before POST response.
- Invalid signature.
- Expired timestamp.
- Missing webhook followed by polling recovery.
- Provider rate changes.
- Exact usage reconciliation.
- Image URL expiration and local storage.
- Insufficient customer credit, if credit functionality exists.

Use mocked provider responses for fast automated tests. Use real provider test prompts for focused integration testing.

## 9. Implementation principles

- Follow the existing Laravel architecture and coding conventions.
- Keep provider HTTP communication in one focused integration layer.
- Keep financial calculations in a dedicated, testable service.
- Keep webhook verification separate from business processing.
- Use database transactions and unique constraints for financial and event idempotency.
- Use queues for asynchronous work.
- Keep controllers thin.
- Prefer Laravel native functionality.
- Avoid unnecessary packages, abstractions, interfaces, repositories, and generic frameworks.
- Do not duplicate provider logic across controllers, jobs, and models.
- Do not add undocumented API features.
- Do not refactor unrelated code.

Choose the simplest implementation that correctly handles the documented behaviour.

## 10. Completion checklist

Before declaring Pixelforge work complete, verify:

- [ ] Official documentation was checked for the changed API behaviour.
- [ ] Request and response contracts match the documentation.
- [ ] No floating-point money calculations exist.
- [ ] Generation-specific rates are preserved.
- [ ] Failed generations are free.
- [ ] Idempotency is implemented.
- [ ] Webhook signatures are verified against raw bodies.
- [ ] Duplicate and out-of-order events are safe.
- [ ] Early webhooks are handled.
- [ ] Polling recovery is available.
- [ ] Image expiration is handled.
- [ ] Provider usage reconciliation is tested.
- [ ] Relevant automated tests pass.

In the final report, state the files changed, tests executed, and any unresolved issues. Never claim an API behaviour or test result that was not verified.