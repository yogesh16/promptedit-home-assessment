# PromptEdit

AI image-generation application built with Laravel, Vue 3, Inertia.js and Pixelforge.

## Features

- User authentication.
- AI image generation.
- Image size and quality selection.
- Optional reference image URLs.
- Upfront cost estimation.
- Actual cost tracking.
- 25% customer markup.
- Asynchronous generation processing.
- Generation history.
- Webhook processing.
- Generated image previews.

Optional features:
- Admin financial reporting.
- Simulated credit balance.

## Technology

- Laravel 12
- PHP 8.4
- Vue 3 + Inertia.js 2 (official Laravel Vue starter kit)
- PostgreSQL 17
- Vite 6 + npm
- Pixelforge API

## Requirements

- Docker with Docker Compose (OrbStack or equivalent).
- Pixelforge API credentials (optional for local development until Phase 2).

All application commands run inside containers. Do not install PHP, Composer, Node.js or npm on the host.

## Installation

Clone the repository:

```bash
git clone <repository-url>
cd <repository-directory>
```

Build and start the environment:

```bash
docker compose up -d --build
```

Services:

| Service | Purpose | URL |
|---|---|---|
| `app` | Laravel (PHP 8.4) | `http://app.promptedit-home-assessment.orb.local` |
| `node` | Vite dev server | `http://node.promptedit-home-assessment.orb.local` |
| `db` | PostgreSQL 17 | port `5432` |
| `queue` | Queue worker | — |
| `scheduler` | Task scheduler | — |

OrbStack resolves `*.orb.local` automatically. `http://localhost:8080` also reaches the app as a fallback.

Install dependencies (first run, or after lockfile changes):

```bash
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps node npm install
```

Configure the environment:

```bash
cp .env.example .env
docker compose run --rm --no-deps app php artisan key:generate
```

Run migrations:

```bash
docker compose run --rm --no-deps app php artisan migrate
```

The application is then available at `http://app.promptedit-home-assessment.orb.local`.

## Environment configuration

Configure the following application settings in `.env` as applicable:

```dotenv
APP_NAME=PromptEdit
APP_ENV=local
APP_DEBUG=true
APP_URL=http://app.promptedit-home-assessment.orb.local

DB_CONNECTION=pgsql
DB_HOST=db

PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
```

Database credentials for Docker are set in `docker-compose.yml`. Use the actual database driver and environment variables implemented by the application.

Never commit API keys or webhook secrets.

Use separate Pixelforge credentials for local and production environments.

## Background processing

The `queue` and `scheduler` services run automatically with `docker compose up`.

To run commands manually inside containers:

```bash
docker compose run --rm --no-deps app php artisan queue:work
docker compose run --rm --no-deps app php artisan schedule:work
```

Production must have properly managed queue workers and scheduled tasks.

Pixelforge webhook URLs must be publicly reachable. For local development, expose the app with a Cloudflare Tunnel and register the public URL in the Pixelforge dashboard.

## Testing

Run backend tests:

```bash
docker compose run --rm --no-deps app php artisan test
```

Build frontend assets:

```bash
docker compose run --rm --no-deps node npm run build
```

Lint and format checks:

```bash
docker compose run --rm --no-deps node npm run lint
docker compose run --rm --no-deps node npm run format:check
docker compose run --rm --no-deps app vendor/bin/pint --test
```

## Pixelforge

Official API documentation:

https://pixelforge.promptedit.dev/docs

Provider-specific integration rules are maintained in:

`.opencode/skills/pixelforge-api/SKILL.md`

## Deployment checklist

- Production environment configured.
- Database configured.
- Migrations executed.
- Queue workers running.
- Scheduler configured.
- HTTPS enabled.
- Production API credentials configured.
- Production webhook URL registered.
- Frontend assets built.
- Generation flow tested.

## Project documentation

| File | Purpose |
|---|---|
| `AGENTS.md` | Coding agent instructions |
| `PROJECT.md` | Product requirements |
| `IMPLEMENTATION_PLAN.md` | Development progress |
| `README.md` | Setup and operation |
| `.opencode/skills/pixelforge-api/SKILL.md` | Pixelforge integration rules |

## Development approach

This project prioritizes correctness, reliability and maintainability over unnecessary architectural complexity.

See `IMPLEMENTATION_PLAN.md` for current development progress.