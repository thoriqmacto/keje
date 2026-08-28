# monorepo — Laravel + Next.js starter

A reusable starter kit for new web apps. Laravel 12 API backend, Next.js 15 frontend, organized as a Turborepo.

```
apps/
├── api/   Laravel 12 REST API (Sanctum auth, SQLite default)
└── web/   Next.js 15 App Router (TypeScript, Tailwind 4, shadcn/ui)
```

After setup you get a working baseline:

1. `/` public landing page
2. `/register` and `/login` auth flow
3. `/dashboard` authenticated page that talks to the Laravel API

---

## Fresh install — local mode

The API runs on your machine. Requires **Node ≥ 20**, **PHP ≥ 8.2**, **Composer**.

```bash
# 1. Clone into the name you want for your project
git clone https://github.com/thoriqmacto/monorepo.git my-project
cd my-project

# 2. Start a fresh Git repository for this project
rm -rf .git
git init
git branch -M main

# 3. Install Node dependencies
npm install

# 4. Interactive setup — picks project name, mode, port, auth mode
npm run setup

# 5. Start everything
npm run dev
```

> **Windows PowerShell** — replace step 2 with:
> ```powershell
> Remove-Item -Recurse -Force .git
> git init
> git branch -M main
> ```

> **Why remove `.git`?** `git clone` creates a repository already linked to the original `monorepo` remote. Removing `.git` and running `git init` gives your project a clean history with no connection to the starter. If you'd rather keep the starter's history, skip step 2 and jump to [keeping the starter history](#keeping-the-starter-history) below.

What the setup wizard asks:

| Prompt | Notes |
|---|---|
| **Project name** | Sets `APP_NAME` (Laravel) and `NEXT_PUBLIC_APP_NAME` (browser title). Defaults to the directory name. |
| **Where will the API run?** | Pick "Local machine". |
| **Laravel Herd?** (macOS only) | If yes: asks for Herd parked root + project slug, symlinks `apps/api` there. API URL becomes `http://<slug>.test`. |
| **API port** (no Herd) | Default `8000`. API URL becomes `http://localhost:<port>`. |
| **Auth mode** | `bearer` (default) or `cookie`. |
| **Seed demo user?** | Creates `demo@example.com` / `password`. |

Visit **http://localhost:3000** → sign in or register → `/dashboard`.

**Demo credentials** (after seeding):

```
email    demo@example.com
password password
```

---

## Fresh install — remote mode

The API is hosted elsewhere; only the Next.js frontend runs locally.

```bash
# 1. Clone into the name you want
git clone https://github.com/thoriqmacto/monorepo.git my-project
cd my-project

# 2. Start a fresh Git repository for this project
rm -rf .git
git init
git branch -M main

# 3. Install Node dependencies
npm install

# 4. Interactive setup
npm run setup

# 5. Start the frontend only
npm run dev:web
```

When prompted:

| Prompt | Example value |
|---|---|
| **Project name** | `My App` |
| **Where will the API run?** | Pick "Remote backend". |
| **Backend API origin** | `https://api.example.com` (no path) |
| **Frontend origin** | `https://app.example.com` (for CORS) |
| **Auth mode** | `bearer` (default) |

Laravel bootstrap (migrate, key:generate) is skipped in remote mode — run those on the remote host.

---

## Connect the new project to GitHub

After running setup, create an **empty** GitHub repository (no README, no `.gitignore`, no license — adding those creates a commit that conflicts with your first push). Then:

```bash
git add .
git commit -m "Initial project setup"
git remote add origin https://github.com/<username>/<new-repository>.git
git push -u origin main
```

---

## Keeping the starter history

If you want to preserve the starter's Git history and simply point the repository at your own remote, skip the `rm -rf .git` step and replace the remote instead:

```bash
git remote remove origin
git remote add origin https://github.com/<username>/<new-repository>.git
git push -u origin main
```

---

## Non-interactive install

```bash
# Local
node scripts/setup.mjs \
  --non-interactive \
  --project-name="My App" \
  --mode=local \
  --auth-mode=bearer \
  --port=8000 \
  --seed

# Remote
node scripts/setup.mjs \
  --non-interactive \
  --project-name="My App" \
  --mode=remote \
  --api-url=https://api.example.com \
  --frontend-origin=https://app.example.com
```

---

## How project naming works

When you clone the repo as `my-project` and run setup, the setup wizard:

1. Prompts "Project name" — defaults to the directory name (e.g. `My Project` from `my-project`).
2. Writes `APP_NAME=My Project` into `apps/api/.env` — controls the Laravel app name, mail sender name, and log prefix.
3. Writes `NEXT_PUBLIC_APP_NAME=My Project` into `apps/web/.env.local` — used for the browser tab title and any UI branding.

To rename the project later without re-running full setup:

```bash
npm run setup:env   # reruns only the env-writing step
```

Or edit the two env files directly:

```bash
# apps/api/.env
APP_NAME=New Name

# apps/web/.env.local
NEXT_PUBLIC_APP_NAME=New Name
```

---

## Setup modes

### Local mode (default)

```bash
npm run setup           # pick "Local machine"
```

Setup writes `apps/api/.env` and `apps/web/.env.local`, runs `composer install`, creates `apps/api/database/database.sqlite`, runs `php artisan key:generate` and `php artisan migrate`.

### Remote mode

```bash
npm run setup           # pick "Remote backend"
```

Setup writes env files and installs Node dependencies. Laravel bootstrap is skipped.

---

## How the auth flow works

- **Default: Sanctum bearer token.**
  - `POST /api/v1/login` returns `{ user, token, expires_at }`.
  - The web app stores `{ token, user, expiresAt }` in `localStorage`.
  - Every request sends `Authorization: Bearer <token>`.
  - `POST /api/v1/logout` revokes the token.
  - On `401`, the client dispatches `auth:expired`, clears storage, and sends the user to `/login`.
- **Alternative: Sanctum SPA cookie.**
  - Set `NEXT_PUBLIC_AUTH_MODE=cookie`.
  - Set `CORS_SUPPORTS_CREDENTIALS=true` and include your web origin in `SANCTUM_STATEFUL_DOMAINS`.
  - The `cookie` adapter primes `/sanctum/csrf-cookie` before each mutating call.
- **Frontend-only dev: `mock`.**
  - Set `NEXT_PUBLIC_AUTH_MODE=mock`.
  - No HTTP calls are made. Login/register instantly "succeed" as a fixture user.
  - Useful when the Laravel API is intentionally offline and you only want to iterate on UI.

Adapters live in `apps/web/lib/auth/adapters/`. Adding a new auth method = implement one more adapter.

### Password reset

Shipped and enabled by default:

- `POST /api/v1/forgot-password` → emails a reset link to the user.
- `POST /api/v1/reset-password` → consumes a valid token to set a new password.
- The reset URL in the email points at `${FRONTEND_URL}/reset-password?token=…&email=…` (configured in `App\Providers\AppServiceProvider::boot`).
- In local dev the default mail driver is `log`, so the link appears in `apps/api/storage/logs/laravel.log`.
- Frontend pages: `/forgot-password`, `/reset-password`.

### Email verification

The `User` model implements `MustVerifyEmail`. After register, Laravel sends a signed verification link (TTL controlled by `VERIFICATION_LINK_TTL_MINUTES`, default 60).

- The link in the email points at the **backend** route `/api/v1/email/verify/{id}/{hash}`. The `signed` middleware verifies the URL hasn't been tampered with — no auth header required.
- On success the backend redirects to `${FRONTEND_URL}/verify-email?status=verified`. On a wrong hash → `?status=invalid`. On a tampered signature → 403.
- `/api/v1/email/verification-notification` (auth required, throttled) lets a signed-in user resend the email.
- Changing your email via `PATCH /api/v1/me` clears `email_verified_at` and triggers a new verification email automatically.
- The starter does **not** apply the `verified` middleware to any route — it just makes verification status available. Add `->middleware('verified')` to any route you want to gate.
- Frontend: `/verify-email` page (handles the redirect-back), plus a "Verify your email" card in `/settings` with a "Resend" button shown only when the user is unverified.

---

## API routing / base URL

- `NEXT_PUBLIC_API_BASE_URL` is the **fully-prefixed** base (e.g. `http://localhost:8000/api/v1`). Client code calls `/login`, `/me`, `/logout` — the axios instance prepends it.
- `apps/web/app/api/[...path]/route.ts` is a same-origin proxy handler for SSR or cross-origin-sensitive setups. It reads `API_PROXY_TARGET` (or derives it from `NEXT_PUBLIC_API_BASE_URL`).

Endpoints (all JSON):

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET  | `/api/ping` | public | Health. |
| POST | `/api/v1/register` | public | Throttled. |
| POST | `/api/v1/login` | public | Throttled. |
| POST | `/api/v1/forgot-password` | public | Throttled. |
| POST | `/api/v1/reset-password` | public | Throttled. |
| GET  | `/api/v1/me` | bearer | Current user. |
| PATCH | `/api/v1/me` | bearer | Update name/email. |
| PATCH | `/api/v1/me/password` | bearer | Change password (requires current). Revokes other tokens. |
| POST | `/api/v1/email/verification-notification` | bearer | Re-send the verify-your-email link. Throttled. |
| GET  | `/api/v1/email/verify/{id}/{hash}` | signed URL | Email verification target. Marks user verified, redirects to `${FRONTEND_URL}/verify-email?status=verified`. |
| POST | `/api/v1/logout` | bearer | Revokes current token. |
| GET  | `/api/v1/notes` | bearer | Example resource — list. |
| POST | `/api/v1/notes` | bearer | Example resource — create. |
| DELETE | `/api/v1/notes/{id}` | bearer | Example resource — delete. |

Public auth endpoints are rate-limited to `AUTH_THROTTLE_PER_MINUTE` requests per minute (default `10`), keyed by authenticated user or IP. Exceed the limit and the API responds `429`.

---

## Scripts

From the repo root:

```bash
npm run dev         # Turbo: web + api in parallel
npm run dev:web     # just web
npm run dev:api     # just api (php artisan serve)
npm run build       # Turbo build
npm run lint        # Turbo lint
npm run typecheck   # Turbo typecheck (web only)
npm run test        # Turbo test (runs api tests)
npm run test:api    # apps/api php artisan test
npm run setup       # interactive setup
npm run setup:env   # rewrite env files only
npm run setup:check # preflight + ping smoke test
```

---

## Re-running setup safely

`npm run setup` is idempotent. Existing `.env` values are preserved; only keys you're actively changing get rewritten. A `.bak` copy is saved next to each env file before overwriting.

If you need to start over:

```bash
rm apps/api/.env apps/web/.env.local
npm run setup
```

---

## Environment reference

### `apps/api/.env`
See `apps/api/.env.example`. Key values the setup script manages:

- `APP_NAME` — project name used in mail sender, log prefix, and session cookie name.
- `APP_URL` — full URL the API is served at.
- `CORS_ALLOWED_ORIGINS` — comma-separated origins the browser may call from.
- `CORS_SUPPORTS_CREDENTIALS` — `true` only in SPA-cookie mode.
- `SANCTUM_STATEFUL_DOMAINS` — only matters in SPA-cookie mode.
- `SANCTUM_TOKEN_EXPIRATION_HOURS` — bearer token lifetime (default 8).

### `apps/web/.env.local`
See `apps/web/.env.local.example`.

- `NEXT_PUBLIC_APP_NAME` — shown in the browser tab and any UI branding spots.
- `NEXT_PUBLIC_API_BASE_URL` — includes `/api/v1`.
- `NEXT_PUBLIC_AUTH_MODE` — `bearer` (default) or `cookie`.
- `API_PROXY_TARGET` — server-side proxy target (origin only, no path).

---

## Troubleshooting

- **CORS errors in the browser.** Make sure your web origin is listed in `CORS_ALLOWED_ORIGINS` on the API. Re-run `npm run setup` and restart `php artisan serve`.
- **`401` on `/me` right after login.** You're probably in SPA-cookie mode without `CORS_SUPPORTS_CREDENTIALS=true` or with a missing `SANCTUM_STATEFUL_DOMAINS` entry. Or, in bearer mode, localStorage was cleared. Switch back to bearer (the default) with `npm run setup:env`.
- **`/dashboard` redirects to `/login`.** Middleware relies on the `auth_hint` cookie set at login time. If you cleared cookies, sign in again.
- **Herd link fails.** You're on Linux/Windows — Herd integration is macOS only. Answer "no" to the Herd prompt and use `php artisan serve`.

---

## Example resource

A small **Notes** demo (`/notes` in the web app, `/api/v1/notes` on the API) ships as the end-to-end CRUD template. It's deliberately domain-neutral — copy it when building a real resource, or delete it when you don't need it. Every Notes file has a header comment and `STRUCTURE.md` lists the full removal checklist.

---

See `STRUCTURE.md` for the layout map and where to put new code.
