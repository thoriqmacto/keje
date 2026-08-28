# Repository structure

Top-level:

```
/
├── apps/
│   ├── api/                   Laravel 12 REST API
│   └── web/                   Next.js 15 frontend
├── scripts/
│   ├── setup.mjs              Interactive installer
│   └── lib/                   Setup script modules (stdlib only)
├── .github/workflows/ci.yml   Web + API CI
├── turbo.json                 Turbo pipeline
├── package.json               npm workspaces root
├── README.md                  Quickstart + ops
└── STRUCTURE.md               This file
```

---

## `apps/api` — Laravel

```
apps/api/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/V1/AuthController.php
│   │   └── Requests/Api/V1/{Login,Register}Request.php
│   ├── Models/User.php
│   └── Providers/AppServiceProvider.php
├── bootstrap/
│   ├── app.php                Registers routes/web.php AND routes/api.php
│   └── providers.php
├── config/
│   ├── auth.php
│   ├── cors.php               env-driven (CORS_ALLOWED_ORIGINS, …)
│   └── sanctum.php            token expiration knobs
├── database/
│   ├── factories/UserFactory.php
│   ├── migrations/            users, cache, jobs, personal_access_tokens
│   └── seeders/DatabaseSeeder.php
├── routes/
│   ├── api.php                /api/ping + /api/v1/{register,login,me,logout}
│   ├── web.php                / health JSON
│   └── console.php
├── tests/
│   ├── Feature/Auth/{Register,Login,Me,Logout}Test.php
│   └── Feature/HealthTest.php
├── .env.example               committed; source of truth for env keys
├── composer.json
├── package.json               Turbo shim (scripts only, no deps)
└── phpunit.xml                SQLite in-memory
```

### Adding a new resource

1. `php artisan make:controller Api/V1/WidgetController --api`
2. Add `Route::apiResource('widgets', WidgetController::class)` inside the `auth:sanctum` group in `routes/api.php` (or create `routes/api_v1.php` and `require` it there when the list grows).
3. Add migration + model.
4. Add a feature test under `tests/Feature/Widgets/`.

### Where auth lives

- Controller: `app/Http/Controllers/Api/V1/AuthController.php`
- Form requests: `app/Http/Requests/Api/V1/{Register,Login}Request.php`
- Config: `config/sanctum.php` (expiration), `config/cors.php` (origins)
- Token issuance reads `config('sanctum.token_expiration_hours')`.

---

## `apps/web` — Next.js

```
apps/web/
├── app/
│   ├── (public)/              Public route group — owns /
│   │   ├── layout.tsx         Public shell (nav, footer)
│   │   ├── page.tsx           Landing
│   │   ├── login/
│   │   │   ├── page.tsx
│   │   │   └── LoginForm.tsx  shadcn + zod + react-hook-form
│   │   ├── register/
│   │   │   ├── page.tsx
│   │   │   └── RegisterForm.tsx
│   │   ├── forgot-password/
│   │   │   ├── page.tsx
│   │   │   └── ForgotPasswordForm.tsx
│   │   ├── reset-password/
│   │   │   ├── page.tsx
│   │   │   └── ResetPasswordForm.tsx
│   │   └── verify-email/      Reads ?status=… after backend redirect
│   │       ├── page.tsx
│   │       └── VerifyEmailClient.tsx
│   ├── (app)/                 Authenticated route group
│   │   ├── layout.tsx         Waits for AuthProvider, redirects if anon
│   │   ├── dashboard/page.tsx Proof-of-install page
│   │   ├── settings/          Profile, password, email verification banner
│   │   │   ├── page.tsx
│   │   │   └── SettingsClient.tsx
│   │   └── notes/             Example resource — safe to delete
│   │       ├── page.tsx
│   │       └── NotesClient.tsx (SWR list, create, optimistic delete)
│   ├── api/[...path]/         Same-origin proxy to the Laravel API
│   ├── layout.tsx             Root layout (fonts, <Providers>)
│   ├── providers.tsx          AuthProvider + sonner Toaster
│   └── globals.css
├── components/
│   ├── auth-provider.tsx      Auth context + 401 handler
│   └── ui/                    shadcn/ui primitives
├── lib/
│   ├── api.ts                 Single axios instance
│   ├── env.ts                 Typed env access
│   └── auth/
│       ├── index.ts           Picks adapter from NEXT_PUBLIC_AUTH_MODE
│       ├── adapter.ts         AuthAdapter interface
│       ├── storage.ts         localStorage + cookie hint helpers
│       └── adapters/
│           ├── bearer.ts      Default
│           ├── cookie.ts      Sanctum SPA-cookie
│           └── mock.ts        Frontend-only dev (NEXT_PUBLIC_AUTH_MODE=mock)
├── middleware.ts              Guards /dashboard via auth_hint cookie
├── next.config.ts             Minimal; no rewrites
├── .env.local.example         committed
├── tsconfig.json
└── package.json
```

### Where to add a new page

- **Public (no auth):** `app/(public)/<name>/page.tsx`. Link it from `app/(public)/layout.tsx` nav if needed.
- **Authenticated:** `app/(app)/<name>/page.tsx`. Add the prefix to `PROTECTED_PREFIXES` in `middleware.ts`.
- **API call:** import `api` from `@/lib/api`. Never call `axios` directly — all HTTP must go through the shared instance so auth and 401 handling are consistent.

### Where to extend auth

- New login method (e.g., magic link, OAuth): add `lib/auth/adapters/<name>.ts` implementing `AuthAdapter`, then add it in `lib/auth/index.ts` based on a new `NEXT_PUBLIC_AUTH_MODE` value. Nothing else changes.

---

## `scripts/` — setup console

Stdlib only. No runtime dependencies.

```
scripts/
├── setup.mjs                  Entry. Subcommands: setup | env | check
└── lib/
    ├── detect.mjs             Node/PHP/Composer/npm detection
    ├── env.mjs                .env read/merge/write (preserves comments)
    ├── herd.mjs               macOS Herd symlink helper
    ├── log.mjs                ANSI-aware logging
    ├── prompt.mjs             readline/promises wrappers
    └── run.mjs                child_process helpers
```

`setup.mjs` composes these; each is individually callable.

---

## Example resource (Notes)

A complete demo of the authenticated CRUD pattern. Copy as a template, or remove by deleting:

**Backend**
- `apps/api/database/migrations/*_create_notes_table.php`
- `apps/api/app/Models/Note.php`
- `apps/api/app/Http/Controllers/Api/V1/NoteController.php`
- `apps/api/app/Http/Requests/Api/V1/StoreNoteRequest.php`
- `apps/api/database/factories/NoteFactory.php`
- `apps/api/tests/Feature/Notes/`
- The `apiResource('notes', ...)` line in `routes/api.php`

**Frontend**
- `apps/web/app/(app)/notes/`
- The `/notes` entry in `PROTECTED_PREFIXES` and the `matcher` array in `apps/web/middleware.ts`
- The `<Link href="/notes">` in `apps/web/app/(app)/layout.tsx`

---

## Conventions

- **Env templates are the contract.** Every new env key goes into `.env.example` or `.env.local.example` first, with a comment.
- **One HTTP client.** Web uses `apps/web/lib/api.ts`. Do not import `axios` directly in pages or components (the cookie adapter is the one exception, and only for `/sanctum/csrf-cookie`).
- **No new runtime packages** for the starter surface. Prefer using what's already installed.
- **Route group naming.** `(public)` and `(app)` are the only groups today. Parenthesized segments don't change URLs — they let each shell own its own layout.
- **API versioning.** Everything behind `/api/v1/*`. Health lives at `/api/ping`.
