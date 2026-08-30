/**
 * Redirect rules for the anonymous / authenticated boundary.
 *
 * Imported by `middleware.ts` (edge runtime) *and* by client components, so
 * this module must stay pure: no `window`, no `next/server`, no env lookups.
 * Keeping the rules here is what stops the middleware fast path and the
 * client-side guard from drifting apart and bouncing users between them.
 */

/** Where a signed-in user lands when there is no safe `next` target. */
export const DEFAULT_AUTHENTICATED_PATH = "/dashboard";

/**
 * Routes that only make sense while signed out.
 *
 * Deliberately excludes /forgot-password, /reset-password and /verify-email:
 * those are opened from emailed links and have to keep working even when a
 * session is already open.
 */
export const ANONYMOUS_ONLY_PATHS = ["/", "/login", "/register"] as const;

/** Prefixes that require a session. Mirrored by the middleware matcher. */
export const PROTECTED_PREFIXES = ["/dashboard", "/studio", "/settings"] as const;

function stripTrailingSlash(pathname: string): string {
    return pathname.length > 1 && pathname.endsWith("/") ? pathname.slice(0, -1) : pathname;
}

export function isAnonymousOnlyPath(pathname: string): boolean {
    const path = stripTrailingSlash(pathname);
    return (ANONYMOUS_ONLY_PATHS as readonly string[]).includes(path);
}

export function isProtectedPath(pathname: string): boolean {
    const path = stripTrailingSlash(pathname);
    return PROTECTED_PREFIXES.some((prefix) => path === prefix || path.startsWith(`${prefix}/`));
}

/**
 * Validate a `?next=` value, which is attacker-controllable: it arrives in a
 * URL anyone can hand a signed-in user.
 *
 * Returns the path when it is a safe same-site destination, otherwise null so
 * the caller can fall back to the dashboard.
 */
export function safeNextPath(raw: string | null | undefined): string | null {
    if (typeof raw !== "string" || raw === "") return null;

    // Must be site-relative. A leading "/" alone is not enough: browsers read
    // "//evil.com" and "/\evil.com" as protocol-relative URLs, so both would
    // navigate off-site.
    if (!raw.startsWith("/")) return null;
    if (raw.startsWith("//")) return null;
    if (raw.includes("\\")) return null;

    // Control characters never belong in a path, and a newline here would be a
    // header-splitting attempt.
    if (/[\u0000-\u001f\u007f]/.test(raw)) return null;

    // Returning a signed-in user to a signed-out-only route would bounce them
    // straight back to where they came from.
    const [pathname] = raw.split(/[?#]/);
    if (isAnonymousOnlyPath(pathname)) return null;

    return raw;
}

/** The safe `next` destination, or the dashboard. */
export function authenticatedDestination(raw: string | null | undefined): string {
    return safeNextPath(raw) ?? DEFAULT_AUTHENTICATED_PATH;
}

/** What the middleware should do with a request. */
export type RouteDecision = { kind: "continue" } | { kind: "redirect"; to: string };

/**
 * The whole anonymous/authenticated routing rule, as a pure function so it can
 * be tested without spinning up Next.js.
 *
 * `hasHint` is the auth_hint cookie: a hint about which page to show first,
 * never authorization. The client-side guard and AuthProvider are what
 * actually enforce the session.
 */
export function routeForVisitor(pathname: string, search: string, hasHint: boolean): RouteDecision {
    if (hasHint && isAnonymousOnlyPath(pathname)) {
        const next = new URLSearchParams(search).get("next");
        return { kind: "redirect", to: authenticatedDestination(next) };
    }

    if (!hasHint && isProtectedPath(pathname)) {
        const target = new URLSearchParams({ next: `${pathname}${search}` });
        return { kind: "redirect", to: `/login?${target}` };
    }

    return { kind: "continue" };
}
