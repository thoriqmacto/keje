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
| `/studio` | All content projects with per-pipeline status |
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
GET    /content-projects/{uuid}/render-status  status + progress
GET    /content-projects/{uuid}/video          stream the MP4
GET    /content-projects/{uuid}/download       download the MP4
GET    /content-projects/{uuid}/media-links    short-lived signed playback URLs

POST   /content-projects/{uuid}/drive          202, queues the Drive backup
POST   /content-projects/{uuid}/youtube        202, queues the YouTube upload
POST   /content-projects/{uuid}/youtube/playlist  retry playlist membership only

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

## Topics and YouTube playlists

A `ContentTopic` is the lecture series. It exists so you type "Riyadhush Shalihin" once, and it carries an optional `youtube_playlist_id`:

```
Keje Topic                YouTube Playlist
Riyadhush Shalihin   →    PLxxxxxxxxxxxx
```

Linking is **never required to render**. Creating playlists from Keje is deliberately not implemented yet — you pick from the ones the channel already has.

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

`php artisan media:diagnose` checks the FFmpeg and FFprobe binaries and versions, both font files, the template definitions and their assets, private storage writability, free disk space, the queue driver, and the Google configuration. It exits non-zero when something critical is missing, so it can gate a deploy.

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

Rendering **must not** use the `sync` driver — that would run FFmpeg inside a web request. `media:diagnose` fails if you try.

```bash
php artisan queue:work --queue=media,default --timeout=7200 --tries=2
```

Supervisor (`/etc/supervisor/conf.d/keje-worker.conf`):

```ini
[program:keje-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/keje/apps/api/artisan queue:work --queue=media,default --timeout=7200 --tries=2 --sleep=3 --max-jobs=50
directory=/var/www/keje/apps/api
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/keje/worker.log
stopwaitsecs=7300
```

One worker is right for a single-operator setup: renders are CPU-bound and running two in parallel makes both slower. `stopwaitsecs` must exceed the render timeout so a deploy never kills a render mid-encode.

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

`storage` and `bootstrap/cache` are written by **two** users: the deploy user
(`view:cache`, `config:cache`, migrations) and the PHP-FPM user at runtime.
Getting the owner right once is not enough — without the setgid bit, files the
deploy writes are owned by the deploy user's own group and PHP-FPM cannot
rewrite them on the next request.

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
# setgid: files created later inherit the directory's group, not the creator's.
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
# Let the deploy user write them too.
sudo usermod -aG www-data <deploy-user>
```

The deploy workflow re-applies the group-write and setgid bits to these
directories after each run, but only as a best effort: once `storage` belongs
to the web user, the deploy user no longer owns it and `chmod` is refused —
only an owner may change a file's mode. That refusal is ignored, because on
such a host the bits are already correct. Establish them with the commands
above; the workflow is a safety net for hosts where the deploy user owns
`storage` itself.

`php artisan media:diagnose` reports the state of every one of these
directories.

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
| `source/` (audio, artwork), `text/`, `temp/` | as soon as the Drive backup succeeds |
| `renders/output.mp4` | once Drive **and** YouTube both hold a copy |

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
