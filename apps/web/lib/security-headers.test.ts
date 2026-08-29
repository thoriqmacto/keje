/**
 * Tests for the CSP builder.
 *
 * Uses Node's built-in test runner and native TypeScript type stripping, so
 * this needs no test framework and adds no dependencies. Run with:
 *
 *     npm run -w apps/web test
 */

import { test, describe } from "node:test";
import assert from "node:assert/strict";
import {
    apiOrigin,
    buildContentSecurityPolicy,
    buildSecurityHeaders,
} from "./security-headers.ts";

const PROD_API = "https://kajian.codingbox.id/api/v1";
const PROD_ORIGIN = "https://kajian.codingbox.id";

/** Pull one directive out of a policy string. */
function directive(policy: string, name: string): string | undefined {
    return policy
        .split("; ")
        .find((part) => part === name || part.startsWith(`${name} `));
}

describe("apiOrigin", () => {
    test("reduces the API base URL to its origin, dropping the path", () => {
        assert.equal(apiOrigin(PROD_API), PROD_ORIGIN);
    });

    test("keeps a non-default port, which is a distinct origin", () => {
        assert.equal(apiOrigin("http://localhost:8000/api/v1"), "http://localhost:8000");
    });

    test("returns null when the variable is unset", () => {
        assert.equal(apiOrigin(undefined), null);
        assert.equal(apiOrigin(""), null);
    });

    test("returns null for a malformed URL instead of throwing", () => {
        // A bare host with no scheme is the likeliest misconfiguration.
        assert.doesNotThrow(() => apiOrigin("kajian.codingbox.id/api/v1"));
        assert.equal(apiOrigin("kajian.codingbox.id/api/v1"), null);
        assert.equal(apiOrigin("not a url"), null);
        assert.equal(apiOrigin("://"), null);
    });

    test("refuses non-http(s) schemes, whose origin serialises to \"null\"", () => {
        // new URL() parses these happily; allow-listing them would be wrong.
        for (const url of ["data:text/html,x", "javascript:alert(1)", "file:///etc/passwd"]) {
            assert.equal(apiOrigin(url), null, url);
        }
    });
});

describe("buildContentSecurityPolicy with a cross-origin API", () => {
    const policy = buildContentSecurityPolicy(PROD_ORIGIN);

    test("allows the API origin to be called, so auth requests are not blocked", () => {
        // This is the bug: with `connect-src 'self'` the browser blocked
        // POST /api/v1/register as (blocked:csp) before it reached Laravel.
        assert.equal(directive(policy, "connect-src"), `connect-src 'self' ${PROD_ORIGIN}`);
    });

    test("allows images from the API origin for studio artwork", () => {
        assert.equal(
            directive(policy, "img-src"),
            `img-src 'self' data: blob: ${PROD_ORIGIN}`,
        );
    });

    test("allows media from the API origin for signed video playback", () => {
        assert.equal(
            directive(policy, "media-src"),
            `media-src 'self' blob: ${PROD_ORIGIN}`,
        );
    });

    test("matches the documented production policy exactly", () => {
        assert.equal(
            policy,
            [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                `img-src 'self' data: blob: ${PROD_ORIGIN}`,
                `connect-src 'self' ${PROD_ORIGIN}`,
                `media-src 'self' blob: ${PROD_ORIGIN}`,
                "frame-ancestors 'none'",
            ].join("; "),
        );
    });
});

describe("buildContentSecurityPolicy safety properties", () => {
    for (const [label, origin] of [
        ["with an API origin", PROD_ORIGIN],
        ["without an API origin", null],
    ] as const) {
        test(`does not weaken default-src ${label}`, () => {
            assert.equal(directive(buildContentSecurityPolicy(origin), "default-src"), "default-src 'self'");
        });

        test(`uses no wildcard sources ${label}`, () => {
            const policy = buildContentSecurityPolicy(origin);
            // `connect-src *` and friends would defeat the point of having a CSP.
            for (const part of policy.split("; ")) {
                const sources = part.split(" ").slice(1);
                assert.ok(!sources.includes("*"), `wildcard in: ${part}`);
                assert.ok(
                    !sources.some((s) => s.startsWith("http://*") || s.startsWith("https://*")),
                    `wildcard host in: ${part}`,
                );
            }
        });

        test(`keeps every directive present ${label}`, () => {
            const policy = buildContentSecurityPolicy(origin);
            for (const name of [
                "default-src",
                "script-src",
                "style-src",
                "font-src",
                "img-src",
                "connect-src",
                "media-src",
                "frame-ancestors",
            ]) {
                assert.ok(directive(policy, name), `missing ${name}`);
            }
        });
    }

    test("only the three directives that need the API origin receive it", () => {
        const policy = buildContentSecurityPolicy(PROD_ORIGIN);
        const carrying = policy
            .split("; ")
            .filter((part) => part.includes(PROD_ORIGIN))
            .map((part) => part.split(" ")[0]);

        assert.deepEqual(carrying.sort(), ["connect-src", "img-src", "media-src"]);
    });
});

describe("buildContentSecurityPolicy without a configured API", () => {
    const policy = buildContentSecurityPolicy(null);

    test("falls back to self-only sources rather than throwing", () => {
        assert.equal(directive(policy, "connect-src"), "connect-src 'self'");
        assert.equal(directive(policy, "img-src"), "img-src 'self' data: blob:");
        assert.equal(directive(policy, "media-src"), "media-src 'self' blob:");
    });

    test("leaves no dangling separator from the absent origin", () => {
        assert.ok(!policy.includes("  "), policy);
        assert.ok(!policy.includes(" ;"), policy);
    });
});

describe("buildSecurityHeaders", () => {
    const headers = buildSecurityHeaders();
    const byKey = (key: string) => headers.find((h) => h.key === key)?.value;

    test("preserves the other security headers alongside the CSP", () => {
        assert.equal(byKey("X-Content-Type-Options"), "nosniff");
        assert.equal(byKey("X-Frame-Options"), "SAMEORIGIN");
        assert.equal(byKey("Referrer-Policy"), "strict-origin-when-cross-origin");
        assert.equal(
            byKey("Strict-Transport-Security"),
            "max-age=31536000; includeSubDomains",
        );
        assert.equal(byKey("Permissions-Policy"), "camera=(), microphone=(), geolocation=()");
        assert.ok(byKey("Content-Security-Policy"));
    });

    test("emits each header exactly once", () => {
        const keys = headers.map((h) => h.key);
        assert.equal(new Set(keys).size, keys.length);
    });
});
