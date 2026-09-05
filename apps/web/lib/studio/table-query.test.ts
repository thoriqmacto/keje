import assert from "node:assert/strict";
import { test } from "node:test";
import {
    DEFAULT_QUERY,
    clampPage,
    clearFilters,
    describeSort,
    hasActiveFilters,
    parseQuery,
    serializeQuery,
    toRequestParams,
    toggleSort,
    updateQuery,
} from "./table-query.ts";

const params = (search: string) => new URLSearchParams(search);

test("an empty URL is the default view", () => {
    assert.deepEqual(parseQuery(params("")), DEFAULT_QUERY);
});

test("the default view serialises to an empty URL", () => {
    // A plain /studio should stay /studio. Writing out every default makes an
    // unconfigured view look configured and gives two identical views two
    // different links.
    assert.equal(serializeQuery(DEFAULT_QUERY).toString(), "");
});

test("a full query round-trips through the URL", () => {
    const query = {
        ...DEFAULT_QUERY,
        page: 3,
        perPage: 50 as const,
        sort: "topic" as const,
        direction: "asc" as const,
        search: "lapar",
        topic: "topic-uuid",
        youtubeStatus: "published",
    };

    assert.deepEqual(parseQuery(serializeQuery(query)), query);
});

test("a hand-edited URL falls back rather than breaking", () => {
    // A stale bookmark naming a column that has since been renamed should
    // still show somebody their projects.
    const query = parseQuery(params("page=0&per_page=7&sort=nonsense&direction=sideways"));

    assert.equal(query.page, 1);
    assert.equal(query.perPage, 25);
    assert.equal(query.sort, "updated_at");
    assert.equal(query.direction, "desc");
});

test("the request states defaults the URL leaves out", () => {
    // Two different jobs: the URL elides defaults for legibility, the request
    // spells them out so the server never guesses what an omission meant.
    const sent = toRequestParams(DEFAULT_QUERY);

    assert.equal(sent.page, "1");
    assert.equal(sent.per_page, "25");
    assert.equal(sent.sort, "updated_at");
    assert.equal(sent.direction, "desc");
    assert.equal(sent.q, undefined);
});

test("changing a filter returns to page one", () => {
    // Narrowing while on page four otherwise leaves you looking at page four
    // of a three-page result, which renders empty and reads as "no matches".
    const query = { ...DEFAULT_QUERY, page: 4 };

    assert.equal(updateQuery(query, { youtubeStatus: "published" }).page, 1);
});

test("changing the page keeps the page", () => {
    const query = { ...DEFAULT_QUERY, search: "lapar" };
    const next = updateQuery(query, { page: 3 });

    assert.equal(next.page, 3);
    assert.equal(next.search, "lapar", "Paging must not drop the filters.");
});

test("changing the page size returns to page one", () => {
    const query = { ...DEFAULT_QUERY, page: 9 };

    assert.equal(updateQuery(query, { perPage: 100 }).page, 1);
});

test("sorting a new column starts ascending", () => {
    const query = toggleSort(DEFAULT_QUERY, "working_title");

    assert.equal(query.sort, "working_title");
    assert.equal(query.direction, "asc");
});

test("sorting cycles ascending, descending, then back to the default", () => {
    // The third state is the one that matters: without it there is no way back
    // to "newest first" once a column has been clicked.
    let query = toggleSort(DEFAULT_QUERY, "working_title");
    assert.equal(query.direction, "asc");

    query = toggleSort(query, "working_title");
    assert.equal(query.direction, "desc");

    query = toggleSort(query, "working_title");
    assert.equal(query.sort, "updated_at");
    assert.equal(query.direction, "desc");
});

test("sorting resets to page one", () => {
    assert.equal(toggleSort({ ...DEFAULT_QUERY, page: 5 }, "topic").page, 1);
});

test("sorting is not a filter", () => {
    // The distinction the toolbar depends on: a sorted view is not a narrowed
    // one, and offering "clear filters" for it would be misleading.
    assert.equal(hasActiveFilters(toggleSort(DEFAULT_QUERY, "topic")), false);
});

test("search and filters both count as active", () => {
    assert.equal(hasActiveFilters({ ...DEFAULT_QUERY, search: "lapar" }), true);
    assert.equal(hasActiveFilters({ ...DEFAULT_QUERY, speaker: "none" }), true);
});

test("clearing filters keeps the sort and page size", () => {
    const query = {
        ...DEFAULT_QUERY,
        page: 4,
        perPage: 50 as const,
        sort: "topic" as const,
        search: "lapar",
        topic: "abc",
    };

    const cleared = clearFilters(query);

    assert.equal(hasActiveFilters(cleared), false);
    assert.equal(cleared.page, 1);
    // How the table is ordered and how many rows it shows are not filters,
    // and clearing filters must not silently undo them.
    assert.equal(cleared.sort, "topic");
    assert.equal(cleared.perPage, 50);
});

test("a page beyond the result set is clamped to the last one", () => {
    // Deleting rows, or narrowing a filter elsewhere, can strand a reader past
    // the end. An empty table there looks like a broken filter.
    assert.equal(clampPage({ ...DEFAULT_QUERY, page: 9 }, 3).page, 3);
});

test("a page inside the result set is left alone", () => {
    assert.equal(clampPage({ ...DEFAULT_QUERY, page: 2 }, 3).page, 2);
});

test("an empty result set does not clamp to page zero", () => {
    assert.equal(clampPage({ ...DEFAULT_QUERY, page: 1 }, 0).page, 1);
});

test("dates are described by recency and everything else by direction", () => {
    assert.equal(describeSort(DEFAULT_QUERY, "Updated"), "Sorted by Updated, newest first");
    assert.equal(
        describeSort({ ...DEFAULT_QUERY, sort: "working_title", direction: "asc" }, "Working title"),
        "Sorted by Working title, ascending",
    );
});

test("the YouTube sort is described by its ends, not its direction", () => {
    // The server orders by how far along the lifecycle a project is. "YouTube,
    // ascending" describes the enum's spelling, which is not what happens.
    assert.equal(
        describeSort({ ...DEFAULT_QUERY, sort: "youtube_status", direction: "asc" }, "YouTube"),
        "Sorted by YouTube, not planned first",
    );
    assert.equal(
        describeSort({ ...DEFAULT_QUERY, sort: "youtube_status", direction: "desc" }, "YouTube"),
        "Sorted by YouTube, published first",
    );
});
