# PromptEdit — Project Requirements

## 1. Overview

Build a web application that allows authenticated users to generate AI images using the Pixelforge API.

The application is part of a Senior Product Engineer take-home assessment.

The priority is to demonstrate:
- Correct financial calculations.
- Reliable asynchronous processing.
- Secure API integration.
- Clean, maintainable code.
- A straightforward user experience.

## 2. Technology

- Backend: Laravel
- Frontend: Vue 3
- Application bridge: Inertia.js
- Database: MySQL or PostgreSQL
- Image generation: Pixelforge API

Use the versions already established by the project dependencies. Do not upgrade dependencies without a reason.

## 3. Core functionality

### Authentication

Users must be able to:
- Register.
- Log in.
- Log out.
- Access their own generation history.

Users must not access another user's private generation records.

### Image generation

Authenticated users can submit an image-generation request with:

- Prompt.
- Image size:
  - 1024x1024
  - 1536x1024
  - 1024x1536
- Quality:
  - Low
  - Medium
  - High
- Optional reference image URLs: maximum four HTTP(S) URLs.

Display an estimated generation cost before submission.

Generation is asynchronous. Users must be able to see the current status.

### Generation history

Display the user's generations with:

- Prompt.
- Selected size.
- Selected quality.
- Status.
- Estimated cost.
- Actual customer charge, when available.
- Generated image, when available.
- Creation and completion information.

Supported statuses:

- Queued
- Processing
- Succeeded
- Failed

### Financial rules

These rules are mandatory:

1. Customer charge = actual provider cost + 25% markup.
2. Failed generations are free.
3. Rejected submissions are free.
4. Estimated cost and actual cost must remain separate.
5. Actual customer charges are recorded only after successful generation.
6. Financial calculations must use exact decimal or fixed-point arithmetic.
7. Historical financial records must remain reproducible.

The application must not charge users based on estimated costs.

### Asynchronous processing

The application must reliably handle:

- Generation submission.
- Status updates.
- Webhook notifications.
- Duplicate notifications.
- Out-of-order notifications.
- Missed webhook recovery.
- Failed generations.

Pixelforge integration details belong exclusively in the `pixelforge-api` skill.

## 4. Optional assessment features

Implement these only after all core functionality is complete.

### Admin reporting

Provide daily and per-user reporting for:

- Provider cost.
- Customer charges.
- Profit.

Provider cost must be reconcilable with Pixelforge usage reports.

### Credit balance

- Users have an application credit balance.
- Provide simulated credit purchases.
- Prevent generation requests when the available balance is insufficient.
- Deduct credits only according to the actual application charging rules.
- Maintain a transaction history.

Do not integrate a real payment gateway.

## 5. Scope boundaries

Do not build:

- A general-purpose image editor.
- Social sharing features.
- Subscription plans.
- Real payment gateway integration.
- Multi-provider image generation.
- Unrequested administrative features.

Avoid scope expansion unless explicitly approved.

## 6. Product priorities

Priority order:

1. Financial correctness.
2. Generation reliability.
3. Security and authorization.
4. Clear user experience.
5. Maintainability.
6. Optional assessment features.

## 7. Definition of done

The core application is complete when:

- Users can register and authenticate.
- Users can submit valid image-generation requests.
- Estimated cost is displayed before submission.
- Generation statuses update reliably.
- Successful images are available to users.
- Failed generations are free.
- Customer charges are correct.
- Generation history is accessible.
- Duplicate and out-of-order events are safe.
- Critical automated tests pass.
- The application is deployed and publicly accessible.