/**
 * How a plain `/studio` should open, for this person.
 *
 * Three kinds of state now, and keeping them apart is the whole point:
 *
 *   the URL          which rows am I looking at *right now*. Shareable,
 *                    bookmarkable, restored by the back button.
 *
 *   the saved view   how should `/studio` open when I arrive with no URL of
 *                    my own. Personal. Never inferred — only ever set by
 *                    somebody pressing "save".
 *
 *   the table layout column order, widths, density. Also personal, also
 *                    stored locally, but about arrangement rather than data.
 *
 * They are separate keys rather than one settings blob because they answer
 * different questions and change at different times. A copied URL has to
 * reproduce its dataset on somebody else's browser; their columns stay theirs.
 *
 * ── Why saving is explicit ───────────────────────────────────────────────
 *
 * The tempting version writes every filter change straight to the default.
 * That makes filtering once — to find a single project — silently redefine
 * how the page opens forever, and there is no obvious way to undo it because
 * nothing announced that it happened. So the default only ever moves when
 * somebody says so.
 */

import {
    DEFAULT_QUERY,
    PAGE_SIZES,
    SORT_KEYS,
    type PageSize,
    type SortDirection,
    type StudioProjectQuery,
    type StudioSortKey,
} from "./table-query.ts";

/**
 * The parts of a query worth remembering.
 *
 * Not the page: "open on page four" is nobody's preference, and a saved page
 * number goes stale the moment the result set changes size.
 */
export type SavedView = {
    sort: StudioSortKey;
    direction: SortDirection;
    perPage: PageSize;
    search: string;
    topic: string | null;
    speaker: string | null;
    renderStatus: string | null;
    driveStatus: string | null;
    youtubeStatus: string | null;
    updatedWithin: string | null;
    workingTitle: string;
};

/** Versioned separately from the table layout: different shape, different life. */
export const SAVED_VIEW_KEY = "keje:studio-view:v1";

/** Everything the URL carries about the dataset, as a saveable view. */
export function viewFromQuery(query: StudioProjectQuery): SavedView {
    return {
        sort: query.sort,
        direction: query.direction,
        perPage: query.perPage,
        search: query.search,
        topic: query.topic,
        speaker: query.speaker,
        renderStatus: query.renderStatus,
        driveStatus: query.driveStatus,
        youtubeStatus: query.youtubeStatus,
        updatedWithin: query.updatedWithin,
        workingTitle: query.workingTitle,
    };
}

/**
 * Reconcile a stored view against what the server still supports.
 *
 * A sort key can be removed between releases, and a saved view naming one
 * would otherwise send an invalid request on every visit — or, worse, be
 * silently dropped by the server so the page opens sorted by something the
 * user did not choose and cannot see. Falling back to the application default
 * is the honest outcome.
 */
export function normalizeSavedView(stored: unknown): SavedView | null {
    if (stored === null || typeof stored !== "object") {
        return null;
    }

    const raw = stored as Partial<SavedView>;

    const sort = (SORT_KEYS as readonly string[]).includes(raw.sort ?? "")
        ? (raw.sort as StudioSortKey)
        : DEFAULT_QUERY.sort;

    const perPage = (PAGE_SIZES as readonly number[]).includes(raw.perPage ?? -1)
        ? (raw.perPage as PageSize)
        : DEFAULT_QUERY.perPage;

    return {
        sort,
        direction: raw.direction === "asc" ? "asc" : "desc",
        perPage,
        search: typeof raw.search === "string" ? raw.search : "",
        topic: typeof raw.topic === "string" ? raw.topic : null,
        speaker: typeof raw.speaker === "string" ? raw.speaker : null,
        renderStatus: typeof raw.renderStatus === "string" ? raw.renderStatus : null,
        driveStatus: typeof raw.driveStatus === "string" ? raw.driveStatus : null,
        youtubeStatus: typeof raw.youtubeStatus === "string" ? raw.youtubeStatus : null,
        updatedWithin: typeof raw.updatedWithin === "string" ? raw.updatedWithin : null,
        workingTitle: typeof raw.workingTitle === "string" ? raw.workingTitle : "",
    };
}

/**
 * Which query to open with.
 *
 * The precedence that makes saved views safe to have at all:
 *
 *   1. an explicit URL wins, always. A shared link must show what the sender
 *      saw, and the back button must return to what was left. A saved default
 *      that could override those would break both.
 *   2. otherwise the saved view, if there is one.
 *   3. otherwise Keje's own default.
 *
 * "Explicit" means the URL carries any dataset parameter at all — which is
 * exactly what serializeQuery produces for a non-default view, and exactly
 * what a plain `/studio` does not.
 */
export function resolveInitialQuery(
    params: URLSearchParams,
    saved: SavedView | null,
): StudioProjectQuery | null {
    if (params.toString() !== "" || saved === null) {
        return null;
    }

    return {
        ...DEFAULT_QUERY,
        ...saved,
        // Always the first page: a saved page number describes a result set
        // that has since changed size.
        page: 1,
    };
}

/** Whether a saved view differs from what is on screen, so "save" can be offered honestly. */
export function viewMatchesQuery(saved: SavedView | null, query: StudioProjectQuery): boolean {
    if (saved === null) {
        return false;
    }

    const current = viewFromQuery(query);

    return (Object.keys(current) as Array<keyof SavedView>).every(
        (key) => current[key] === saved[key],
    );
}

export function loadSavedView(storage?: Storage): SavedView | null {
    try {
        const store = storage ?? window.localStorage;
        const raw = store.getItem(SAVED_VIEW_KEY);

        return raw === null ? null : normalizeSavedView(JSON.parse(raw));
    } catch {
        // Private windows and blocked site data throw on access. A studio that
        // refused to open because it could not remember a filter would be a
        // poor trade.
        return null;
    }
}

export function saveView(view: SavedView, storage?: Storage): void {
    try {
        (storage ?? window.localStorage).setItem(SAVED_VIEW_KEY, JSON.stringify(view));
    } catch {
        // As above: a preference that cannot be remembered is a small loss.
    }
}

export function clearSavedView(storage?: Storage): void {
    try {
        (storage ?? window.localStorage).removeItem(SAVED_VIEW_KEY);
    } catch {
        // As above.
    }
}
