# PromptEdit

AI image-generation application built with Laravel, Vue 3, Inertia.js and Pixelforge.

## Features

- User authentication.
- AI image generation with size and quality selection.
- Optional reference image URLs.
- Upfront cost estimation and actual cost tracking.
- 25% customer markup with exact decimal arithmetic.
- Asynchronous generation processing, webhooks and recovery.
- Generation history and image previews.
- Simulated credit balance, purchases and transaction history.
- Admin daily and per-user financial reporting.

## Technology

- Laravel 12
- PHP 8.4
- Vue 3 + Inertia.js 2
- PostgreSQL 17
- Vite 6 + npm
- Pixelforge API

## Requirements

- Docker Desktop, OrbStack, or Docker Engine with Compose v2.
- Free ports `8080` (app), `5175` (Vite) and `5432` (database). All are configurable.
- Pixelforge API credentials only when generating real images or testing webhooks.

Do not install PHP, Composer, Node.js or npm on the host. Every application command runs inside a container.

## Quick start

```bash
git clone https://github.com/yogesh16/promptedit-home-assessment.git
cd promptedit-home-assessment
docker compose up -d --build
```

On the first start, the `app` container automatically:

1. Installs Composer dependencies.
2. Creates `.env` from `.env.example` when missing.
3. Generates `APP_KEY`.
4. Runs database migrations.
5. Seeds the default administrator.

The `node` container installs npm dependencies and starts the Vite dev server.

Wait until `http://localhost:8080/up` responds, then open:

**http://localhost:8080**

Default administrator:

| Field | Value |
|---|---|
| Email | `admin@promptedit.test` |
| Password | `password` |

Change `ADMIN_NAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` before the first start if you want different credentials. The seeder is idempotent and never resets an existing password.

Check startup progress with:

```bash
docker compose ps
docker compose logs -f app
```

## Services

| Service | Purpose | Local URL |
|---|---|---|
| `app` | Laravel HTTP server | `http://localhost:8080` |
| `node` | Vite dev server | `http://localhost:5175` |
| `db` | PostgreSQL 17 | `localhost:5432` |
| `queue` | Queue worker | — |
| `scheduler` | Task scheduler | — |
| `cloudflared` | Cloudflare Tunnel (profile `tunnel`) | — |

## Configuration

The defaults work without editing anything. To customise, create a local environment file before the first start:

```bash
cp .env.example .env
```

Then edit `.env` and restart:

```bash
docker compose up -d
```

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | URL used for generated links and assets |
| `APP_PORT` | `8080` | Host port for the app |
| `VITE_PORT` | `5175` | Host port for the Vite dev server |
| `VITE_DEV_SERVER_ORIGIN` | derived from `APP_URL` | Public origin of the Vite dev server when not using localhost |
| `DB_DATABASE` | `promptedit` | PostgreSQL database |
| `DB_USERNAME` | `promptedit` | PostgreSQL user |
| `DB_PASSWORD` | `secret` | PostgreSQL password |
| `DB_FORWARD_PORT` | `5432` | Host port for PostgreSQL |
| `PIXELFORGE_BASE_URL` | `https://pixelforge.promptedit.dev/v1` | Pixelforge API base URL |
| `PIXELFORGE_API_KEY` | empty | Pixelforge API key |
| `PIXELFORGE_WEBHOOK_SECRET` | empty | Webhook signing secret |
| `CLOUDFLARE_TUNNEL_TOKEN` | empty | Token for the optional tunnel service |
| `ADMIN_NAME` | `PromptEdit Admin` | Seeded administrator name |
| `ADMIN_EMAIL` | `admin@promptedit.test` | Seeded administrator email |
| `ADMIN_PASSWORD` | `password` | Seeded administrator password |
| `RUN_MIGRATIONS` | `1` | Run migrations and seed the admin on app start |

Notes:

- `DB_HOST` must stay `db` inside Docker.
- If you change `APP_URL` to a public domain, also update it after your proxy or tunnel is reachable.
- Changing database credentials after the database volume exists will break the connection. Either keep the defaults or reset the volume (see Troubleshooting).
- Never commit API keys or webhook secrets.

## Common commands

Run these while the services are up:

```bash
docker compose ps
docker compose logs -f app

docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=AdminUserSeeder
docker compose exec app php artisan users:promote you@example.com

docker compose exec app php artisan test
docker compose exec node npm run build

docker compose exec app vendor/bin/pint --test
docker compose exec node npm run lint
docker compose exec node npm run format:check

docker compose down
```

For one-off commands when services are not running, use `docker compose run --rm app <command>`.

## Background processing

The `queue` and `scheduler` services start automatically with `docker compose up`.

The `generations:recover` command runs every minute. It polls non-terminal generations when a webhook is missed and retries missing image downloads.

To run a worker or scheduler manually:

```bash
docker compose exec app php artisan queue:work
docker compose exec app php artisan schedule:work
```

Production must use properly managed queue workers and scheduled tasks.

