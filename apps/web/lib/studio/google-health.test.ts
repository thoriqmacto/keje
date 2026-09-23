import assert from "node:assert/strict";
import { test } from "node:test";
import {
    attentionSummary,
    healthSeverity,
    isStale,
    needsAttention,
    worstSeverity,
} from "./google-health.ts";
import type { GoogleHealth, GoogleHealthStatus } from "../types/studio.ts";

function health(status: GoogleHealthStatus, overrides: Partial<GoogleHealth> = {}): GoogleHealth {
    return {
        service: "youtube",
        label: "YouTube",
        status,
        message: "Something about the connection.",
        guidance: [],
        configured: true,
        connected: true,
        checked_at: "2026-09-23T10:00:00+00:00",
        failing_since: null,
        expires_at: null,
        expires_in_seconds: null,
        expires_in_human: null,
        ...overrides,
    };
}

test("a working connection is never in the banner", () => {
    assert.equal(needsAttention(health("healthy")), false);
});

test("every broken status reaches the banner", () => {
    for (const status of [
        "renew_required",
        "invalid_client",
        "scope_error",
        "api_disabled",
        "unknown_error",
    ] as const) {
        assert.equal(needsAttention(health(status)), true, status);
    }
});

test("the one real countdown reaches the banner before anything breaks", () => {
    assert.equal(needsAttention(health("expiring_soon")), true);
});

test("a network blip never blames the connection", () => {
    // The status the UI must tell apart from a real failure: nothing was
    // learned about the credentials, so a banner would be an accusation the
    // check never made.
    assert.equal(needsAttention(health("unreachable")), false);
    assert.equal(healthSeverity("unreachable"), "warning");
});

test("a connection nobody set up is information, not a fault", () => {
    assert.equal(needsAttention(health("disconnected")), false);
    assert.equal(needsAttention(health("not_configured")), false);
    assert.equal(healthSeverity("disconnected"), "info");
});

test("the banner takes the worse of the two services", () => {
    assert.equal(worstSeverity([health("healthy"), health("renew_required")]), "critical");
    assert.equal(worstSeverity([health("healthy"), health("expiring_soon")]), "warning");
    assert.equal(worstSeverity([health("healthy"), health("healthy")]), "ok");
    assert.equal(worstSeverity([]), "ok");
});

test("one unhappy service is named with its own sentence", () => {
    assert.equal(
        attentionSummary([
            health("healthy", { service: "drive", label: "Google Drive" }),
            health("renew_required", { message: "Google rejected the stored credentials." }),
        ]),
        "YouTube: Google rejected the stored credentials.",
    );
});

test("two unhappy services are both named", () => {
    const summary = attentionSummary([
        health("renew_required"),
        health("api_disabled", { service: "drive", label: "Google Drive" }),
    ]);

    assert.equal(summary, "YouTube and Google Drive both need attention before the next upload.");
});

test("nothing wrong means no banner at all", () => {
    assert.equal(attentionSummary([health("healthy"), health("unreachable")]), null);
});

test("a check nobody has run is stale", () => {
    // Not a detail: a banner reading "working" on the strength of a check
    // that never happened is the reassurance somebody acts on.
    assert.equal(isStale(null), true);
    assert.equal(isStale("not a date"), true);
});

test("a check from three hours ago means the scheduler stopped", () => {
    const now = new Date("2026-09-23T13:30:00Z");

    assert.equal(isStale("2026-09-23T13:00:00Z", now), false);
    assert.equal(isStale("2026-09-23T10:00:00Z", now), true);
});
