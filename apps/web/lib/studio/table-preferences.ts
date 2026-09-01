/**
 * How the table is arranged, as opposed to which rows it shows.
 *
 * The split is deliberate and load-bearing. Which rows am I looking at? goes
 * in the URL, so a filtered view can be refreshed, shared and returned to with
 * the back button. How do I want my columns? is personal to one browser and
 * belongs nowhere near a link — nobody wants to send a colleague their column
 * widths, and a URL carrying them would be unreadable.
 *
 * Everything here is a pure function over a plain object, so the reordering
 * and resizing rules can be tested directly rather than through simulated
 * pointer events, which are brittle and test the browser more than the code.
 *
 * Nothing sensitive is stored: column ids, booleans and pixel widths. No token,
 * no project content, no Google data.
 */

export type ColumnId =
    | "working_title"
    | "topic"
    | "topic_sequence"
    | "speaker"
    | "render"
    | "drive"
    | "youtube"
    | "updated_at"
    | "created_at"
    | "audio_duration"
    | "actions";

export type Density = "compact" | "comfortable";

export type TablePreferences = {
    order: ColumnId[];
    hidden: ColumnId[];
    widths: Partial<Record<ColumnId, number>>;
    density: Density;
};

/**
 * The default layout: identity first, then grouping, then the pipelines.
 *
 * Ordered so the fields somebody scans for are left of where horizontal
 * scrolling begins. Created and Audio come last and start hidden — useful when
 * wanted, clutter when not.
 */
export const DEFAULT_ORDER: ColumnId[] = [
    "working_title",
    "topic",
    "topic_sequence",
    "speaker",
    "render",
    "drive",
    "youtube",
    "updated_at",
    "audio_duration",
    "created_at",
    "actions",
];

export const DEFAULT_HIDDEN: ColumnId[] = ["created_at"];

/**
 * Columns that cannot be hidden.
 *
 * A table with no title is a grid of statuses belonging to nothing, and a row
 * with no way to open it is a dead end. Neither is a preference worth
 * respecting, so the menu does not offer them.
 */
export const REQUIRED_COLUMNS: ColumnId[] = ["working_title", "actions"];

/** Wide enough to read, narrow enough not to be absurd. */
export const MIN_COLUMN_WIDTH = 64;
export const MAX_COLUMN_WIDTH = 640;

export const DEFAULT_WIDTHS: Partial<Record<ColumnId, number>> = {
    working_title: 280,
    topic: 180,
    topic_sequence: 80,
    speaker: 160,
    render: 150,
    drive: 120,
    youtube: 170,
    updated_at: 150,
    audio_duration: 110,
    created_at: 150,
    actions: 90,
};

export const DEFAULT_PREFERENCES: TablePreferences = {
    order: DEFAULT_ORDER,
    hidden: DEFAULT_HIDDEN,
    widths: DEFAULT_WIDTHS,
    density: "comfortable",
};

/**
 * Versioned, so a future layout change falls back instead of misreading.
 *
 * Bumping this discards saved layouts, which is the right trade: a stored
 * order naming columns that no longer exist would render a broken table, and
 * the cost of losing a column arrangement is one drag.
 */
export const PREFERENCES_KEY = "keje:studio-table:v1";

/**
 * Reconcile stored preferences with the columns that exist today.
 *
 * Never trusts what it reads. A saved order can name a column that has since
 * been removed, or be missing one that has since been added — both happen the
 * first time somebody deploys after a column change, and neither should
 * produce a table with missing or duplicated columns.
 */
export function normalizePreferences(
    stored: unknown,
    known: ColumnId[] = DEFAULT_ORDER,
): TablePreferences {
    const raw = (stored ?? {}) as Partial<TablePreferences>;
    const isKnown = (id: unknown): id is ColumnId => known.includes(id as ColumnId);

    const savedOrder = Array.isArray(raw.order) ? raw.order.filter(isKnown) : [];
    // Anything the saved order does not mention is appended in its default
    // position rather than dropped.
    const order = [...savedOrder, ...known.filter((id) => !savedOrder.includes(id))];

    const hidden = (Array.isArray(raw.hidden) ? raw.hidden.filter(isKnown) : DEFAULT_HIDDEN)
        .filter((id) => !REQUIRED_COLUMNS.includes(id));

    const widths: Partial<Record<ColumnId, number>> = { ...DEFAULT_WIDTHS };

    for (const [id, width] of Object.entries(raw.widths ?? {})) {
        if (isKnown(id) && typeof width === "number" && Number.isFinite(width)) {
            widths[id] = clampWidth(width);
        }
    }

    return {
        order,
        hidden,
        widths,
        density: raw.density === "compact" ? "compact" : "comfortable",
    };
}

export function clampWidth(width: number): number {
    return Math.min(MAX_COLUMN_WIDTH, Math.max(MIN_COLUMN_WIDTH, Math.round(width)));
}

/**
 * Move a column to sit where another one currently is.
 *
 * Expressed as "drop A onto B" rather than "move A to index 3" because that is
 * what a drag actually is, and because an index computed before the removal
 * means something different after it — the classic off-by-one in every
 * hand-rolled reorder.
 */
export function moveColumn(
    order: ColumnId[],
    dragged: ColumnId,
    target: ColumnId,
): ColumnId[] {
    if (dragged === target) return order;

    const from = order.indexOf(dragged);
    const to = order.indexOf(target);

    if (from === -1 || to === -1) return order;

    const next = [...order];
    next.splice(from, 1);
    // Recomputed after the removal, which is the whole point.
    next.splice(next.indexOf(target) + (to > from ? 1 : 0), 0, dragged);

    return next;
}

/** Toggle a column's visibility, refusing to hide the ones a table needs. */
export function toggleColumn(hidden: ColumnId[], id: ColumnId): ColumnId[] {
    if (REQUIRED_COLUMNS.includes(id)) return hidden;

    return hidden.includes(id) ? hidden.filter((c) => c !== id) : [...hidden, id];
}

export function isVisible(preferences: TablePreferences, id: ColumnId): boolean {
    return !preferences.hidden.includes(id);
}

/** The columns to render, in order, honouring both order and visibility. */
export function visibleColumns(preferences: TablePreferences): ColumnId[] {
    return preferences.order.filter((id) => !preferences.hidden.includes(id));
}

export function setWidth(
    widths: Partial<Record<ColumnId, number>>,
    id: ColumnId,
    width: number,
): Partial<Record<ColumnId, number>> {
    return { ...widths, [id]: clampWidth(width) };
}

/**
 * Read preferences from browser storage, falling back cleanly.
 *
 * Every access is guarded: storage throws outright in some privacy modes, and
 * a table that refuses to render because it could not remember a column width
 * would be a poor trade.
 */
export function loadPreferences(storage?: Storage): TablePreferences {
    try {
        const store = storage ?? window.localStorage;
        const raw = store.getItem(PREFERENCES_KEY);

        return normalizePreferences(raw === null ? null : JSON.parse(raw));
    } catch {
        return DEFAULT_PREFERENCES;
    }
}

export function savePreferences(preferences: TablePreferences, storage?: Storage): void {
    try {
        const store = storage ?? window.localStorage;
        store.setItem(PREFERENCES_KEY, JSON.stringify(preferences));
    } catch {
        // A layout that cannot be remembered is a small loss; an exception
        // thrown from a drag handler is not.
    }
}

export function clearPreferences(storage?: Storage): void {
    try {
        const store = storage ?? window.localStorage;
        store.removeItem(PREFERENCES_KEY);
    } catch {
        // As above.
    }
}
