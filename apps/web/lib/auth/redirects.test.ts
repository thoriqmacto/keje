/**
 * Tests for the anonymous / authenticated redirect rules.
 *
 * Node's built-in runner, no test framework. Run with:
 *
 *     npm run -w apps/web test
 */

import { test, describe } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
    ANONYMOUS_ONLY_PATHS,
    DEFAULT_AUTHENTICATED_PATH,
    PROTECTED_PREFIXES,
    authenticatedDestination,
    isAnonymousOnlyPath,
    isProtectedPath,
    routeForVisitor,
    safeNextPath,
} from "./redirects.ts";

/** Read the `next` a /login redirect carries, rather than matching encoding. */
function nextParamOf(location: string): string | null {
    return new URL(location, "https://keje.test").searchParams.get("next");
}

const HINT = true;
const NO_HINT = false;

describe("routeForVisitor — the ten states", () => {
    test("1. anonymous at / sees the public homepage", () => {
        assert.deepEqual(routeForVisitor("/", "", NO_HINT), { kind: "continue" });
    });

    test("2. hinted session at / goes to the dashboard", () => {
        assert.deepEqual(routeForVisitor("/", "", HINT), {
            kind: "redirect",
            to: "/dashboard",
        });
    });

    test("3. hinted session at /login goes to the dashboard", () => {
        assert.deepEqual(routeForVisitor("/login", "", HINT), {
            kind: "redirect",
            to: "/dashboard",
        });
    });

    test("4. hinted session at /register goes to the dashboard", () => {
        assert.deepEqual(routeForVisitor("/register", "", HINT), {
            kind: "redirect",
            to: "/dashboard",
        });
    });

    test("5. anonymous at /dashboard goes to /login carrying next", () => {
        const decision = routeForVisitor("/dashboard", "", NO_HINT);
        assert.equal(decision.kind, "redirect");
        assert.ok(decision.kind === "redirect");
        assert.ok(decision.to.startsWith("/login?"));
        assert.equal(nextParamOf(decision.to), "/dashboard");
    });

    test("6. a cleared stale hint leaves the homepage public, with no loop", () => {
        // AuthProvider clears auth_hint the moment it finds no stored token, so
        // the very next request is the anonymous case — not another bounce.
        assert.deepEqual(routeForVisitor("/", "", HINT), {
            kind: "redirect",
            to: "/dashboard",
        });
        assert.deepEqual(routeForVisitor("/", "", NO_HINT), { kind: "continue" });
    });

    test("7 & 8. once expired or revoked auth is cleared, /login is reachable", () => {
        // clearAuth() drops token and hint together, so the login form renders
        // instead of redirecting back to the dashboard.
        assert.deepEqual(routeForVisitor("/login", "", NO_HINT), { kind: "continue" });
        assert.deepEqual(routeForVisitor("/login", "?next=%2Fstudio", NO_HINT), {
            kind: "continue",
        });
    });

    test("9. a hinted user at /login?next=… lands on the requested page", () => {
        assert.deepEqual(routeForVisitor("/login", "?next=%2Fstudio%2Fabc", HINT), {
            kind: "redirect",
            to: "/studio/abc",
        });
    });

    test("10. after logout the hint is gone and / is public again", () => {
        assert.deepEqual(routeForVisitor("/", "", NO_HINT), { kind: "continue" });
        assert.deepEqual(routeForVisitor("/register", "", NO_HINT), { kind: "continue" });
    });
});

describe("routeForVisitor — no redirect loops", () => {
    test("a hinted visitor is never sent to another anonymous-only route", () => {
        for (const path of ANONYMOUS_ONLY_PATHS) {
            const decision = routeForVisitor(path, "", HINT);
            assert.ok(decision.kind === "redirect", path);
            assert.equal(isAnonymousOnlyPath(decision.to), false, path);
        }
    });

    test("a hinted visitor is never bounced away from a protected route", () => {
        // Otherwise / -> /dashboard -> / would cycle forever.
        for (const prefix of PROTECTED_PREFIXES) {
            assert.deepEqual(routeForVisitor(prefix, "", HINT), { kind: "continue" }, prefix);
        }
    });

    test("the /login target of a protected redirect is itself terminal", () => {
        const decision = routeForVisitor("/studio/abc", "", NO_HINT);
        assert.ok(decision.kind === "redirect");
        const target = new URL(decision.to, "https://keje.test");
        assert.deepEqual(
            routeForVisitor(target.pathname, target.search, NO_HINT),
            { kind: "continue" },
            "login must not redirect an anonymous visitor again",
        );
    });

    test("a next pointing back at an anonymous-only route is refused", () => {
        // /login?next=/login would otherwise redirect to itself.
        assert.deepEqual(routeForVisitor("/login", "?next=%2Flogin", HINT), {
            kind: "redirect",
            to: DEFAULT_AUTHENTICATED_PATH,
        });
        assert.deepEqual(routeForVisitor("/", "?next=%2F", HINT), {
            kind: "redirect",
            to: DEFAULT_AUTHENTICATED_PATH,
        });
    });
});

