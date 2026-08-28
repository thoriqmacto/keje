# CLAUDE.md — Agent guidance for this repo

You (the agent) are working on a **reusable Laravel + Next.js monorepo starter**. The shipped baseline must always keep working: public `/` → `/login` or `/register` → authenticated `/dashboard` talking to a live Laravel API via bearer token.

## Read first

- `README.md` — quickstart, setup modes, auth modes, troubleshooting.
- `STRUCTURE.md` — directory layout and conventions.
- `scripts/setup.mjs` — the one-shot installer users invoke via `npm run setup`.
- `apps/api/routes/api.php` — every HTTP contract lives here.
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

## Copying the Notes example

`notes` is the canonical CRUD template in this repo. When building a new resource, copy that pattern end-to-end: migration with `foreignId('user_id')`, model with `$fillable` excluding `user_id`, controller that uses `$model->user()->associate($request->user())` to attach the owner, form request for validation, feature test covering 401 / index-scope / store / validation / delete-self / delete-other. On the frontend: a page in `(app)/<slug>/` using SWR for reads and `api` for writes, with optimistic deletes.

## Smoke test (for any PR you touch)

```bash
npm install
npm run setup --non-interactive --mode=local --auth-mode=bearer
npm run -w apps/web lint && npm run -w apps/web typecheck && npm run -w apps/web build
cd apps/api && php artisan test
```

All must pass. CI enforces the same matrix.
