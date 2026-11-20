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

- Laravel
- PHP
- Vue 3
- Inertia.js
- MySQL or PostgreSQL
- Pixelforge API

Check the installed dependency versions before setting up the environment.

## Requirements

- PHP compatible with the installed Laravel version.
- Composer.
- Node.js compatible with frontend dependencies.
- npm, pnpm or the package manager used by the repository.
- MySQL or PostgreSQL.
- Pixelforge API credentials.

## Installation

Clone the repository:

```bash
git clone <repository-url>
cd <repository-directory>
```

Install PHP dependencies:

```bash
composer install
```

Install frontend dependencies using the repository's package manager. For npm:

```bash
npm install
```

Configure the environment:

```bash
cp .env.example .env
php artisan key:generate
```

Configure database settings in `.env`.

Run migrations:

```bash
php artisan migrate
```

Start the Laravel server:

```bash
php artisan serve
```

Start the frontend development server:

```bash
npm run dev
```

## Environment configuration

Configure the following application settings in `.env` as applicable:

```dotenv
APP_NAME=PromptEdit
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql

PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
```

Use the actual database driver and environment variables implemented by the application.

Never commit API keys or webhook secrets.

Use separate Pixelforge credentials for local and production environments.

## Background processing

Run the queue worker:

```bash
php artisan queue:work
```

Run the scheduler during development:

```bash
php artisan schedule:work
```

Production must have properly managed queue workers and scheduled tasks.

## Testing

Run backend tests:

```bash
php artisan test
```

Build frontend assets:

```bash
npm run build
```

Run the relevant frontend tests and checks available in the repository.

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