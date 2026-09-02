import assert from "node:assert/strict";
import { test } from "node:test";
import { youtubeBadgeLabel, youtubeBadgeStatus, youtubeSchedule } from "./youtube-badge.ts";

test("what YouTube says now beats our frozen pipeline value", () => {
    assert.equal(
        youtubeBadgeLabel({ label: "Scheduled", remoteLabel: "Published" }),
        "Published",
    );
});

test("falls back to the pipeline label when Google has not been asked", () => {
    assert.equal(youtubeBadgeLabel({ label: "Uploaded", remoteLabel: null }), "Uploaded");
});

test("a replacement in flight outranks everything else", () => {
    assert.equal(
        youtubeBadgeLabel({ label: "Published", remoteLabel: "Published", isReplacing: true }),
        "Replacing…",
    );
});

test("a failed replacement keeps the published video's own headline", () => {
    // The case this function exists for. The workflow broke; the lecture is
    // still up and unchanged, and a bare "Failed" would send someone to check
    // a video that is perfectly fine.
    assert.equal(
        youtubeBadgeLabel({
            label: "Published",
            remoteLabel: "Published",
            isReplacing: true,
            replacementFailed: true,
            hasVideo: true,
        }),
        "Published · Replacement failed",
    );
});

test("with no video left, a failed replacement stands alone", () => {
    assert.equal(
        youtubeBadgeLabel({
            label: "Pending",
            isReplacing: true,
            replacementFailed: true,
            hasVideo: false,
        }),
        "Replacement failed",
    );
});

test("a replacement in flight takes the in-progress tone", () => {
    assert.equal(youtubeBadgeStatus({ label: "Published", isReplacing: true }, "published"), "uploading");
});

test("a failed replacement keeps the project's own tone, not danger", () => {
    // Colouring this red would report a working video as a broken one.
    assert.equal(
        youtubeBadgeStatus(
            { label: "Published", isReplacing: true, replacementFailed: true, hasVideo: true },
            "published",
        ),
        "published",
    );
});

// ── Which publish time to show, and what it promises ────────────────────────

const NOW = new Date("2026-09-10T12:00:00Z");

test("a confirmed schedule is shown as the plain date it always was", () => {
    const schedule = youtubeSchedule(
        { scheduledAt: "2026-09-12T19:00:00Z", plannedPublishAt: "2026-09-12T19:00:00Z" },
        NOW,
    );

    assert.equal(schedule?.at, "2026-09-12T19:00:00Z");
    assert.equal(schedule?.planned, false);
});

test("a queued project shows the schedule it intends to ask for", () => {
    // The whole point: a project waiting on its render used to show "Pending"
    // and nothing else, with its publication date decided days earlier.
    const schedule = youtubeSchedule(
        { scheduledAt: null, plannedPublishAt: "2026-09-12T19:00:00Z" },
        NOW,
    );

    assert.equal(schedule?.at, "2026-09-12T19:00:00Z");
    assert.equal(schedule?.planned, true);
    assert.equal(schedule?.overdue, false);
});

test("the confirmed schedule wins when both exist", () => {
    // Both are set for most of a scheduled video's life, and only one of them
    // is the time YouTube will actually act on.
    const schedule = youtubeSchedule(
        { scheduledAt: "2026-09-12T19:00:00Z", plannedPublishAt: "2026-10-01T09:00:00Z" },
        NOW,
    );

    assert.equal(schedule?.at, "2026-09-12T19:00:00Z");
    assert.equal(schedule?.planned, false);
});

test("a plan whose time has passed is marked overdue", () => {
    // The upload refuses a publish time in the past outright, so this is not
    // a date to look forward to — it is a project that cannot be uploaded.
    const schedule = youtubeSchedule(
        { scheduledAt: null, plannedPublishAt: "2026-09-01T19:00:00Z" },
        NOW,
    );

    assert.equal(schedule?.planned, true);
    assert.equal(schedule?.overdue, true);
});

test("a plan due exactly now counts as passed", () => {
    const schedule = youtubeSchedule(
        { scheduledAt: null, plannedPublishAt: NOW.toISOString() },
        NOW,
    );

    assert.equal(schedule?.overdue, true);
});

test("a confirmed schedule in the past is never marked overdue", () => {
    // YouTube holds it; what happened to it afterwards is the remote status's
    // question, and answering it here would change how every published video
    // in the list reads.
    const schedule = youtubeSchedule({ scheduledAt: "2026-09-01T19:00:00Z" }, NOW);

    assert.equal(schedule?.overdue, false);
});

test("no schedule of either kind shows nothing", () => {
    assert.equal(youtubeSchedule({ scheduledAt: null, plannedPublishAt: null }, NOW), null);
    assert.equal(youtubeSchedule({}, NOW), null);
});

test("an unparseable plan is dropped rather than rendered as Invalid Date", () => {
    assert.equal(youtubeSchedule({ plannedPublishAt: "not a date" }, NOW), null);
});
