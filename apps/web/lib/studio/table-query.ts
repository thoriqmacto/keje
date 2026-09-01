/**
 * The Studio table's dataset query, and its one true serialisation.
 *
 * Which rows am I looking at? — that question lives in the URL, so a filtered
 * view survives a refresh, the back button and being pasted to somebody else.
 * How do I want the table arranged? is a different question with a different
 * home; see table-preferences.
 *
 * Everything here is pure. Components read and write `StudioProjectQuery`
 * objects and never touch a query string, because the moment two call sites
 * assemble URLs by concatenation they disagree about defaults, and the bug is
 * a filter that silently stops applying.
 */

export type SortDirection = "asc" | "desc";

/**
 * Columns the server will sort by. Mirrors the backend allow-list — anything
 * else is ignored there rather than reaching SQL.
 */
export const SORT_KEYS = [
    "working_title",
    "topic",
    "topic_sequence",
    "speaker",
    "audio_duration",
    "render_status",
    "drive_status",
    "youtube_status",
    "created_at",
    "updated_at",
] as const;

export type StudioSortKey = (typeof SORT_KEYS)[number];

export const PAGE_SIZES = [10, 25, 50, 100] as const;

export type PageSize = (typeof PAGE_SIZES)[number];

export const DEFAULT_SORT: StudioSortKey = "updated_at";
export const DEFAULT_DIRECTION: SortDirection = "desc";
export const DEFAULT_PAGE_SIZE: PageSize = 25;

export type StudioProjectQuery = {
    page: number;
    perPage: PageSize;
    sort: StudioSortKey;
    direction: SortDirection;
    search: string;
    topic: string | null;
    /** A speaker UUID, or the literal "none" for unattributed projects. */
    speaker: string | null;
    renderStatus: string | null;
    driveStatus: string | null;
    youtubeStatus: string | null;

    /**
     * A column filter on the title alone.
     *
     * Deliberately not the same thing as `search`, which also covers the
     * topic, the speaker and the drawn titles. Somebody filtering the Working
     * title column means that column — a match on a speaker's name would look
     * like a bug.
     */
    workingTitle: string;

    /** A relative window: "today", "7d", "30d". Absolute dates are overkill here. */
    updatedWithin: string | null;
};

export const DEFAULT_QUERY: StudioProjectQuery = {
    page: 1,
    perPage: DEFAULT_PAGE_SIZE,
    sort: DEFAULT_SORT,
    direction: DEFAULT_DIRECTION,
    search: "",
    topic: null,
    speaker: null,
    renderStatus: null,
    driveStatus: null,
    youtubeStatus: null,
    workingTitle: "",
    updatedWithin: null,
};

/** The relative windows the Updated column offers. */
export const UPDATED_WINDOWS = [
    { value: "today", label: "Today" },
    { value: "7d", label: "Last 7 days" },
    { value: "30d", label: "Last 30 days" },
] as const;

/** The filter keys, so "is anything filtered" is asked in one place. */
const FILTER_KEYS = [
    "topic",
    "speaker",
    "renderStatus",
    "driveStatus",
    "youtubeStatus",
    "updatedWithin",
] as const;

/**
 * Read a query out of URL parameters.
 *
 * Forgiving by design: a hand-edited or stale URL should still show somebody
 * their projects. Anything unrecognised falls back to its default rather than
 * throwing, which matches how the server treats the same input.
 */
export function parseQuery(params: URLSearchParams): StudioProjectQuery {
    const page = Number.parseInt(params.get("page") ?? "", 10);
    const perPage = Number.parseInt(params.get("per_page") ?? "", 10);
    const sort = params.get("sort");
    const direction = params.get("direction");

    return {
        page: Number.isFinite(page) && page > 0 ? page : 1,
        perPage: (PAGE_SIZES as readonly number[]).includes(perPage)
            ? (perPage as PageSize)
            : DEFAULT_PAGE_SIZE,
        sort: (SORT_KEYS as readonly string[]).includes(sort ?? "")
            ? (sort as StudioSortKey)
            : DEFAULT_SORT,
        direction: direction === "asc" ? "asc" : DEFAULT_DIRECTION,
        search: params.get("q") ?? "",
        topic: params.get("topic") || null,
        speaker: params.get("speaker") || null,
        renderStatus: params.get("render_status") || null,
        driveStatus: params.get("drive_status") || null,
        youtubeStatus: params.get("youtube_status") || null,
        workingTitle: params.get("working_title") ?? "",
        updatedWithin: params.get("updated_within") || null,
    };
}

/**
 * Write a query back to URL parameters, omitting anything at its default.
 *
 * A default-valued parameter in the URL is noise: it makes a plain view look
 * like a configured one, and it makes two identical views produce two
 * different links. `/studio` should stay `/studio` until something is
 * actually chosen.
 */