describe("routeForVisitor — query strings", () => {
    test("the original query survives the round trip to /login", () => {
        const decision = routeForVisitor("/studio/abc", "?tab=render", NO_HINT);
        assert.ok(decision.kind === "redirect");
        assert.equal(nextParamOf(decision.to), "/studio/abc?tab=render");
    });

    test("a hinted visitor keeps the query on the resumed destination", () => {
        assert.deepEqual(
            routeForVisitor("/login", "?next=%2Fstudio%2Fabc%3Ftab%3Drender", HINT),
            { kind: "redirect", to: "/studio/abc?tab=render" },
        );
    });
});

describe("safeNextPath — only internal destinations", () => {
    test("accepts ordinary internal paths", () => {
        assert.equal(safeNextPath("/dashboard"), "/dashboard");
        assert.equal(safeNextPath("/studio/abc"), "/studio/abc");
        assert.equal(safeNextPath("/studio/abc?tab=render"), "/studio/abc?tab=render");
        assert.equal(safeNextPath("/settings/integrations"), "/settings/integrations");
    });

    test("rejects absolute URLs to another origin", () => {
        assert.equal(safeNextPath("https://evil.example/steal"), null);
        assert.equal(safeNextPath("http://evil.example"), null);
    });

    test("rejects protocol-relative URLs, which browsers treat as external", () => {
        assert.equal(safeNextPath("//evil.example"), null);
        assert.equal(safeNextPath("//evil.example/path"), null);
    });

    test("rejects the backslash form of a protocol-relative URL", () => {
        // Browsers normalise "/\evil.example" to "//evil.example".
        assert.equal(safeNextPath("/\\evil.example"), null);
        assert.equal(safeNextPath("/\\/evil.example"), null);
    });

    test("rejects control characters, including header-splitting newlines", () => {
        assert.equal(safeNextPath("/dashboard\nSet-Cookie: a=b"), null);
        assert.equal(safeNextPath("/dash\u0000board"), null);
        assert.equal(safeNextPath("/dash\u007fboard"), null);
    });

    test("rejects anything that is not a path at all", () => {
        assert.equal(safeNextPath("dashboard"), null);
        assert.equal(safeNextPath(""), null);
        assert.equal(safeNextPath(null), null);
        assert.equal(safeNextPath(undefined), null);
    });

    test("rejects signed-out-only routes", () => {
        for (const path of ANONYMOUS_ONLY_PATHS) {
            assert.equal(safeNextPath(path), null, path);
        }
        assert.equal(safeNextPath("/login?next=%2F"), null);
    });

    test("authenticatedDestination falls back to the dashboard", () => {
        assert.equal(authenticatedDestination("//evil.example"), DEFAULT_AUTHENTICATED_PATH);
        assert.equal(authenticatedDestination(null), DEFAULT_AUTHENTICATED_PATH);
        assert.equal(authenticatedDestination("/studio/abc"), "/studio/abc");
    });
});

describe("path predicates", () => {
    test("isAnonymousOnlyPath matches only the three signed-out routes", () => {
        assert.equal(isAnonymousOnlyPath("/"), true);
        assert.equal(isAnonymousOnlyPath("/login"), true);
        assert.equal(isAnonymousOnlyPath("/register"), true);
        assert.equal(isAnonymousOnlyPath("/login/"), true);
    });

    test("password and verification routes stay reachable while signed in", () => {
        // They are opened from emailed links; bouncing them to the dashboard
        // would break password resets for anyone with a live session.
        for (const path of ["/forgot-password", "/reset-password", "/verify-email"]) {
            assert.equal(isAnonymousOnlyPath(path), false, path);
            assert.deepEqual(routeForVisitor(path, "", HINT), { kind: "continue" }, path);
        }
    });

    test("isProtectedPath covers each prefix and its children", () => {
        assert.equal(isProtectedPath("/dashboard"), true);
        assert.equal(isProtectedPath("/studio/abc/render"), true);
        assert.equal(isProtectedPath("/settings/integrations"), true);
    });

    test("isProtectedPath ignores a sibling that merely shares a prefix", () => {
        assert.equal(isProtectedPath("/settings-other"), false);
        assert.equal(isProtectedPath("/studiolike"), false);
        assert.equal(isProtectedPath("/"), false);
    });
});

describe("middleware matcher", () => {
    // The original bug was exactly this: "/" was absent from the matcher, so
    // middleware never ran for the homepage no matter what the rules said.
    const source = readFileSync(new URL("../../middleware.ts", import.meta.url), "utf8");
    const matcher = source.slice(source.indexOf("matcher:"));

    test("every signed-out-only route is matched", () => {
        for (const path of ANONYMOUS_ONLY_PATHS) {
            assert.ok(matcher.includes(`"${path}"`), `matcher is missing ${path}`);
        }
    });

    test("every protected prefix is matched with its children", () => {
        for (const prefix of PROTECTED_PREFIXES) {
            assert.ok(
                matcher.includes(`"${prefix}/:path*"`),
                `matcher is missing ${prefix}/:path*`,
            );
        }
    });
});
