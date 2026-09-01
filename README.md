# Keje

Keje is a personal YouTube content-production and publishing workflow application.

It converts lecture audio recordings and background artwork into ready-to-upload YouTube videos using FFmpeg, manages the content-production workflow, backs rendered videos up to Google Drive, and integrates with the YouTube Data API for uploading and scheduled publication.

```
Lecture audio
     +
Background image
     +
Content metadata
     ↓
Keje Content Studio
     ↓
Laravel queue
     ↓
FFmpeg
     ↓
YouTube-ready MP4
     ├── Google Drive
     └── YouTube
```

The manual workflow it replaces — Audacity pre-processing, a hand-written FFmpeg shell script, manual Drive upload, manual YouTube scheduling — collapses into one form and one **Render** button. **The Audacity step is gone**: Keje accepts the original recording and normalises it itself.

```
apps/
├── api/   Laravel 12 REST API
│         queue jobs · FFmpeg · Google Drive · YouTube
│
└── web/   Next.js 15 Content Studio UI
```

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

### Where each session lands

- Signed in, visiting `/`, `/login` or `/register` → redirected to `/dashboard`.
- Signed out, visiting `/dashboard`, `/studio` or `/settings` → redirected to `/login?next=<where you were going>`, and sign-in returns you there. Only same-site paths are accepted; anything else falls back to `/dashboard`.
- Signed out, visiting `/` → the public landing page, as normal.
- `/forgot-password`, `/reset-password` and `/verify-email` stay reachable either way, because they are opened from emailed links.

The `auth_hint` cookie only decides which page renders first, so the anonymous
landing page never flashes for a signed-in user. It is **not** authorization:
the bearer token and `/me` remain authoritative, and a stale hint is cleared as
soon as the app finds no stored token.

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
- `APP_TIMEZONE` — `Asia/Pontianak`. Timestamps are still stored UTC; this is the timezone the app reasons in, so behaviour never depends on the VPS clock's timezone.
- `QUEUE_CONNECTION` — must not be `sync`; rendering runs on the `media` queue.

Media rendering (API host only — see `apps/api/.env.example` for the annotated list):

- `MEDIA_FFMPEG_PATH`, `MEDIA_FFPROBE_PATH` — binaries on the render host.
- `MEDIA_FONT_FILE`, `MEDIA_FONT_BOLD_FILE` — TrueType fonts the templates draw with.
- `MEDIA_MAX_AUDIO_MB` (512), `MEDIA_MAX_IMAGE_MB` (20) — upload ceilings.
- `MEDIA_VIDEO_WIDTH`, `MEDIA_VIDEO_HEIGHT`, `MEDIA_VIDEO_FPS` — output canvas.
- `MEDIA_VIDEO_CRF`, `MEDIA_VIDEO_PRESET` — H.264 quality/speed.
- `MEDIA_WAVE_WIDTH`, `MEDIA_WAVE_HEIGHT`, `MEDIA_WAVE_COLOR`, `MEDIA_WAVE_MODE` — waveform.
- `MEDIA_AUDIO_SAMPLE_RATE`, `MEDIA_AUDIO_BITRATE` — output audio track.
- `MEDIA_LOUDNORM_ENABLED` — EBU R128 loudness normalisation. **Off by default.**
- `MEDIA_RENDER_TIMEOUT` — ceiling for one render; keep below the worker's `--timeout`.
- `MEDIA_STREAM_LINK_TTL_MINUTES` — lifetime of a signed playback link.

Google — **secrets, API host only, never Vercel, never `NEXT_PUBLIC_*`**:

