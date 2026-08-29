# CLAUDE.md — Agent guidance for this repo

You (the agent) are working on **Keje**, a personal YouTube content-production and publishing workflow app, built on a Laravel + Next.js monorepo. The shipped baseline must always keep working: public `/` → `/login` or `/register` → authenticated `/dashboard` talking to a live Laravel API via bearer token, and the Content Studio must be able to take a lecture recording plus artwork to a rendered MP4.

## Read first

- `README.md` — the product, the Kajian Tematik template, the media pipeline, deployment.
- `STRUCTURE.md` — directory layout, the media pipeline map, conventions.
- `scripts/setup.mjs` — the one-shot installer users invoke via `npm run setup`.
- `apps/api/routes/api.php` — every HTTP contract lives here.
- `apps/api/resources/media/templates/kajian-tematik/template.php` — all template geometry.
- `apps/api/app/Services/Media/` — layout, ffprobe, FFmpeg, rendering.
- `apps/web/lib/auth/` — auth adapters; understand this before touching login/logout.
- `apps/web/components/auth-provider.tsx` — one source of truth for client-side auth state.

## Ground rules

- **No new runtime packages** unless truly necessary. Prefer existing deps (axios, zod, react-hook-form, SWR, sonner, shadcn/ui).
- **No new dev-only packages** inside `scripts/`. The setup console uses Node stdlib only (`readline/promises`, `fs`, `child_process`, etc.).
- **No secret commits.** Templates live in `.env.example` / `.env.local.example`. Real `.env` files are gitignored.
- **API is versioned.** New endpoints go under `/api/v1`. Only truly cross-version endpoints (like `/api/ping`) live outside the `v1` prefix.
- **One HTTP client on the web side.** Never import `axios` directly in pages or components — go through `@/lib/api`. The one exception is the cookie adapter calling `/sanctum/csrf-cookie`.
- **One auth provider.** Don't add another context. Extend the adapter interface (`apps/web/lib/auth/adapter.ts`) and add an entry in `apps/web/lib/auth/index.ts`.
- **Route group discipline.** Public routes go in `app/(public)/`. Authenticated routes go in `app/(app)/`. Add new protected prefixes to `middleware.ts`.

## When adding features

1. If it requires an env key, add it to `.env.example` (or `.env.local.example`) first, with a comment.
2. If it changes the auth contract, update **both** `apps/web/lib/auth/adapters/bearer.ts` and `apps/web/lib/auth/adapters/cookie.ts` — and the mock if relevant.
3. If it's a new API endpoint, add a feature test under `apps/api/tests/Feature/`.
4. If it touches the setup flow, make it idempotent. `npm run setup` must be safe to re-run.
5. If it prompts something, also support a non-interactive flag (`--my-option=...` + `--non-interactive`).

## CI

- `.github/workflows/ci.yml` runs web lint/typecheck/build, api phpunit, and a setup-script smoke.
- PHP 8.2 is the floor. Write code that works there.
- The Laravel test runner is PHPUnit 11; phpunit.xml uses `DB_CONNECTION=sqlite` in-memory.

## Things to avoid

- Don't quietly widen permissions in `CORS_ALLOWED_ORIGINS` or `SANCTUM_STATEFUL_DOMAINS` — those are security-sensitive.
- Don't reintroduce `react-hot-toast`. Sonner is the one toast library.
- Don't add Next.js `rewrites()`. The same-origin proxy at `app/api/[...path]/route.ts` is the server-side path.
- Don't couple dashboard/auth code to domain-specific models (users is fine; any app-specific resource is not).
- Don't commit generated files from `bootstrap/cache/` or `storage/**/` — the nested `.gitignore` files there take care of that.

## Where to put new code

| Thing | Where |
|---|---|
| New public page | `apps/web/app/(public)/<slug>/page.tsx` |
| New authenticated page | `apps/web/app/(app)/<slug>/page.tsx` + update `PROTECTED_PREFIXES` and `config.matcher` in `middleware.ts` + add a `<Link>` in `app/(app)/layout.tsx` if it's a top-level destination |
| New API endpoint | `apps/api/routes/api.php` (inside `v1` prefix; add to `auth:sanctum` group if protected) |
| New controller | `apps/api/app/Http/Controllers/Api/V1/<Name>Controller.php` |
| New form request | `apps/api/app/Http/Requests/Api/V1/<Name>Request.php` |
| New auth method | `apps/web/lib/auth/adapters/<name>.ts` + wire in `lib/auth/index.ts` |
| New setup prompt | `scripts/setup.mjs` (prompt helper) + add the env key to `.env.example` |
| New video template | `apps/api/resources/media/templates/<key>/template.php` + assets. Nothing else changes. |
| New render setting | `config/media.php` + the env key in `.env.example`. Never a magic number in a service. |

## Copying the Topics/Speakers pattern

`ContentTopic` and `Speaker` are the canonical CRUD resources now that the Notes demo is gone. When building a new one, copy that pattern end-to-end: migration with `uuid` + `foreignId('user_id')`, model using `HasUuid` (so routes bind on the UUID and integer ids stay internal) with `$fillable` excluding `user_id`, a policy, a controller that uses `$model->user()->associate($request->user())` and `abort_unless(..., 404)` for foreign records, a form request, an API Resource, and a feature test covering 401 / index-scope / store / validation / foreign-access-404. On the frontend: a page in `(app)/<slug>/` using SWR for reads and `@/lib/studio/api` for writes.

## Media rules

- **FFmpeg never runs in an HTTP request.** Rendering goes to `RenderContentProjectJob` on the `media` queue. The endpoint returns 202.
- **Never interpolate user text into a command or a filter graph.** Text runs are written to `.txt` files and drawn with `textfile=` plus `expansion=none`. Symfony Process with an argument array, never a shell string.
- **Never let a request supply an FFmpeg filter, expression or option.** Every graph is assembled from template config and `config/media.php`.
- **All template geometry lives in `template.php`.** No coordinates in services, controllers or components.
- **The browser preview must consume the API's resolved layout**, never its own coordinates — that is the only thing keeping preview and render in sync.
- **Text that does not fit is refused, never cropped.** Titles are one line, subtitles at most two.
- **Trust ffprobe, not the extension or the browser MIME type.**
- **The three pipelines are independent.** A Drive or YouTube failure must only ever touch its own `drive_*` / `youtube_*` columns.
- **Google tokens are encrypted, hidden, and never serialised into a response.** Secrets stay on the API host — never a `NEXT_PUBLIC_*` variable.

## Smoke test (for any PR you touch)

```bash
npm install
npm run setup --non-interactive --mode=local --auth-mode=bearer
npm run -w apps/web lint && npm run -w apps/web typecheck && npm run -w apps/web build
cd apps/api && php artisan test && ./vendor/bin/pint --test
```

All must pass. CI enforces the same matrix.

If you touched anything under `app/Services/Media/` or a template definition, also run `php artisan media:diagnose`, and render the fixture to look at an actual frame — the layout tests prove the maths, not that the composition looks right.
