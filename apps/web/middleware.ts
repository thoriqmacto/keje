import { NextRequest, NextResponse } from "next/server";
import { routeForVisitor } from "@/lib/auth/redirects";

/**
 * Cookie written by lib/auth/storage.ts alongside the bearer token.
 *
 * It is a *hint*, never proof. It is readable and forgeable, and the token it
 * shadows lives in localStorage where middleware cannot reach it. All it does
 * is decide which page to render first, so the anonymous landing page does not
 * flash for a signed-in user. Authorization stays where it belongs: every
 * protected page waits on AuthProvider validating the token against Laravel's
 * /me, and the API rejects any request without a real bearer token.
 */
const HINT_COOKIE = "auth_hint";

export function middleware(req: NextRequest) {
    const { pathname, search } = req.nextUrl;
    const hasHint = req.cookies.get(HINT_COOKIE)?.value === "1";

    // Signed-in visitors skip the landing page and the auth forms; signed-out
    // visitors are sent to the form carrying where they were going. The rule
    // itself lives in lib/auth/redirects.ts so it is unit-testable.
    const decision = routeForVisitor(pathname, search, hasHint);
    if (decision.kind === "redirect") {
        return NextResponse.redirect(new URL(decision.to, req.nextUrl));
    }

    return NextResponse.next();
}

export const config = {
    // Must stay in step with ANONYMOUS_ONLY_PATHS and PROTECTED_PREFIXES in
    // lib/auth/redirects.ts — a path the matcher misses never reaches the
    // middleware at all.
    matcher: [
        "/",
        "/login",
        "/register",
        "/dashboard/:path*",
        "/studio/:path*",
        "/settings/:path*",
        "/youtube/:path*",
        "/drive/:path*",
    ],
};
