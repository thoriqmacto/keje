import assert from "node:assert/strict";
import { test } from "node:test";
import {
    SAVED_VIEW_KEY,
    clearSavedView,
    loadSavedView,
    normalizeSavedView,
    resolveInitialQuery,
    saveView,
    viewFromQuery,
    viewMatchesQuery,
} from "./saved-view.ts";
import { DEFAULT_QUERY, serializeQuery } from "./table-query.ts";

function fakeStorage(initial: Record<string, string> = {}): Storage {
    const data = new Map(Object.entries(initial));

    return {
        get length() {
            return data.size;
        },
        clear: () => data.clear(),
        getItem: (key: string) => data.get(key) ?? null,
        key: (index: number) => [...data.keys()][index] ?? null,
        removeItem: (key: string) => void data.delete(key),
        setItem: (key: string, value: string) => void data.set(key, value),
    } as Storage;
}

const preferred = viewFromQuery({
    ...DEFAULT_QUERY,
    sort: "topic_sequence",
    direction: "asc",
    topic: "topic-uuid",
    youtubeStatus: "published",
});

// ── Precedence ──────────────────────────────────────────────────────────────

test("a plain /studio opens with the saved view", () => {
    const query = resolveInitialQuery(new URLSearchParams(""), preferred);

    assert.notEqual(query, null);
    assert.equal(query?.sort, "topic_sequence");
    assert.equal(query?.direction, "asc");
    assert.equal(query?.topic, "topic-uuid");
    assert.equal(query?.youtubeStatus, "published");
});

test("an explicit URL always wins over the saved view", () => {
    // The rule that makes saved views safe to have. A shared link has to show
    // what the sender saw, and the back button has to return to what was left;
    // a default that could override either would break both.
    const url = serializeQuery({ ...DEFAULT_QUERY, sort: "working_title", direction: "asc" });

    assert.equal(resolveInitialQuery(url, preferred), null);
});

test("even one URL parameter counts as explicit", () => {
    // Someone who filtered to Published and shared the link did not also mean
    // "and apply whatever defaults you have saved".
    assert.equal(
        resolveInitialQuery(new URLSearchParams("youtube_status=published"), preferred),
        null,
    );
});

test("with nothing saved, a plain /studio uses Keje's own default", () => {
    assert.equal(resolveInitialQuery(new URLSearchParams(""), null), null);
});

test("the saved view never restores a page number", () => {
    // A saved page describes a result set that has since changed size, and
    // "open on page four" is nobody's preference.
    const saved = viewFromQuery({ ...DEFAULT_QUERY, topic: "abc" });
    const query = resolveInitialQuery(new URLSearchParams(""), saved);

    assert.equal(query?.page, 1);
});

// ── Reconciliation ──────────────────────────────────────────────────────────

test("a saved sort key that no longer exists falls back to the default", () => {
    // The first release after a column is removed. Sending the old key would
    // either error or be dropped server-side, opening the page sorted by
    // something the user did not choose and cannot see.
    const view = normalizeSavedView({ sort: "a_column_that_was_removed", direction: "asc" });

    assert.equal(view?.sort, "updated_at");
});

test("a saved page size that is no longer offered falls back", () => {
    assert.equal(normalizeSavedView({ perPage: 7 })?.perPage, 25);
});

test("garbage in storage is not a saved view", () => {
    assert.equal(normalizeSavedView(null), null);
    assert.equal(normalizeSavedView("nonsense"), null);
});

test("partial stored data fills in from the defaults", () => {
    const view = normalizeSavedView({ topic: "abc" });

    assert.equal(view?.topic, "abc");
    assert.equal(view?.sort, "updated_at");
    assert.equal(view?.search, "");
});

// ── Round trip ──────────────────────────────────────────────────────────────

test("a saved view survives storage", () => {
    const storage = fakeStorage();
    saveView(preferred, storage);

    assert.deepEqual(loadSavedView(storage), preferred);
});

test("clearing removes it", () => {
    const storage = fakeStorage();
    saveView(preferred, storage);
    clearSavedView(storage);

    assert.equal(loadSavedView(storage), null);
});

test("corrupt stored JSON reads as no saved view", () => {
    assert.equal(loadSavedView(fakeStorage({ [SAVED_VIEW_KEY]: "{ not json" })), null);
});

test("unreadable storage reads as no saved view rather than throwing", () => {
    const hostile = new Proxy({} as Storage, {
        get() {
            throw new Error("SecurityError");
        },
    });

    assert.equal(loadSavedView(hostile), null);
    assert.doesNotThrow(() => saveView(preferred, hostile));
});

// ── Offering the action honestly ────────────────────────────────────────────

test("the current view is recognised as already saved", () => {
    const query = {
        ...DEFAULT_QUERY,
        sort: "topic_sequence" as const,
        direction: "asc" as const,
        topic: "topic-uuid",
        youtubeStatus: "published",
    };

    assert.equal(viewMatchesQuery(preferred, query), true);
});

test("a changed filter makes the current view differ from the saved one", () => {
    const query = {
        ...DEFAULT_QUERY,
        sort: "topic_sequence" as const,
        direction: "asc" as const,
        topic: "a-different-topic",
        youtubeStatus: "published",
    };

    assert.equal(viewMatchesQuery(preferred, query), false);
});

test("the page number is not part of what counts as a match", () => {
    // Paging is not a change to the view, so it must not make "save" light up.
    const query = {
        ...DEFAULT_QUERY,
        page: 4,
        sort: "topic_sequence" as const,
        direction: "asc" as const,
        topic: "topic-uuid",
        youtubeStatus: "published",
    };

    assert.equal(viewMatchesQuery(preferred, query), true);
});

test("nothing saved never matches", () => {
    assert.equal(viewMatchesQuery(null, DEFAULT_QUERY), false);
});