export function serializeQuery(query: StudioProjectQuery): URLSearchParams {
    const params = new URLSearchParams();

    if (query.page > 1) params.set("page", String(query.page));
    if (query.perPage !== DEFAULT_PAGE_SIZE) params.set("per_page", String(query.perPage));
    if (query.sort !== DEFAULT_SORT) params.set("sort", query.sort);
    if (query.direction !== DEFAULT_DIRECTION) params.set("direction", query.direction);
    if (query.search.trim() !== "") params.set("q", query.search.trim());
    if (query.topic) params.set("topic", query.topic);
    if (query.speaker) params.set("speaker", query.speaker);
    if (query.renderStatus) params.set("render_status", query.renderStatus);
    if (query.driveStatus) params.set("drive_status", query.driveStatus);
    if (query.youtubeStatus) params.set("youtube_status", query.youtubeStatus);
    if (query.workingTitle.trim() !== "") params.set("working_title", query.workingTitle.trim());
    if (query.updatedWithin) params.set("updated_within", query.updatedWithin);

    return params;
}

/**
 * The parameters actually sent to the API.
 *
 * Distinct from serializeQuery because the two have different jobs: the URL
 * elides defaults for legibility, while the request states them so the server
 * never has to guess what an omitted value meant.
 */
export function toRequestParams(query: StudioProjectQuery): Record<string, string> {
    const params: Record<string, string> = {
        page: String(query.page),
        per_page: String(query.perPage),
        sort: query.sort,
        direction: query.direction,
    };

    if (query.search.trim() !== "") params.q = query.search.trim();
    if (query.topic) params.topic = query.topic;
    if (query.speaker) params.speaker = query.speaker;
    if (query.renderStatus) params.render_status = query.renderStatus;
    if (query.driveStatus) params.drive_status = query.driveStatus;
    if (query.youtubeStatus) params.youtube_status = query.youtubeStatus;
    if (query.workingTitle.trim() !== "") params.working_title = query.workingTitle.trim();
    if (query.updatedWithin) params.updated_within = query.updatedWithin;

    return params;
}

/**
 * Change part of a query, resetting the page unless the page is what changed.
 *
 * The rule that stops the most common data-table bug: narrowing a filter while
 * on page four leaves you looking at page four of a three-page result, which
 * renders as an empty table and reads as "no matches".
 */
export function updateQuery(
    query: StudioProjectQuery,
    patch: Partial<StudioProjectQuery>,
): StudioProjectQuery {
    const next = { ...query, ...patch };
    const onlyPaging = Object.keys(patch).every((key) => key === "page");

    return onlyPaging ? next : { ...next, page: 1 };
}

/**
 * Cycle a column's sort: ascending, descending, then back to the default.
 *
 * The third state matters. Without it there is no way back to "newest first"
 * once a column has been clicked, short of reloading the page — and the
 * default ordering is the one people want most of the time.
 */
export function toggleSort(query: StudioProjectQuery, key: StudioSortKey): StudioProjectQuery {
    if (query.sort !== key) {
        return updateQuery(query, { sort: key, direction: "asc" });
    }

    if (query.direction === "asc") {
        return updateQuery(query, { direction: "desc" });
    }

    return updateQuery(query, { sort: DEFAULT_SORT, direction: DEFAULT_DIRECTION });
}

/** Whether anything narrows the dataset — filters or search, not sorting. */
export function hasActiveFilters(query: StudioProjectQuery): boolean {
    return (
        query.search.trim() !== ""
        || query.workingTitle.trim() !== ""
        || FILTER_KEYS.some((key) => query[key] !== null)
    );
}

/** Drop every filter and the search, keeping the sort and page size. */
export function clearFilters(query: StudioProjectQuery): StudioProjectQuery {
    return updateQuery(query, {
        search: "",
        workingTitle: "",
        topic: null,
        speaker: null,
        renderStatus: null,
        driveStatus: null,
        youtubeStatus: null,
        updatedWithin: null,
    });
}

/** Clamp a page number to a result set that may have shrunk under it. */
export function clampPage(query: StudioProjectQuery, lastPage: number): StudioProjectQuery {
    if (lastPage < 1 || query.page <= lastPage) {
        return query;
    }

    return { ...query, page: lastPage };
}

/** "Sorted by Updated, newest first" — the ordering, said out loud. */
export function describeSort(query: StudioProjectQuery, label: string): string {
    // Dates read as recency; everything else reads alphabetically or
    // numerically, and "newest first" would be wrong for a title.
    const isDate = query.sort === "updated_at" || query.sort === "created_at";

    const suffix = isDate
        ? query.direction === "desc"
            ? "newest first"
            : "oldest first"
        : query.direction === "desc"
          ? "descending"
          : "ascending";

    return `Sorted by ${label}, ${suffix}`;
}
