import assert from "node:assert/strict";
import { test } from "node:test";
import {
    EMPTY_TITLES,
    YOUTUBE_TITLE_LIMIT,
    remaining,
    setCustom,
    setSynced,
    setWorking,
    youtubeTitle,
    youtubeTitleForMetadata,
} from "./title-sync.ts";

test("one title fills both by default", () => {
    // The whole point: a new project needs its title typed once.
    const state = setWorking(EMPTY_TITLES, "Kajian Tematik #11");

    assert.equal(state.synced, true);
    assert.equal(youtubeTitle(state), "Kajian Tematik #11");
});

test("typing keeps the two together while synced", () => {
    let state = setWorking(EMPTY_TITLES, "Kajian");
    state = setWorking(state, "Kajian Tematik");

    assert.equal(youtubeTitle(state), "Kajian Tematik");
});

test("unticking seeds the YouTube field from what is on screen", () => {
    // Changing one word of the title is the common case; starting from an
    // empty box would mean retyping the sentence to alter it.
    const synced = setWorking(EMPTY_TITLES, "Keutamaan Lapar");
    const split = setSynced(synced, false);

    assert.equal(split.custom, "Keutamaan Lapar");
    assert.equal(youtubeTitle(split), "Keutamaan Lapar");
});

test("once split, the two titles move independently", () => {
    let state = setSynced(setWorking(EMPTY_TITLES, "Kajian #11"), false);
    state = setCustom(state, "Keutamaan Lapar | Kajian Tematik");
    state = setWorking(state, "Kajian #11 — Part 3");

    assert.equal(state.working, "Kajian #11 — Part 3");
    assert.equal(youtubeTitle(state), "Keutamaan Lapar | Kajian Tematik");
});

test("re-ticking the box is not destructive", () => {
    /*
     * The behaviour worth a test. Ticking the box shows the synced title, but
     * the typed one is kept aside — so somebody can look at what syncing
     * would do and change their mind without losing the sentence they wrote.
     */
    let state = setSynced(setWorking(EMPTY_TITLES, "Kajian #11"), false);
    state = setCustom(state, "A carefully written public title");

    const resynced = setSynced(state, true);
    assert.equal(youtubeTitle(resynced), "Kajian #11");

    const split = setSynced(resynced, false);
    assert.equal(youtubeTitle(split), "A carefully written public title");
});

test("unticking does not overwrite a title already typed", () => {
    let state = setSynced(setWorking(EMPTY_TITLES, "Working"), false);
    state = setCustom(state, "Public");
    state = setSynced(state, true);
    state = setSynced(state, false);

    assert.equal(state.custom, "Public");
});

// ── YouTube's limit ─────────────────────────────────────────────────────────

test("both fields stop at YouTube's limit", () => {
    // The working title obeys the stricter rule too, because while synced it
    // *is* the YouTube title — a longer one would make the checkbox a lie.
    const long = "x".repeat(150);

    assert.equal(setWorking(EMPTY_TITLES, long).working.length, YOUTUBE_TITLE_LIMIT);
    assert.equal(setCustom(EMPTY_TITLES, long).custom.length, YOUTUBE_TITLE_LIMIT);
});

test("a long paste lands truncated rather than being refused", () => {
    const state = setWorking(EMPTY_TITLES, "y".repeat(120));

    assert.equal(state.working, "y".repeat(YOUTUBE_TITLE_LIMIT));
});

test("the counter reports what is left", () => {
    assert.equal(remaining(""), 100);
    assert.equal(remaining("abc"), 97);
    assert.equal(remaining("z".repeat(100)), 0);
});

// ── What reaches the API ────────────────────────────────────────────────────

test("an empty title is sent as nothing rather than as a blank", () => {
    // Storing "" would make the upload send a blank title, where the
    // builder's fallback — the project's own naming — is what anybody expects.
    assert.equal(youtubeTitleForMetadata(EMPTY_TITLES), null);
    assert.equal(youtubeTitleForMetadata(setWorking(EMPTY_TITLES, "   ")), null);
});

test("the stored title is trimmed", () => {
    assert.equal(
        youtubeTitleForMetadata(setWorking(EMPTY_TITLES, "  Kajian Tematik  ")),
        "Kajian Tematik",
    );
});

test("a split title is what gets stored", () => {
    let state = setSynced(setWorking(EMPTY_TITLES, "Internal name"), false);
    state = setCustom(state, "Public name");

    assert.equal(youtubeTitleForMetadata(state), "Public name");
});
