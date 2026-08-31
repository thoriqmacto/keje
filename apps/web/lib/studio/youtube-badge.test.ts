import assert from "node:assert/strict";
import { test } from "node:test";
import { youtubeBadgeLabel, youtubeBadgeStatus } from "./youtube-badge.ts";

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