- `GOOGLE_YOUTUBE_CLIENT_ID`, `GOOGLE_YOUTUBE_CLIENT_SECRET`, `GOOGLE_YOUTUBE_REDIRECT_URI` — the YouTube OAuth client.
- `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REDIRECT_URI` — the Drive OAuth client. Separate on purpose; see [Google setup](#google-setup).
- `GOOGLE_DRIVE_FOLDER_ID` — optional; a folder is created by name when unset.
- `GOOGLE_DRIVE_FOLDER_NAME` — default `Keje YouTube Outputs`.
- `YOUTUBE_EXPECTED_CHANNEL_ID` — uploads are blocked when the connected channel differs.
- `YOUTUBE_DEFAULT_CATEGORY_ID` — default `27` (Education).

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
- **`/` redirects to `/dashboard` when you expect the landing page.** That is the `auth_hint` cookie doing its job. If you are actually signed out, load the page once: the app clears the stale hint on boot and `/` becomes public again. To browse the landing page while signed in, sign out first.
- **A migration failed partway on MySQL/MariaDB.** MySQL commits each DDL statement immediately and cannot roll a schema change back, so a failure leaves the earlier statements applied and the migration unrecorded. Keje's migrations are written to be safe to re-run over that partial state: fix the cause, then `php artisan migrate --force` again. `php artisan down` / `php artisan up` bracket the deploy, so the app stays in maintenance mode until it succeeds.
- **`tempnam(): file created in the system's temporary directory`.** Blade could not write a compiled view, because `storage/framework/views` is not writable by the PHP-FPM user — usually because a deploy running as another user created those files first. This is almost always a *secondary* failure: it happens while rendering the error page, so the exception that actually broke the request is discarded with it. **Look in `storage/logs/laravel.log` for the real error.** Fix the permissions per [Permissions](#permissions), then `php artisan media:diagnose`, which checks every directory both users need.
- **`Call to undefined method …Controller::someMethod()` after a deploy.** The deploy died before its cache-building steps, so `bootstrap/cache` still holds routes and config compiled from the *previous* release while the code on disk is the new one. The stale route cache points at controller methods that release renamed or removed. Fix with `php artisan optimize:clear`, then rebuild: `php artisan config:cache && php artisan route:cache && php artisan view:cache`. The deploy workflow now clears these automatically when a run fails.
- **An OAuth callback, password-reset link or verification redirect points at `http://localhost:3000` in production.** `php artisan config:cache` stops Laravel from loading `.env`, so `env()` returns null everywhere outside `config/*.php` and falls back to its default. Every browser-facing URL the API builds comes from `config('app.frontend_url')` for this reason — set `FRONTEND_URL` in `apps/api/.env` and re-run `php artisan config:cache`. A test (`ConfigCachingTest`) fails the build if `env()` is reintroduced into runtime code.
- **A recording upload comes back `422` and the file is fine.** Two different server problems produce this, and the response body now tells them apart. *"The recording did not finish uploading…"* means PHP dropped the file before Laravel saw it: `upload_max_filesize` is smaller than the recording (`post_max_size` too large to trigger a `413`, so it looks like a validation failure). The message names both current values — raise them per [Upload limits](#upload-limits), reload PHP-FPM, and check `client_max_body_size` in Nginx while you are there. *"The media toolchain is unavailable on this server"* with a `503` means ffprobe is missing or `MEDIA_FFPROBE_PATH` is wrong; without it every upload looks corrupt. `php artisan media:diagnose` checks both, including the PHP limits against `MEDIA_MAX_AUDIO_MB`, so run it after any deploy.
- **Media reports as missing but `sudo` can see it.** A permission problem, not a lost file: read permission on the file is useless without **execute on every directory above it**, and PHP cannot tell the two apart — `is_file()` returns false either way. `php artisan render:preflight <project-uuid>` now distinguishes them and names the directory that blocks:

  ```
  ✖ Audio              cannot be reached — a parent directory is closed
    database path ........ content/<uuid>/source/audio.mp3
    absolute path ........ /var/www/keje/apps/api/storage/app/private/content/<uuid>/source/audio.mp3
    exists ............... unknown — parent not traversable
    owner ................ www-data
    group ................ www-data
    mode ................. 0700
    Cannot traverse: /var/www/keje/apps/api/storage/app/private/content/<uuid>
  ```

  From a shell, as the user that is failing — not via `sudo`, which passes every check:

  ```bash
  whoami
  id                                    # is www-data in the group list?
  getent group www-data                 # is the deploy user in the member list?

  # Every component from / down, with the first closed door marked:
  namei -l /var/www/keje/apps/api/storage/app/private/content/<uuid>/source/audio.mp3

  stat -c '%A %a %U:%G %n' storage storage/app storage/app/private

  test -r <absolute-path> && echo readable || echo NOT readable
  sudo -u www-data test -r <absolute-path> && echo www-data ok || echo www-data blocked
  ```

  `id` reads the current session: after `usermod -aG www-data deploy` the group only appears in a **new login session**. See [Permissions](#permissions) for the fix and why a one-time `chmod` does not hold.
- **A render fails with "The source audio is missing from storage".** The database says the file is there and the disk disagrees. It is almost never the upload: check `php artisan render:preflight <project-uuid>`, which prints the absolute path it looked for so you can `ls` it. The usual causes are a deploy that replaced `storage/` (releases must share it — symlink `storage/app` to a persistent directory, never ship a fresh one per release), or a worker running from a different directory than PHP-FPM. Recovery is to re-upload the recording from the studio and render again; the render endpoint now refuses to queue a project whose recorded files are gone, so you get an immediate message instead of a job that fails minutes later.
- **A render is stuck and you want to know where.** Run `php artisan render:status` on the API server. It prints the media queue's depth and the recent attempts, and gives each one a verdict — waiting normally, nothing is consuming the queue, claimed by a worker that then died, encoding, or failed with its error. Add `--log` for the FFmpeg output of an attempt, `--project=<uuid>` to narrow it. It is read-only; it never re-queues or cancels anything.
- **A render stays queued at 0% forever.** Nothing is consuming the `media` queue. Renders are dispatched with `onQueue('media')`, so a worker started as a plain `php artisan queue:work` listens to `default` only and never touches them — no error, just a job that waits. The studio now says so after two minutes instead of showing a progress bar that implies work is happening, and `php artisan media:diagnose` reports the pending backlog. Start the worker with the queue named — `php artisan queue:work --queue=media,default --timeout=7200 --tries=2` — or check Supervisor is running it, per [Queue worker](#queue-worker). Nothing is lost while it waits: the queued attempt runs as soon as a worker appears. `php artisan queue:failed` lists attempts that ran and failed, which is a different problem.
- **Herd link fails.** You're on Linux/Windows — Herd integration is macOS only. Answer "no" to the Herd prompt and use `php artisan serve`.
- **Requests fail with `(blocked:csp)` in the browser console.** The frontend's Content-Security-Policy must name the Laravel API origin, because on Vercel the API is a *different* origin. The policy in `apps/web/next.config.ts` derives that origin from `NEXT_PUBLIC_API_BASE_URL` at **build time** and adds it to `connect-src`, `img-src` and `media-src`. So if the variable is missing, wrong, or was changed without redeploying, the browser blocks login, artwork and video playback before any request reaches Laravel. Set `NEXT_PUBLIC_API_BASE_URL` in the Vercel project and **redeploy** — changing it alone is not enough. Confirm with `curl -sI https://<your-app> | grep -i content-security-policy`; `connect-src` should list your API origin, not just `'self'`.

---

## Kajian Tematik video template

`kajian-tematik` is the first production template. The background artwork you upload should be **clean** — no titles, no speaker name, no branding burnt in. Keje overlays all eight elements automatically from the database, so the same artwork can be reused across a whole series.

| # | Element | Source | Behavior |
|---|---------|--------|----------|
| 1 | Topic | `ContentTopic` | Top-left; conceptually maps to a YouTube playlist |
| 2 | Topic sequence | `ContentProject` | Stored as an integer, rendered as `TEMA #N` |
| 3 | Speaker label | Template | Constant `USTADZ`, muted grey, never entered per project |
| 4 | Speaker name | `Speaker` | Bright white, uppercased for render only |
| 5 | Branding | Template asset | Constant `KAJIAN ● TEMATIK`, committed PNG |
| 6 | Primary title | `ContentProject` | Largest font, **exactly one row**, auto-shrinks to fit |
| 7 | Subtitle | `ContentProject` | Smaller, **maximum two rows**, balanced word wrap |
| 8 | Part | `ContentProject` | Stored as an integer, rendered as `~ PART-N ~`; omitted when null |

Elements #3 and #4 share a baseline despite their different sizes. #6 never wraps: it shrinks from its preferred size down to a floor, and if it still does not fit the render is **refused with a message** rather than cropping the text. #7 behaves the same way for two lines and never produces a third.

### Geometry and typography

All of it lives in one file — `apps/api/resources/media/templates/kajian-tematik/template.php`. Nothing is hardcoded in a service or a controller.

| Element | Position (1280×720) | Font | Size | Min size | Colour | Align | Max lines |
|---|---|---|---|---|---|---|---|
| #1 Topic | x 48, y 46, w 640 | sans_bold | 30 | 20 | `#FFFFFF` | left | 1 |
| #2 Topic sequence | x 48, y 88, w 640 | sans_bold | 24 | 18 | `#DCDCDC` | left | 1 |
| #3 Speaker label | centred group, baseline 232 | sans_bold | 22 | 16 | `#B5B5B5` | — | 1 |
| #4 Speaker name | centred group, baseline 232 | sans_bold | 32 | 20 | `#FFFFFF` | — | 1 |
| #5 Branding | x 1022, y 42, 210×76 | *(image asset)* | — | — | — | — | — |
| #6 Primary title | x 48, y 286, w 1184 | sans_bold | 72 | 38 | `#FFFFFF` | center | 1 |
| #7 Subtitle | x 100, y 380, w 1080 | sans_bold | 38 | 24 | `#F0F0F0` | center | 2 |
| #8 Part | x 48, y 486, w 1184 | sans_bold | 28 | 20 | `#FFFFFF` | center | 1 |
| Waveform | x 320, y 540, 640×150 | — | — | — | red, `cline` | — | — |

Safe margin is 48 px on every edge. Everything below y 540 is the reserved waveform zone, so the wave can never collide with the part marker or the subtitle.

Adding another template means adding another directory under `resources/media/templates/` — the renderer, the models and the API do not change.

---

## The content workflow

1. Create or select a **Topic** (`Riyadhush Shalihin`).
2. Set the **topic sequence** (`11` → renders as `TEMA #11`). Keje suggests the next free number.
3. Create or select a **Speaker** (`Syafiq Riza Basalamah`).
4. Upload the **lecture audio** — the original recording, straight off the recorder.
5. Upload the **clean background artwork**.
6. Enter the **primary title** (`Keutamaan Lapar, Hidup`).
7. Enter the **supporting subtitle**.
8. Enter the optional **video part** (`3` → `~ PART-3 ~`).
9. Enter the **YouTube metadata** — separate from the on-screen title.
10. Review the **Kajian Tematik preview**.
11. **Render**.
12. Monitor progress.
13. Preview and download the MP4.
14. Back up to Google Drive.
15. Upload to YouTube, immediately or scheduled.

Steps 14 and 15 are independent of each other and of the render: a failed Drive backup never invalidates a good render, and rendering never publishes anything on its own.

---

## Media pipeline

**Audio in** — `.mp3`, `.mpeg`, `.mpg`, `.m4a`, `.wav`, `.aac`. The extension and the browser's MIME type are treated as claims only: **ffprobe** decides whether a file is usable, and reports codec, duration, sample rate, channels and bitrate. A file with no audio stream is rejected and deleted. An MPEG carrying both video and audio is accepted — the first audio stream is used.

**Background in** — `.jpg`, `.jpeg`, `.png`, `.webp`, verified as a real image and measured. Scaled to **cover** 1280×720 and centre-cropped, preserving aspect ratio. Images are never stretched, and the uploaded file is never modified — the readability gradient exists only during the render.

**Video out**

| | |
|---|---|
| Resolution | 1280 × 720 (16:9) |
| Container | MP4, `+faststart` |
| Video | H.264, High profile, `yuv420p`, 30 fps, CRF 20, preset medium |
| Audio | AAC, 48 kHz, 256 kbps |
| Duration | the source audio's duration |

Sprint 1 normalises the container, codec and sample rate only. **EBU R128 loudness normalisation is wired in but OFF by default** — lecture audio should not be materially altered without intent. Enable it per project via `render_settings.loudnorm`, or globally with `MEDIA_LOUDNORM_ENABLED`.

---

## Rendering architecture

```
Browser
   ↓
Next.js / Vercel          UI · forms · uploads · previews · polling
   ↓  HTTPS
Laravel / VPS             auth · validation · private media · API
   ↓
Laravel queue (database)  the "media" queue
   ↓
FFmpeg / ffprobe          on the VPS
   ↓
MP4 on the private disk
   ├── Google Drive
   └── YouTube
```

**FFmpeg never runs on Vercel, and never inside an HTTP request.** Rendering is always dispatched to `RenderContentProjectJob` on the `media` queue; the render endpoint returns `202` immediately and the studio polls for progress.

### The FFmpeg graph

```
[0:v] scale=1280:720:force_original_aspect_ratio=increase, crop, setsar, format=rgba  ← background, cover+crop
[2:v] scale=1280:720                                                                  ← readability gradient
      overlay
[3:v] scale=210:76 → overlay=1022:42                                                  ← #5 branding
      drawtext ×8 (textfile=, expansion=none)                                         ← #1 #2 #3 #4 #6 #7×2 #8
[1:a] aresample=48000 [, loudnorm] , asplit=2 → [aout] + [awave]
[awave] showwaves=s=640x150:mode=cline:colors=red:rate=30
      overlay=320:540:shortest=1
      format=yuv420p
```

How this differs from the original shell script:

- **Audio is decoded and re-encoded**, never stream-copied, so any supported input yields a uniform AAC 48 kHz track. The Audacity pre-pass is no longer needed.
- **Text comes from files**, not from the command. Each run is written to its own `.txt` and drawn with `textfile=` plus `expansion=none`, so apostrophes, colons, ampersands, Unicode and Indonesian characters are safe, and a title containing `%{pts}` stays literal.
- **Text is measured and fitted** before rendering (GD + FreeType), so titles shrink to fit and impossible text is rejected up front instead of being silently cropped.
- **Positioning is by baseline** (`y=<baseline>-ascent`), so differently-sized runs share a line.
- **Duration is bounded explicitly** with `-t` plus `overlay=…:shortest=1`. `-shortest` alone does not terminate an encode whose video branch is an infinite `-loop 1` still — it will run forever.
- **Progress is machine-readable** via `-progress pipe:1 -nostats`, throttled before it reaches the database.
- **Nothing is interpolated into a shell.** Everything runs through Symfony Process with an argument array, and the filter graph is assembled only from template config.

---

## Preview parity

The browser preview is not a separate implementation of the layout. `GET /api/v1/content-projects/{uuid}/preview` returns the **resolved layout** — the very structure the renderer draws from — and the preview positions elements from it inside a fixed 1280×720 canvas scaled with a CSS transform. The readability gradient is rebuilt from the same stops that generate `overlay.png`.

The preview cannot drift from the render, because there is only one set of coordinates. It is still an approximation: the browser has its own fonts and text shaping. Close enough to approve a composition before spending minutes rendering.

That same endpoint is the pre-render check — text that cannot be laid out comes back as a `422` the studio shows immediately.

---

## Content Studio routes

| Route | Purpose |
|---|---|
| `/dashboard` | Drafts / Rendering / Ready to upload / Scheduled / Published, plus recent projects |
| `/studio` | The content table: search, filters, sortable columns, pagination |
| `/studio/new` | Create a project: topic, sequence, speaker |
| `/studio/[uuid]` | Media upload, title fields, YouTube metadata, preview, render, playback, publish |
| `/studio/topics` | Topics and playlist links |
| `/studio/topics/[uuid]` | One topic and its videos, in sequence order |
| `/settings/integrations` | YouTube and Google Drive connections, and channel verification |

## API endpoints

All under `/api/v1`, all `auth:sanctum` unless noted, all owner-scoped (a foreign resource returns `404`, never `403`).

```
GET    /topics                              POST   /topics
GET    /topics/{uuid}                       PATCH  /topics/{uuid}
DELETE /topics/{uuid}

GET    /speakers                            POST   /speakers
GET    /speakers/{uuid}                     PATCH  /speakers/{uuid}

GET    /content-projects                    POST   /content-projects
GET    /content-projects/{uuid}             PATCH  /content-projects/{uuid}
DELETE /content-projects/{uuid}

GET    /content-projects/{uuid}/preview        resolved template layout
POST   /content-projects/{uuid}/audio          ffprobe-validated upload
POST   /content-projects/{uuid}/background     image-validated upload
GET    /content-projects/{uuid}/background     artwork, for the preview

POST   /content-projects/{uuid}/render         202, queues the render
GET    /content-projects/stats                 account-wide counts for the dashboard
GET    /content-projects/{uuid}/render-status  status + progress
GET    /content-projects/{uuid}/video          stream the MP4
GET    /content-projects/{uuid}/download       download the MP4
GET    /content-projects/{uuid}/media-links    short-lived signed playback URLs

POST   /content-projects/{uuid}/drive          202, queues the Drive backup
POST   /content-projects/{uuid}/youtube        202, queues the YouTube upload
POST   /content-projects/{uuid}/youtube/playlist  retry playlist membership only

PATCH  /content-projects/{uuid}/youtube/metadata   edit the existing video in place (videos.update)
GET    /content-projects/{uuid}/youtube/replacement       current correction + eligibility
POST   /content-projects/{uuid}/youtube/replacement       202, starts a safe replacement
POST   /content-projects/{uuid}/youtube/replacement/retry  202, resumes where it stopped
POST   /content-projects/{uuid}/youtube/replacement/cancel 202, undoes it while that is possible
GET    /content-projects/{uuid}/youtube/publications      every video this project has had
POST   /content-projects/{uuid}/finalize        end the correction window, free the files

GET    /integrations/google                    status of both connections
POST   /integrations/youtube/redirect          YouTube consent URL
DELETE /integrations/youtube                   disconnect YouTube only
GET    /integrations/youtube/callback          OAuth callback (state-verified, unauthenticated)
POST   /integrations/drive/redirect            Drive consent URL
DELETE /integrations/drive                     disconnect Drive only
GET    /integrations/drive/callback            OAuth callback (state-verified, unauthenticated)

GET    /integrations/youtube/channel           the connected channel
GET    /integrations/youtube/playlists         destination playlists (paginated)
GET    /integrations/youtube/categories        assignable video categories
GET    /integrations/youtube/languages         i18n languages
GET    /integrations/youtube/recent-uploads    the channel's latest uploads
POST   /integrations/youtube/refresh           drop the cached YouTube catalog
GET    /integrations/drive/about               account, quota, backup folder
GET    /integrations/drive/backups             files Keje put in Drive (paginated)
POST   /integrations/drive/refresh             drop the cached Drive catalog

GET    /content-projects/{uuid}/stream         signed video delivery (unauthenticated)
```

The last three are necessarily unauthenticated. The OAuth callback is reached by a Google redirect and is bound to its user by a single-use `state`. The stream route is reached by a `<video>` element, which cannot attach a bearer token; the short-lived signature issued by `/media-links` is its authorization.

---

## The Studio table

`/studio` is a server-driven table. Filtering, searching, sorting and paging all
happen in SQL, which matters for a reason beyond speed: a browser can only sort
the rows it was given, so sorting a downloaded list silently means "sort this
page", and the answer changes with how many rows happened to arrive.

**Default order: Updated, newest first** — and the table says so, above the
headers, rather than leaving it to be inferred from one small arrow.

### Two kinds of state

| | Lives in | Because |
|---|---|---|
| Search, filters, sort, page | the **URL** | a narrowed view should survive a refresh, come back with the back button, and mean the same thing when pasted to somebody else |
| Column order, widths, hidden columns, density | **browser storage** | it is personal to one browser, and nobody wants to send a colleague their column widths |

So `/studio?q=lapar&youtube_status=published&page=2` is a link you can bookmark
or share, and it will show the same rows to whoever opens it — arranged
according to *their* column preferences.

### Sorting

Click a header to sort ascending, again for descending, and a third time to
return to the default. That third state matters: without it there is no way
back to "newest first" once a column has been clicked.

Sortable: working title, topic, TEMA, speaker, audio duration, render status,
Drive status, YouTube status, created, updated. Topic and speaker sort by the
related name, with unattributed projects last in both directions — nobody scans
an A–Z list for the rows missing the thing they sorted by.

### Search and filters

Search covers the working title, the on-screen titles, the topic and speaker
names, and the YouTube video id. It is debounced, and it only ever reads Keje's
own records — typing in the search box never calls Google.

Filters: topic, speaker (including **No speaker**), render, Drive and YouTube
status. Two are worth calling out because they are not plain column matches:

- **Outdated** finds videos whose frames no longer match their project. That is
  a comparison against a render fingerprint, so it is persisted as a column
  rather than computed per row — which also means renaming a speaker or a topic
  marks their rendered projects outdated, because those names are drawn on the
  video.
- **Published** includes a video uploaded as scheduled that YouTube has since
  released. Our pipeline status froze when the upload returned; the filter means
  what is true now.

Active filters appear as chips under the toolbar, each removable on its own.
**Clear filters** and **Reset table layout** are deliberately separate: one
changes which rows you see, the other changes how they are drawn, and a single
button doing both surprises whoever wanted the other.

### Columns

Drag the handle in a header to reorder. Drag a column's right edge to resize.
Hide what you do not need from **Columns**, and switch between comfortable and
compact from **Density**. All of it is remembered per browser and restored on
the next visit; **Reset table layout** puts it back.

The working title and the row's Open action are pinned and cannot be hidden — a
row scrolled away from its own name is a row of statuses belonging to nothing.

### A note on "Updated"

Background YouTube status polling deliberately does **not** touch a project's
`updated_at`. That column means somebody changed the project, and it is what the
table sorts by out of the box; letting a status poll move it made rows climb the
list on their own and migrate between pages under a reader who was doing
nothing.


## Choosing a thumbnail

Once a project has rendered, the publishing card offers frames from the video
itself — a quarter, half and three-quarters of the way in, plus any timestamp
you type. Never the first or last frame: a lecture opens and closes on
near-static artwork, so a thumbnail of the title card is what the video already
looks like in a list.

Extraction is cheap. `-ss` before `-i` makes FFmpeg seek by keyframe instead of
decoding up to the mark, and `-frames:v 1` stops immediately, so three
candidates are three quick seeks rather than three passes over the video.

**No OAuth change and no reconnect.** `thumbnails.set` is covered by the
`youtube.upload` scope every connection already grants.

Sending the thumbnail is a separate action from uploading the video, which is
what makes **Retry thumbnail** safe: it calls `thumbnails.set` and has no path
back to `videos.insert`, so a retry can never publish a second copy. A channel
that is not verified for custom thumbnails gets that explanation rather than a
raw Google error, and the failure is reported on its own:

```
Render                 Succeeded
Drive                  Succeeded
YouTube video          Succeeded
YouTube thumbnail      Failed  ← the video is published and stays published
```

Thumbnails survive pruning. They are a few dozen kilobytes and are still needed
for a retry after the MP4 has gone to Drive.

## Connected Google pages

Two top-level pages browse the connected accounts:

| Route | Shows |
|---|---|
| `/youtube` | channel profile, playlists, recent uploads, granted capabilities |
| `/drive` | account, storage quota, backup folder, the files Keje created |

**Settings → Integrations manages the connection** — connect, reconnect,
disconnect, permissions — and links to those pages rather than embedding the
catalogs. A catalog in Settings buried the controls the page exists for.

Drive stays on `drive.file`. The page shows what Keje created and says so;
widening the scope to browse everything would trade the point of the narrow
grant for a file picker nobody asked for.

`/studio/topics` redirects to `/youtube`. A playlist and a local topic were
always the same grouping described twice, and existing bookmarks keep working.

### Why YouTube images were blank

The Content-Security-Policy allowed images from `'self'`, `data:`, `blob:` and
the API origin only. Thumbnails and channel avatars come from Google's CDN, so
every one of them was blocked before it left the browser — silently, with the
pages simply rendering holes.

Fixed with an explicit host allow-list in `lib/security-headers.ts`:

```
i.ytimg.com  i9.ytimg.com  yt3.ggpht.com  yt3.googleusercontent.com  lh3.googleusercontent.com
```

Not `img-src https:` and not a wildcard — widening the directive to fix a
thumbnail would hand every origin on the internet a place to render pixels in
the page. A server-side image proxy was the alternative and buys nothing here:
these are public CDN links to the user's own channel, so proxying adds
bandwidth, a cache and an SSRF surface in exchange for hiding a request Google
is already the other end of.

## What YouTube says now

Our upload pipeline status and the video's current state on YouTube are
separate fields, because they answer different questions. A scheduled video
publishes itself and people change privacy from the YouTube app; neither
reaches Keje unless it asks.

```
YouTube status
Scheduled   31 Aug 2026 · 19:00      →   Published   31 Aug 2026 · 19:00
```

`videos.list` costs one quota unit and takes fifty ids, so `search.list` (a
hundred units) is never needed — the ids are known. The studio list answers
from stored state and queues a bounded background refresh for whatever is
oldest; fifty projects never means fifty synchronous calls. A scheduled upload
also dispatches one delayed read just past its publish time, which needs no
scheduler to install — the persistent worker already drains that queue.

Tune with `YOUTUBE_REMOTE_SYNC_TTL_MINUTES` (default 30) and
`YOUTUBE_REMOTE_SYNC_BATCH` (default 10). **Refresh from YouTube** on the
project page forces an immediate check.

The sync is read-only in both directions. It never sets privacy and never
re-uploads: if a public video was made private, that is the truth to report,
not a difference to correct.

## Correcting a video that is already on YouTube

Two mistakes, two different repairs, and confusing them is expensive.

**YouTube cannot replace the video file behind an existing video ID.** There is no API for it and no button for it in YouTube Studio. Everything below follows from that one limitation.

### Metadata-only mistakes

Title, description, tags, category, language, privacy, schedule, thumbnail, playlist. YouTube edits every one of these in place.

> Studio → project → edit the YouTube fields → **Update existing YouTube video**

The video keeps its ID, its URL, its view count, its likes and every comment. Nothing is re-uploaded — the endpoint behind that button can reach `videos.update` and has no path to `videos.insert` at all.

One subtlety worth knowing about, because getting it wrong is destructive in a quiet way: `videos.update` does not patch. Every part named in the request replaces what is on the video, so a snippet carrying only a description would blank the title and the category. Keje reads the video first and sends a complete resource built from the current remote values with your changes layered over them.

### Rendered-content mistakes

Wrong speaker, wrong TEMA number, wrong topic, wrong on-screen title or subtitle, wrong part number, wrong background, wrong audio, wrong cuts — anything encoded into the MP4.

> Studio → project → correct it → **render again** → **Replace YouTube video**

The Studio says *"The current render differs from the video on YouTube"* when this applies. It knows because each publication records the render fingerprint it came from; a timestamp could not answer this, since re-saving a project moves `updated_at` without changing a single frame.

**The replacement gets a new YouTube URL.** Views, likes, comments and shares on the old video do not transfer. That is YouTube's constraint, not a choice Keje makes, and it is why replacement is a confirmed action rather than a button.

### Why Keje does not delete first

The obvious implementation is delete the old video, then upload the corrected one. It has a failure mode that destroys a lecture: if the upload then fails, there is nothing left and nothing brings the old video back.

So the order is inverted:

```
upload the correction, privately
        ↓   the old video is still up, still public, untouched
confirm it exists on YouTube
        ↓
delete the old video  (or set it private)
        ↓   only now does the new one become authoritative
apply playlist, thumbnail, final visibility
```

The replacement stays **private** until the old video is disposed of, so two visible copies of the same lecture never coexist.

Every arrow is a point where a worker can die, and every one of them is safe:

| If this fails | What you have |
|---|---|
| the upload | Your published video, untouched. Nothing was deleted. Retry. |
| deleting the old video | The correction, uploaded and private. Your published video is still live and unchanged. Retry the deletion only. |
| the final settings | The corrected video is live and authoritative but still private. Retry the finalisation only. |

### Retries never re-upload

A retry resumes from what actually happened — does a new video id exist, has the old one been disposed of — rather than from a step counter. Re-running the upload is the one unrecoverable mistake available here, because it publishes a second real copy to a real channel, so the code path is incapable of it once a video id has been stored.

Only one replacement can run per project, enforced by a unique column in the database rather than by a check in the application, so two servers cannot both start one. A **failed** replacement keeps that lock: it still owns a private video on the channel, and a second attempt on top of it would leave a third copy.

### Cancelling

Before the old video has been disposed of, cancelling deletes the temporary private copy and puts everything back. After it, there is nothing to go back to — the old video is gone — so the replacement can only be finished, not undone. The UI says which of the two you are in.

### Keeping the old video

The confirmation offers **Keep old video, set to private** as an alternative to deleting it. The comments and view count survive, invisible, and can be restored by hand from YouTube Studio. Useful when an old public video has discussion worth preserving.

### Publication history

Replacing changes the public URL, so every video a project has had stays on the record with what happened to it — replaced, deleted, or kept private. A link someone shared last week is the reason: the old ID is the only trace that it ever existed.

### Permissions

Editing and deleting videos need `youtube.force-ssl`, which uploading (`youtube.upload`) does not cover. A connection made before Keje requested that scope can publish perfectly well and cannot correct; the Studio says so and offers a reconnect rather than failing at the API. Drive permissions are untouched by any of this, and the two OAuth flows stay separate.

## Editing a project after it exists

A Content Project stays editable for its whole life. The project page carries a
**Project properties** card — working title, topic, TEMA, speaker — using the
same selectors as New Content. Nothing is locked in at creation, so a project
created without a speaker (the easy mistake, since the field is optional) can be
given one afterwards rather than recreated.

### Renders go stale rather than silently lying

Editing creates a correctness problem: a finished MP4 was produced from inputs
that may since have changed. `RenderInputFingerprint` hashes everything that
reaches a frame — the drawn text, template, render settings, source media
identity and the cut list — and the render job records the hash it encoded
from. When the two differ the studio says **Outdated** rather than *Rendered*:

```
Render
Rendered ✓

This render is out of date
The project changed after it was made, so the video below no longer matches.
```

Deliberately excluded: the **working title** (a label for humans, never drawn)
and **publishing metadata** (YouTube's business, not FFmpeg's). Renaming a
project must not throw away a two-hour encode.

Nothing is deleted. A Drive backup or an uploaded YouTube video represents an
earlier revision and stays traceable; only the local output is marked as no
longer current.

## Removing sections of a recording

The **Audio editing** card on the project page removes unwanted spans before
rendering. `Cut 18 → 23` removes those five seconds — the result is `0–18`
followed by `23–end`. It is not a trim down to that range.

```
Original          01:27:12
Removed           00:00:06.50
Rendered length   01:27:05.50
```

**The uploaded MP3 is never rewritten.** The cut list is stored separately and
applied at encode time with `atrim` / `asetpts` / `concat`, so a mis-typed
timestamp costs a re-render rather than the lecture. The cuts run before the
loudness and waveform chain, so the drawn waveform matches what is heard, and
both `-t` and the progress fraction follow the edited duration — a job measured
against the original recording would report 92% and stop.

Overlapping ranges are refused rather than merged: silently widening a cut
removes audio nobody chose to remove. Touching ranges are merged, ranges are
sorted, and removing the whole recording is refused.

The request carries only validated numbers. `AudioEditService` builds the filter
graph; no filter, expression or option ever comes from a client.

Playback uses a short-lived signed link from `/media-links`, the same model as
the rendered video — an `<audio>` element cannot attach a bearer token, and the
recording stays on the private disk.

## After a render

Before starting a render, choose what happens when it succeeds:

```
After render
[x] Back up to Google Drive
[x] Upload to YouTube
```

The choices are **snapshotted onto the render attempt**, not the project: the
job may sit on the queue, and editing the project afterwards must not change
what happens when it finishes. A destination that is not connected is dropped
when the render is queued rather than discovered by a worker twenty minutes
later.

Both run as independent jobs. A Drive or YouTube failure never turns a good
render into a failed one, and a project that already holds a `youtube_video_id`
is never uploaded again however often it is re-rendered.

Pruning is unaffected and safe in either completion order: Drive first keeps the
MP4 because YouTube still wants it; YouTube first prunes nothing because there
is no backup to fall back on.

## Topics and YouTube playlists

A `ContentTopic` is the lecture series. It exists so you type "Riyadhush Shalihin" once, and it carries an optional `youtube_playlist_id`:

```
Keje Topic                YouTube Playlist
Riyadhush Shalihin   →    PLxxxxxxxxxxxx
```

**YouTube playlists are the canonical topic.** A local topic and a playlist
were always the same grouping described twice, maintained separately and mapped
by hand. Choosing a playlist as a project's topic now resolves (or creates) its
local shadow on the server, so there is one choice rather than two.

The `ContentTopic` table stays, and is not redundant: it carries what YouTube
has no concept of — the name drawn on the Kajian Tematik frame, the TEMA
sequence, and the link to every historical project. Dropping it would break
rendering and orphan finished work.

Identity is the **playlist id**, never the name. Two playlists that happen to
share a title are not the same topic, and merging them on a string match would
silently reassign someone's projects. One exception, and only one: an unmapped
legacy topic whose name matches exactly is adopted rather than duplicated
beside it, which keeps its history and its projects.

Legacy topics with no playlist stay selectable under **Not linked to YouTube**,
so a historical project can still be re-attached to its own topic and mapped to
a playlist when convenient. Nothing is deleted.

`/studio/topics` redirects to `/youtube`; the route was in the main navigation
until this sprint, so bookmarks keep working. Creating playlists from Keje is
still deliberately not implemented — you pick from the ones the channel has.

Two levels decide where a video lands, project first:

```
project.youtube_metadata.playlist_id     override, this video only
    └── falls back to ──▶ topic.youtube_playlist_id     the series default
```

Setting an override on one project never rewrites the topic. Going the other way is opt-in: the New Content form offers a checkbox to also save the chosen playlist as the topic's default.

When a video uploads successfully Keje adds it to the resolved playlist. **A playlist failure never fails the upload** — the video already exists on YouTube, and retrying an upload would publish a second copy. Instead the failure is recorded on the project, shown on its page, and retried with `POST /content-projects/{uuid}/youtube/playlist`, which only ever calls `playlistItems.insert`. Adding a video that is already in the playlist counts as success.

The channel's own uploads playlist (the `UU…` id YouTube maintains automatically) is filtered out of every chooser: `playlistItems.insert` against it always fails, so offering it would guarantee the error.

---

## Connected Google data

Once a connection exists, Keje reads what those APIs can tell it and uses it instead of asking you to type ids.

| Where | What it shows |
|---|---|
| Settings → Integrations, YouTube | Granted capabilities, channel avatar/name/handle, subscriber, video and view counts, playlists, recent uploads |
| Settings → Integrations, Drive | Google account, storage used vs. limit, the Keje backup folder, recent backups |
| New Content | The destination channel, a playlist chooser, a category chooser, a language chooser |
| Project detail | The resolved destination — playlist, category and privacy by name — before the upload button |
| Topics | A playlist chooser instead of a raw `PLxxxx` field |

Three rules hold everywhere:

- **Nothing reaches the browser but data.** Every read is `Browser → Laravel → Google`. Access and refresh tokens stay on the API host and are never serialized into a response.
- **A stored id is never discarded because Google could not be reached.** If the catalog fails to load, is disconnected, or no longer lists a playlist, the id is shown as-is and stays selected — saving the form cannot quietly erase a destination.
- **Quota is spent carefully.** `search.list` costs 100 units of a 10,000/day default and is never used. `channels.list`, `playlists.list`, `playlistItems.list` and `videoCategories.list` cost 1 unit each and are authoritative for what they return.

Responses are cached server-side per user and service, so opening the page repeatedly does not re-bill the quota:

| Cached | For |
|---|---|
| Channel profile, Drive account, backup folder | 30 min |
| Playlists, recent uploads, recent backups | 10 min |
| Video categories, languages | 24 h |

**Refresh from YouTube** / **Refresh from Drive** on the integrations page drops that cache and re-reads. Neither ever re-runs OAuth consent.

Categories and languages are localized by `YOUTUBE_DEFAULT_REGION_CODE` (default `ID`) and `YOUTUBE_METADATA_LANGUAGE` (default `id`). Only categories Google marks `assignable` are offered — the rest exist but are rejected at upload time.

## Speakers

A `Speaker` is reusable across projects. The stored name keeps its natural casing — `Syafiq Riza Basalamah`. Uppercasing to `SYAFIQ RIZA BASALAMAH` is a Kajian Tematik rendering decision and never rewrites the record. An optional `display_name` overrides what is drawn without changing the canonical name.

## Visual title vs YouTube title

These are separate fields and are never conflated:

```
On screen   KEUTAMAAN LAPAR, HIDUP
            SEDERHANA DAN MERASA CUKUP
            SERTA MENGEKANG HAWA NAFSU

YouTube     Keutamaan Lapar, Hidup Sederhana … | Riyadhush Shalihin #11 | Part 3
```

The studio can prefill the YouTube title from the visual fields, but it stays independently editable.

---

## Google setup

Google Drive and YouTube use **server-side OAuth**. The client secrets and the refresh tokens live on the Laravel host and are never sent to the browser, never stored in `localStorage`, and never exposed through the API.

**Keje uses two separate OAuth clients — Keje YouTube and Keje Drive — and authorizes them independently.** Google refuses any consent request that mixes the YouTube scopes with `drive.file`:

```
Error 400: invalid_request
This request contains scopes that cannot be requested together:
[youtube.readonly, youtube.upload, drive.file]
```

So there is no single "Connect Google" action. Each product is connected on its own, and Keje asks each for only the permissions that feature needs. Connecting one does not connect the other, and disconnecting one leaves the other working.

1. Configure the **Google Auth Platform** branding and audience for your project (External, with your Google account as a test user).
2. Enable the **YouTube Data API v3**.
3. Enable the **Google Drive API**.
4. Create an OAuth client of type **Web application** named e.g. *Keje YouTube*.
5. Register its redirect URI — it points at the **API**, not the frontend:
   `https://api.yourapp.com/api/v1/integrations/youtube/callback`
6. Create a second OAuth client of type **Web application** named e.g. *Keje Drive*.
7. Register its redirect URI:
   `https://api.yourapp.com/api/v1/integrations/drive/callback`
8. Set both credential sets in `apps/api/.env`:
   - `GOOGLE_YOUTUBE_CLIENT_ID`, `GOOGLE_YOUTUBE_CLIENT_SECRET`, `GOOGLE_YOUTUBE_REDIRECT_URI`
   - `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REDIRECT_URI`

   Each redirect URI must match the one registered on **its own** client, exactly.
9. Open **Settings → Integrations** in Keje and click **Connect YouTube**.
10. Verify the channel shown matches `YOUTUBE_EXPECTED_CHANNEL_ID`. A mismatch blocks YouTube uploads — but not Drive backup.
11. Click **Connect Google Drive**.

Do **not** download the credentials JSON into the repository. Only the environment variables are needed.

Scopes requested, deliberately minimal and never combined:

| OAuth client | Scope | Why |
|---|---|---|
| Keje YouTube | `youtube.upload` | Upload only |
| Keje YouTube | `youtube.readonly` | Read back the channel, its playlists and its categories |
| Keje YouTube | `youtube.force-ssl` | Add an uploaded video to a playlist |
| Keje Drive | `drive.file` | Only files Keje created — not your whole Drive |

`drive.file` is deliberately **not** widened to `drive`, `drive.readonly` or `drive.metadata.readonly`. Keje has no reason to browse the rest of your Drive, so it cannot: what the integrations page lists is only ever what Keje itself uploaded.

Neither flow enables `include_granted_scopes`. Incremental authorization lets Google fold scopes already granted to the project back into a request, which would silently recreate the forbidden combination. Both flows do keep `access_type=offline` and `prompt=consent`, because the queue workers need refresh tokens.

> **Upgrading from the single combined connection.** Existing connections are removed by the `split_google_connections_per_service` migration: their grant covered both products, which the two new OAuth clients do not hold. Reconnect YouTube and Drive separately after deploying. The integrations page says so too.

> **Adding playlist permission to an existing connection.** `youtube.force-ssl` was added after the split. A connection made before it keeps uploading and keeps reading the channel — only *adding a video to a playlist* needs the new scope. The integrations page detects this from the scopes Google actually granted and shows **Reconnect to enable playlist assignment**; nothing else about the connection changes, and Drive is untouched. Uploads that ran without it record a playlist error the project page can retry once you reconnect.

> **YouTube API development limitation.** Google restricts uploads from unverified YouTube Data API projects to **private** visibility until an API compliance audit is completed. During development, expect uploaded videos to remain private regardless of the privacy you select. This is Google policy, not a Keje bug, and must not be worked around.

### Scheduling

Choose a publish time and Keje uploads the video as `private` with `publishAt` set. **YouTube performs the publication itself** — there is no cron job flipping videos public. Times are entered in your local timezone and converted to RFC 3339 UTC on the way out.

---

## Production deployment (VPS)

The API host must have FFmpeg, fonts, a real queue driver and a worker.

```bash
# Dependencies
sudo apt-get install -y ffmpeg fonts-dejavu-core
ffmpeg -version
ffprobe -version
```

After every pull:

```bash
cd apps/api
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan media:diagnose      # verifies the whole media environment
sudo supervisorctl restart keje-worker:*
```

`php artisan media:diagnose` checks the CLI identity and its group membership, the FFmpeg and FFprobe binaries and versions, both font files, the template definitions and their assets, ownership, mode, traversability and setgid on every shared directory, free disk space, PHP's upload limits, the queue driver and its backlog, and the Google configuration. Run it **as the deploy user** — as `root` every permission check passes and proves nothing, which it will tell you. It exits non-zero when something critical is missing, so it can gate a deploy.

For a render that is already stuck rather than an environment that is misconfigured, two commands answer it:

```bash
php artisan render:status                    # queue depth + a verdict per recent attempt
php artisan render:preflight <project-uuid>  # every prerequisite for one project
```

`render:preflight` walks the same checks the render job does — title, both source files (existence, readability and what ffprobe makes of them), the template layout, the FFmpeg binaries, storage writability and the queue — and prints absolute paths, so a file the database expects and the disk does not have can be chased directly. Both are read-only.

```
✔ FFmpeg                       ffmpeg version 6.1.1
✔ FFprobe                      ffprobe version 6.1.1
✔ Font (sans)                  /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
✔ Font (sans_bold)             /usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf
✔ Templates                    kajian-tematik
✔ Private storage              …/storage/app/private/content
✔ Disk space                   21.6 GB free
✔ Queue                        database
```

### Queue worker

**The worker is a service that stays running, not a command you run to make a
render happen.** Nothing in Laravel starts it. If no worker is listening the
render is accepted, queued, and then waits — no error, no progress. Typing
`php artisan queue:work` by hand works only until the SSH session closes, and
never survives a reboot; that is a symptom of a missing service, not a workflow.

So: yes, keep it always on, supervised by the host so it restarts on crash and
comes back at boot. Two ready-to-install configs are committed — use **one**,
not both, or two workers will compete for the same jobs:

| | File | Prefer when |
|---|---|---|
| systemd | [`deploy/systemd/keje-worker.service`](deploy/systemd/keje-worker.service) | default — no extra package on a modern Ubuntu VPS |
| Supervisor | [`deploy/supervisor/keje-worker.conf`](deploy/supervisor/keje-worker.conf) | Supervisor already manages other processes here |

```bash
# Edit the paths inside the file first, then:
sudo cp deploy/systemd/keje-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now keje-worker     # `enable` is what survives a reboot
```

Verify it is actually running, as the right user:

```bash
systemctl status keje-worker
journalctl -u keje-worker -f                # live worker output
ps -eo user,group,pid,cmd | grep '[q]ueue:work'
php artisan render:status                   # queue depth, from the app's side
```

Rendering **must not** use the `sync` driver — that would run FFmpeg inside a
web request. `media:diagnose` fails if you try.

Three settings in those files are load-bearing, and all three fail silently:

- **`--queue=media,default`.** Renders are dispatched with `onQueue('media')`. A
  plain `queue:work` listens to `default` only and will sit idle forever next to
  a full media queue.
- **`User=www-data` / `umask=0002`.** These decide who owns every rendered MP4
  and whether the directories stay group-writable. A worker run as another user
  creates a second ownership class inside `storage/app/private` — see
  [Permissions](#permissions).
- **`TimeoutStopSec` / `stopwaitsecs` above `MEDIA_RENDER_TIMEOUT`.** On stop the
  worker finishes the job it is in; a shorter timeout `SIGKILL`s a render that
  may have been encoding for an hour.

One worker is right for a single-operator setup: renders are CPU-bound and
running two in parallel makes both slower.

`--max-jobs=50` makes the worker exit on purpose after fifty jobs, and the
supervisor immediately starts a replacement — PHP is not built to run for weeks.
`php artisan queue:restart` during a deploy uses the same mechanism: the running
worker finishes its job and exits, and the supervisor brings up a new one on the
new code. Both are why `Restart=always` is right and `on-failure` is not.

> **Not recommended, but honest about the alternative.** A cron entry
> (`* * * * * cd /var/www/keje/apps/api && php artisan queue:work --queue=media,default --stop-when-empty`)
> also drains the queue without a supervisor. It adds up to a minute of latency
> before a render starts, gives you no `journalctl` to read, and needs a lock to
> stop overlapping runs from double-processing. Use it only if you cannot run a
> service.

`user=www-data` is not optional bookkeeping — it decides who owns every rendered
MP4. A worker started by hand as another user (`php artisan queue:work` over
SSH as `deploy`, say) creates a second ownership class inside the same tree, and
those files are then unreachable from the web request that wants to serve them.
Confirm who is actually running it:

```bash
ps -eo user,group,pid,cmd | grep '[q]ueue:work'
sudo supervisorctl status keje-worker:*
```

### Upload limits

A lecture recording is large. All three must be at least `MEDIA_MAX_AUDIO_MB`:

```ini
; php.ini
upload_max_filesize = 512M
post_max_size = 512M
max_execution_time = 300
memory_limit = 256M
```

```nginx
# nginx server block
client_max_body_size 512M;
client_body_timeout 300s;
```

Keje does not modify OS configuration — set these yourself.

### Permissions

Three OS identities touch the same directories, and they are not the same user:

| Identity | Is | Does |
|---|---|---|
| PHP-FPM | `www-data` | writes uploaded audio and artwork |
| Queue worker (Supervisor) | `www-data` | runs FFmpeg, writes the rendered MP4 |
| Deploy / SSH | `deploy`, **in group `www-data`** | migrations, `config:cache`, `media:diagnose`, `render:preflight` |

They meet through the shared group `www-data`. Set `MEDIA_RUNTIME_GROUP` if
yours is named differently — it is only ever compared against, never applied:
**Keje never calls `chmod` or `chown` itself.**

#### The access each one needs

|  | `deploy` | PHP-FPM (`www-data`) | worker (`www-data`) |
|---|---|---|---|
| `storage/app/private` | R/X | R/W/X | R/W/X |
| source audio, background | R | R/W | R |
| rendered MP4 | R | R | R/W |
| `storage/logs` | R/W | R/W | R/W |
| `storage/framework/{views,cache,sessions}` | R/W | R/W | R/W |
| `bootstrap/cache` | R/W | R/W | R/W |

`deploy` needs only **read and traverse** on private media: it diagnoses, it
does not produce media. It needs **write** on the framework caches because
`config:cache` and `view:cache` run as `deploy` while PHP-FPM rewrites the same
files at runtime — that pair is why setgid matters.

`X` is not a detail. **Read permission on a file is useless without execute on
every directory above it**, and a directory that cannot be entered makes the
file inside it indistinguishable from one that does not exist.

#### Target modes

```
directories   2770   owner rwx, group rwx, setgid, world nothing
files         0660   owner rw,  group rw,  world nothing
```

Never world-readable: `storage/app/private` holds unpublished lecture audio.
Never `777`.

Setgid (the `2`) is what makes this survive. A new file takes the *creator's*
primary group by default, so a render written by `www-data` into a directory
owned by `deploy` would be unreachable the other way round. With setgid on the
directory, every child inherits the directory's group instead — and new
subdirectories inherit the setgid bit too, so it propagates without help.

#### Why a one-time chmod is not enough

Laravel's `local` disk previously specified no visibility, and Flysystem's
default is private: **every directory it created came out `0700`**, whatever the
parent looked like. Setgid propagated the group correctly and it still did not
help, because `0700` grants the group nothing:

```
storage/app/private          2770 www-data:www-data   <- correct, set by hand
  content/<uuid>             2700 www-data:www-data   <- created by PHP-FPM
    source/audio.mp3         0664 www-data:www-data
```

`deploy` is in `www-data` and still cannot enter `content/<uuid>`, so
`render:preflight` reports the recording as missing while `sudo` finds it
immediately. The next upload recreates the problem however many times you
re-run `chmod`.

`config/filesystems.php` now sets this explicitly:

```php
'visibility' => 'private',
'permissions' => [
    'file' => ['private' => 0660, 'public' => 0664],
    'dir'  => ['private' => 02770, 'public' => 02775],
],
```

`visibility` matters as much as `permissions`: without it Flysystem never
chmods a written file at all, leaving the mode to the writing process's umask.

#### umask

`chmod` is absolute, but **`mkdir` is masked**. At the common default of `022`
the group-write bit is stripped and `02770` lands as `2750` — readable and
traversable by the group, but not writable. Set `0002` for both runtime
services so directories keep group-write:

```ini
; /etc/php/8.2/fpm/pool.d/www.conf
[www]
umask = 0002
```

Supervisor passes the environment through, so set it on the worker too:

```ini
[program:keje-worker]
umask=0002
```

Verify: `sudo chmod 2770` on a parent, upload something, then check the new
directory really is `2770` and not `2750`.

#### One-time remediation

Run once, on the host, after checking the current layout with
`php artisan media:diagnose`:

```bash
# 1. Group membership. Requires a NEW login session to take effect —
#    `id` in the current shell will keep showing the old groups.
sudo usermod -aG www-data deploy

# 2. Shared group on the runtime trees only. Not the repo, not vendor,
#    not .env.
sudo chgrp -R www-data storage bootstrap/cache

# 3. Directories: group rwx + setgid, nothing for the world.
sudo find storage bootstrap/cache -type d -exec chmod 2770 {} +

# 4. Files: group rw, nothing for the world.
sudo find storage bootstrap/cache -type f -exec chmod 0660 {} +

# 5. Owner stays the web user; the deploy user reaches them via the group.
sudo chown -R www-data:www-data storage bootstrap/cache
```

Then log out and back in, and confirm with `php artisan media:diagnose` **as
the deploy user** — running it as `root` proves nothing, because root passes
every permission check. The command says so when you do.

Do not apply any of this to `.env`, the repository files, `vendor/` or
`node_modules/`.

#### What survives what

| Event | Why it stays correct |
|---|---|
| New browser upload | disk config sets `2770`/`0660`; umask `0002` keeps group-write |
| New render | same config; the worker is the same user as PHP-FPM |
| Deploy | `deploy` is in `www-data`; setgid keeps the group off the creator |
| Queue restart | Supervisor `user=www-data`, `umask=0002` |
| Reboot | nothing here is runtime state |

The deploy workflow still re-applies group-write and setgid as a safety net,
but it is not the mechanism — the disk config is.

### Production environment

```dotenv
APP_ENV=production
APP_DEBUG=false
```

`APP_DEBUG=true` in production serves Laravel's full debug page — stack traces,
file paths, and configuration — to anyone who can trigger an error. It also
pulls in the framework's exception-renderer Blade views, which are compiled on
demand and therefore need a writable `storage/framework/views` at exactly the
moment something is already going wrong.

Source recordings and rendered videos live under `storage/app/private/content/{project-uuid}/` and are **never** served from a public directory.

### Local retention

The VPS is working space, not an archive — a lecture recording alone can be hundreds of megabytes. Once Drive confirms it holds the rendered MP4, the files that produced it are deleted:

| Removed | When |
|---|---|
| `source/` (audio, artwork), `text/`, `temp/` | once the Drive backup succeeds **and** the correction window has closed |
| `renders/output.mp4` | once Drive **and** YouTube both hold a copy, and the correction window has closed |

**The correction window.** Pruning used to run the moment YouTube confirmed the upload — which is precisely when the video becomes visible and its mistakes become findable. Someone who spotted a wrong speaker name the next morning had to find the original recording again, if they still had it.

The working files now survive `MEDIA_CORRECTION_WINDOW_DAYS` (default 14) after publication, so **correct → re-render → replace** works without the original MP3. Two things end the window:

- **Finalise project** on the project page — the usual path, and the honest one: the person who has just watched the video knows whether it is right far better than a clock does.
- The window elapsing.

Because pruning is otherwise opportunistic — it happens when a backup or upload finishes, and a project retained at that moment is never revisited — a sweeper collects whatever is left:

```bash
php artisan media:prune-expired --dry-run   # what would go
php artisan media:prune-expired             # do it
```

This is optional, not a requirement: Finalise frees a project immediately. `deploy/systemd/keje-prune.timer` automates it for hosts that want it. Set `MEDIA_CORRECTION_WINDOW_DAYS=0` to restore the previous prune-on-publish behaviour.

A replacement in flight always retains the render it is uploading, whatever the window says.

The MP4 is held back for YouTube because that upload reads the same local file; set `MEDIA_RETAIN_OUTPUT_FOR_YOUTUBE=false` if Drive is your only destination.

**Nothing is deleted without a confirmed Drive backup** — a failed or in-flight upload, or an `uploaded` status with no file id, prunes nothing.

This is a real trade. A pruned project **cannot be re-rendered**: its source audio is gone, so a title fix or template change means uploading the recording again. Every text and metadata field stays in the database, and the project points at its Drive copy. Set `MEDIA_PRUNE_SOURCES_AFTER_BACKUP=false` and `MEDIA_PRUNE_OUTPUT_AFTER_BACKUP=false` to keep everything.

To reclaim space from projects rendered before this existed:

```bash
php artisan media:prune --dry-run   # what would go, and how much
php artisan media:prune             # do it
```

---

## Vercel

Vercel imports the whole repository and deploys **`apps/web`** only.

| Setting | Value |
|---|---|
| Framework | Next.js |
| Root Directory | `apps/web` — not `web`, not `/apps/web` |

Frontend environment variables:

```
NEXT_PUBLIC_APP_NAME=Keje
NEXT_PUBLIC_API_BASE_URL=https://api.yourapp.com/api/v1
NEXT_PUBLIC_AUTH_MODE=bearer
API_PROXY_TARGET=https://api.yourapp.com
```

Sprint 1 adds **no new frontend environment variables**. FFmpeg runs on the VPS, not on Vercel. Google client secrets belong on the VPS, not on Vercel, and must never appear in a `NEXT_PUBLIC_*` variable.

`NEXT_PUBLIC_API_BASE_URL` does double duty: besides pointing the API client at Laravel, its **origin** is baked into the Content-Security-Policy at build time (`connect-src`, `img-src`, `media-src`). Because the API is a different origin from the Vercel deployment, a missing or stale value means the browser blocks every API call, background image and video with `(blocked:csp)`. It is read during `next build`, so changing it in the Vercel dashboard requires a **redeploy** to take effect.

---

## Security

- Uploads are authenticated and owner-scoped; a foreign resource returns `404`, not `403`.
- Media lives on a private disk, outside the web root.
- Filenames are server-generated from the project UUID; the original name is kept only as a display label.
- Uploads are verified with ffprobe, not by extension or browser MIME type.
- FFmpeg runs through Symfony Process with an argument array — never a shell string.
- User text reaches FFmpeg only through `textfile=`, with `expansion=none`.
- The frontend cannot supply FFmpeg filters or expressions. Every graph is built from application-owned template config.
- OAuth tokens are encrypted at rest, hidden from serialisation, and never returned by the API.
- OAuth `state` is single-use and bound to the user who started the flow.
- Video playback links are short-lived signatures, not permanent public URLs.

---

## Development status

### Sprint 1

- [x] Content projects
- [x] Topics
- [x] Speakers
- [x] Audio upload with ffprobe validation
- [x] Background upload with image validation
- [x] Kajian Tematik template (all 8 elements)
- [x] Text fitting and balanced subtitle wrapping
- [x] Browser preview from the shared layout contract
- [x] FFmpeg rendering on the queue
- [x] Real render progress
- [x] Video preview and download
- [x] `php artisan media:diagnose`
- [x] Google OAuth (server-side, encrypted tokens, channel verification)
- [x] Google Drive resumable backup
- [x] YouTube resumable upload
- [x] YouTube scheduling (private + `publishAt`)
- [x] Dashboard and Content Studio UI

**Verified end to end** against a live API and queue worker: register → topic → speaker → project → upload MP3 → upload artwork → preview → render → poll progress → download a 1280×720 H.264/AAC MP4.

**Verified with mocks only** — the Google code paths have full test coverage but have not been exercised against real Google APIs, because that needs live credentials and a real channel. Expect to shake out details on first connection.

### Not in Sprint 1

Creating YouTube playlists from Keje; automatic cleanup of local renders; analytics, comments and subscriber management; AI transcription, titles, summaries or chapters; subtitles and captions; Shorts; thumbnail generation; a drag-and-drop layout editor; multiple channels; cross-posting; team collaboration.

The architecture leaves room for these — `TemplateRegistry` and the template directories in particular mean a second template needs no changes to `ContentProject` or the renderer — but none of them are implemented.

---

See `STRUCTURE.md` for the layout map and where to put new code.
