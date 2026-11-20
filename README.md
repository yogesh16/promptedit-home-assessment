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
| `cloudflared` | Cloudflare Tunnel (profile `tunnel`) | — |

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

CLOUDFLARE_TUNNEL_TOKEN=
```

`CLOUDFLARE_TUNNEL_TOKEN` is only used by the optional `cloudflared` service (see Cloudflare Tunnel below).

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

The `generations:recover` command is scheduled every minute. It polls non-terminal generations as a fallback when a webhook is missed and retries missing image downloads.

Reconcile internal provider costs against Pixelforge's usage report (UTC completion dates, maximum 92-day range):

```bash
docker compose run --rm --no-deps app php artisan pixelforge:reconcile --start=2026-11-01 --end=2026-11-30
```

The command exits non-zero when totals differ and never modifies historical financial records.

## Cloudflare Tunnel (webhook testing)

Pixelforge only delivers webhooks to public HTTP(S) URLs. `localhost`, `*.orb.local` and private addresses are rejected, so expose the app with a Cloudflare Tunnel during local development.

The tunnel must target `http://host.docker.internal:8080` (the published `app` port). Do **not** use `*.orb.local` as the origin — it resolves only on the host and is unreachable from containers.

### Dashboard-managed tunnel (token, recommended)

1. In the Cloudflare dashboard: **Zero Trust → Networks → Tunnels → Create a tunnel → Cloudflared**. Copy the connector token.
2. Add it to `.env` (never commit it):
   ```dotenv
   CLOUDFLARE_TUNNEL_TOKEN=your-tunnel-token
   ```
3. Start the tunnel service:
   ```bash
   docker compose --profile tunnel up -d cloudflared
   ```
4. In the tunnel’s **Public Hostname** tab, add a route:

   | Field | Value |
   |---|---|
   | Subdomain / Domain | `promptedit.yourdomain.com` |
   | Path | leave empty |
   | Service type | `HTTP` |
   | URL | `host.docker.internal:8080` |

   Save. Cloudflare creates the DNS record automatically and pushes the ingress config to the connector.
5. Verify the tunnel from the host:
   ```bash
   curl -i https://promptedit.yourdomain.com/up
   curl -i -X POST https://promptedit.yourdomain.com/webhooks/pixelforge
   ```
   Expect `200` for `/up` and `400` for the webhook without a valid signature.
6. In the Pixelforge dashboard, edit the local API key: set its webhook URL to `https://promptedit.yourdomain.com/webhooks/pixelforge` and copy its signing secret (`whsec_…`).
7. Set the secret and restart the app and worker:
   ```bash
   # .env
   PIXELFORGE_WEBHOOK_SECRET=whsec_...
   ```
   ```bash
   docker compose up -d app queue
   ```
8. Click **Send test event** in Pixelforge and confirm the endpoint returns `204`:
   ```bash
   docker compose logs -f cloudflared app queue
   ```
9. Stop the tunnel when finished:
   ```bash
   docker compose --profile tunnel stop cloudflared
   ```

Changing `APP_URL` is only required when browsing the whole app through the tunnel (redirects and assets use it). Webhook delivery itself does not depend on `APP_URL`.

Rotate the tunnel token if it has been shared or exposed. Store it only in `.env` or your deployment secret manager.

### Quick tunnel (temporary URL, no Cloudflare account)

```bash
docker run --rm -it cloudflare/cloudflared tunnel --url http://host.docker.internal:8080
```

Copy the printed `https://…trycloudflare.com` URL. It is random and changes on every restart — update the webhook URL in the Pixelforge dashboard after each restart.

### Locally-managed named tunnel (credentials file)

One-time setup — authenticate, create the tunnel and point DNS at the app (each command opens/prints a URL you complete in the browser):

```bash
V=promptedit-cloudflared

docker run --rm -it -v $V:/home/nonroot/.cloudflared cloudflare/cloudflared tunnel login
docker run --rm -v $V:/home/nonroot/.cloudflared cloudflare/cloudflared tunnel create promptedit
docker run --rm -v $V:/home/nonroot/.cloudflared cloudflare/cloudflared tunnel route dns promptedit webhooks.yourdomain.com
```

Create `docker/cloudflared.yml` (adjust the hostname) and mount it as the tunnel config:

```yaml
tunnel: promptedit
credentials-file: /home/nonroot/.cloudflared/promptedit.json

ingress:
  - hostname: webhooks.yourdomain.com
    service: http://host.docker.internal:8080
  - service: http_status:404
```

Run the tunnel:

```bash
docker run --rm -it \
  -v promptedit-cloudflared:/home/nonroot/.cloudflared \
  -v "$(pwd)/docker/cloudflared.yml:/home/nonroot/.cloudflared/config.yml:ro" \
  cloudflare/cloudflared tunnel run promptedit
```

The stable URL `https://webhooks.yourdomain.com/webhooks/pixelforge` can then be registered once in the Pixelforge dashboard.

## Testing

Run backend tests:

```bash
docker compose run --rm --no-deps app php artisan test
```

Tests run against an in-memory SQLite database configured in `phpunit.xml`; they never touch the development PostgreSQL database. `tests/TestCase.php` fails fast if an unsafe database connection is configured.

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