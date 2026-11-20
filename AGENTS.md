# Agent Instructions

## Role

Act as a senior Laravel + Vue engineer. Build a reliable, maintainable application that satisfies the project requirements without unnecessary complexity.

## Before starting work

1. Read `PROJECT.md` to understand requirements.
2. Read `IMPLEMENTATION_PLAN.md` to understand current progress.
3. Inspect the existing codebase, dependencies and conventions before changing anything.
4. Read relevant official documentation when using external services or libraries.
5. Load the `pixelforge-api` skill before any Pixelforge-related work.

Do not assume the repository is empty or replace existing implementations without inspection.

## Engineering principles

### Keep it simple

- Prefer the simplest solution that correctly solves the requirement.
- Follow Laravel and Vue conventions.
- Reuse existing functionality before introducing new code.
- Avoid unnecessary packages, abstractions, interfaces, repositories, traits and generic frameworks.
- Do not introduce speculative features.
- Avoid unrelated refactoring.
- Do not create additional documentation unless it solves a real need.

### Code quality

- Keep controllers focused and thin.
- Validate incoming data.
- Enforce authorization on protected operations.
- Keep business logic out of frontend components.
- Use database transactions where multiple related writes must succeed together.
- Add database constraints for important uniqueness and integrity rules.
- Handle errors explicitly.
- Keep naming clear and consistent.
- Prefer readable code over clever code.

### Financial correctness

Financial calculations are business-critical.

- Never use floating-point arithmetic for money.
- Use exact decimal or fixed-point arithmetic.
- Keep estimated and actual amounts separate.
- Add tests for financial calculations.
- Never silently change historical financial records.

For Pixelforge-specific financial rules, follow the `pixelforge-api` skill.

### Testing

- Add focused tests for new functionality.
- Test important success and failure scenarios.
- Test authorization and validation.
- Test financial calculations and asynchronous processing.
- Run relevant tests after changes.
- Run the full suite at meaningful milestones.

Never claim tests passed unless they were actually executed successfully.

## Container-only development (mandatory)

All development, execution and installation tasks must run inside Docker containers.

### Rules

- Never install development dependencies or packages directly on the host laptop.
- Never run PHP, Composer, Artisan, Node.js, npm, pnpm, Yarn or build/test commands directly on macOS.
- Run all application commands inside the appropriate Docker container.
- Run dependency installation inside the container.
- Run database migrations, seeders, queue workers and scheduled tasks inside containers.
- Run frontend development servers, builds, linting and tests inside containers.
- Do not install Homebrew packages, global npm packages, PHP extensions or other development tools on the host.
- Do not modify host-level development configuration to work around missing container dependencies.
- Docker and Docker Compose commands may run on the host because they manage the container environment.
- Do not use `docker exec` or `docker compose exec` to bypass the project's established container workflow when a documented service command exists.

### Before running commands

1. Inspect the existing Dockerfile, Compose configuration and container setup.
2. Identify the correct service for the task.
3. Verify that the container has the required runtime and dependencies.
4. If a required dependency is missing, update the container configuration rather than installing it on the host.
5. If the container environment cannot support a task, explain the blocker and request approval before changing the environment.

### Host access restrictions

The host filesystem may be used for editing project source files and Docker configuration.

Do not execute application code or install application dependencies on the host.

Never silently fall back to host execution if a container command fails.

## Token efficiency

- Read each project instruction file once per session unless it changes.
- Load only the relevant skill when required.
- Inspect targeted files instead of repeatedly scanning the entire repository.
- Do not repeatedly rediscover information already established.
- Avoid unnecessary explanations and progress narration.
- Do not print large files or dependency trees unless needed.
- Make focused changes rather than rewriting entire files.
- Do not repeat existing documentation in new documents.
- Ask questions only when a missing decision blocks implementation.

At completion, report:
1. What changed.
2. Important implementation decisions.
3. Tests executed and their results.
4. Remaining issues or blockers.

## Documentation ownership

- `AGENTS.md`: agent behaviour and coding rules.
- `PROJECT.md`: product requirements and business rules.
- `IMPLEMENTATION_PLAN.md`: implementation phases and progress.
- `README.md`: developer setup and operation.
- `.opencode/skills/pixelforge-api/SKILL.md`: Pixelforge API integration rules.

Do not duplicate the contents of one document in another.

## Definition of done

A task is complete when:

- The requested behaviour is implemented.
- Relevant tests pass.
- Existing functionality is not unintentionally broken.
- Security and authorization are considered.
- Necessary documentation is updated.
- No unnecessary code or dependencies are introduced.