## Pixelforge

Add credentials to `.env` and restart the services that need them:

```dotenv
PIXELFORGE_API_KEY=your-api-key
PIXELFORGE_WEBHOOK_SECRET=whsec_...
```

```bash
docker compose up -d app queue scheduler
```

Reconcile internal provider costs against Pixelforge usage (UTC completion dates, maximum 92-day range):

```bash
docker compose exec app php artisan pixelforge:reconcile --start=2026-11-01 --end=2026-11-30
```

The command exits non-zero when totals differ and never modifies historical financial records.

Official API documentation: https://pixelforge.promptedit.dev/docs

Provider integration rules: `.opencode/skills/pixelforge-api/SKILL.md`

## Webhooks and public URLs

Pixelforge only delivers webhooks to public HTTP(S) URLs. `localhost` and private addresses are rejected, so use a tunnel during local development.

### Cloudflare Tunnel (recommended)

1. Create a tunnel in the Cloudflare dashboard and copy its token.
2. Add the token to `.env`:
   ```dotenv
   CLOUDFLARE_TUNNEL_TOKEN=your-tunnel-token
   ```
3. Start the tunnel:
   ```bash
   docker compose --profile tunnel up -d cloudflared
   ```
4. Add a public hostname route in the Cloudflare dashboard pointing to the published app port:
   - Service type: `HTTP`
   - URL: `host.docker.internal:8080`

   Use `host.docker.internal:<APP_PORT>` if you changed `APP_PORT`.
5. Register this webhook URL in Pixelforge:
   ```text
   https://your-domain.example/webhooks/pixelforge
   ```
6. Verify the tunnel from your machine:
   ```bash
   curl -i https://your-domain.example/up
   curl -i -X POST https://your-domain.example/webhooks/pixelforge
   ```

   Expect `200` for `/up` and `400` for the unsigned webhook.
7. Stop the tunnel when finished:
   ```bash
   docker compose --profile tunnel stop cloudflared
   ```

### Quick temporary tunnel

```bash
docker run --rm -it cloudflare/cloudflared tunnel --url http://host.docker.internal:8080
```

Use the printed `https://…trycloudflare.com` URL as the webhook base. The URL changes on every restart.

## Credits and admin reporting

Users buy simulated credits on `/credits`; there is no real payment gateway. A generation is accepted only when the balance covers the estimated maximum charge. The actual charge is deducted once after a successful generation, and failed generations are free.

Promote an existing user to administrator:

```bash
docker compose exec app php artisan users:promote you@example.com
```

Administrators can open `/admin/reports` for daily and per-user provider cost, customer charges and profit, plus an on-demand Pixelforge usage reconciliation check.

## Testing

Backend tests use an in-memory SQLite database configured in `phpunit.xml` and never touch the development PostgreSQL database:

```bash
docker compose exec app php artisan test
```

Frontend build and quality checks:

```bash
docker compose exec node npm run build
docker compose exec node npm run lint
docker compose exec node npm run format:check
docker compose exec app vendor/bin/pint --test
```

## Troubleshooting

**The app does not become healthy**

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f node
docker compose logs -f db
```

The first start installs Composer and npm dependencies, which can take a few minutes.

**A port is already in use**

Set alternative host ports in `.env`, then restart:

```dotenv
APP_PORT=8081
VITE_PORT=5176
DB_FORWARD_PORT=5433
```

If you change `APP_PORT`, update `APP_URL` and any tunnel target to match.

**Frontend assets or hot reload fail when using a domain**

Set the Vite origin to the hostname browsers use and make the Vite port reachable:

```dotenv
VITE_DEV_SERVER_ORIGIN=https://your-domain.example:5175
```

Restart the `node` service afterwards.

**Dependencies changed**

Docker installs Composer and npm dependencies automatically when lock files change and the containers restart. To force an update:

```bash
docker compose exec app composer install
docker compose exec node npm install
```

**Reset everything**

```bash
docker compose down -v
docker compose up -d --build
```

`-v` removes the database, Composer and npm volumes. All local data is lost.

**Clear Laravel caches**

```bash
docker compose exec app php artisan optimize:clear
```

## Production notes

The provided Compose stack is intended for local development. For production, build a release artifact and run it behind a hardened web server instead of bind-mounting the source.

- Configure a production `.env` with `APP_ENV=production` and `APP_DEBUG=false`.
- Set `RUN_MIGRATIONS=0` and run migrations as a deployment step.
- Use secure database credentials.
- Run queue workers and the scheduler under a process supervisor.
- Serve over HTTPS and register the production webhook URL in Pixelforge.
- Build frontend assets with `npm run build`.

## Project documentation

| File | Purpose |
|---|---|
| `AGENTS.md` | Coding agent instructions |
| `PROJECT.md` | Product requirements |
| `IMPLEMENTATION_PLAN.md` | Development progress |
| `README.md` | Setup and operation |
| `.opencode/skills/pixelforge-api/SKILL.md` | Pixelforge integration rules |
