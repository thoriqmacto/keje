import assert from "node:assert/strict";
import { test } from "node:test";
import {
    DEFAULT_ORDER,
    DEFAULT_PREFERENCES,
    MAX_COLUMN_WIDTH,
    MIN_COLUMN_WIDTH,
    type ColumnId,
    clampWidth,
    loadPreferences,
    minWidthFor,
    moveColumn,
    normalizePreferences,
    savePreferences,
    setWidth,
    toggleColumn,
    visibleColumns,
} from "./table-preferences.ts";

/** A localStorage stand-in, so these tests need no DOM. */
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

/** Storage that throws, as it does in some privacy modes. */
const hostileStorage = new Proxy({} as Storage, {
    get() {
        throw new Error("SecurityError: access denied");
    },
});

// ── Reordering ──────────────────────────────────────────────────────────────

test("dragging a column to the right lands after the target", () => {
    const order: ColumnId[] = ["working_title", "topic", "speaker", "render"];

    assert.deepEqual(moveColumn(order, "topic", "render"), [
        "working_title",
        "speaker",
        "render",
        "topic",
    ]);
});

test("dragging a column to the left lands before the target", () => {
    const order: ColumnId[] = ["working_title", "topic", "speaker", "render"];

    assert.deepEqual(moveColumn(order, "render", "topic"), [
        "working_title",
        "render",
        "topic",
        "speaker",
    ]);
});

test("the index is recomputed after the removal", () => {
    // The classic off-by-one: an index taken before the column is lifted out
    // means something different once it has been. Moving a column one place
    // right is where that shows up.
    const order: ColumnId[] = ["a", "b", "c"] as unknown as ColumnId[];

    assert.deepEqual(moveColumn(order, "a" as ColumnId, "b" as ColumnId), [
        "b",
        "a",
        "c",
    ]);
});

test("dropping a column on itself changes nothing", () => {
    const order: ColumnId[] = ["working_title", "topic"];

    assert.deepEqual(moveColumn(order, "topic", "topic"), order);
});

test("a move naming an unknown column is ignored", () => {
    const order: ColumnId[] = ["working_title", "topic"];

    assert.deepEqual(moveColumn(order, "nope" as ColumnId, "topic"), order);
});

test("no column is lost or duplicated by a move", () => {
    let order = [...DEFAULT_ORDER];

    order = moveColumn(order, "youtube", "topic");
    order = moveColumn(order, "working_title", "actions");
    order = moveColumn(order, "speaker", "render");

    assert.equal(order.length, DEFAULT_ORDER.length);
    assert.equal(new Set(order).size, DEFAULT_ORDER.length);
});

// ── Visibility ──────────────────────────────────────────────────────────────

test("a column can be hidden and shown again", () => {
    const hidden = toggleColumn([], "topic");
    assert.deepEqual(hidden, ["topic"]);
    assert.deepEqual(toggleColumn(hidden, "topic"), []);
});

test("the title and the actions cannot be hidden", () => {
    // A table with no title is a grid of statuses belonging to nothing, and a
    // row with no way to open it is a dead end.
    assert.deepEqual(toggleColumn([], "working_title"), []);
    assert.deepEqual(toggleColumn([], "actions"), []);
});

test("visible columns respect order and visibility together", () => {
    const preferences = {
        ...DEFAULT_PREFERENCES,
        order: ["topic", "working_title", "speaker"] as ColumnId[],
        hidden: ["speaker"] as ColumnId[],
    };

    assert.deepEqual(visibleColumns(preferences), ["topic", "working_title"]);
});

// ── Widths ──────────────────────────────────────────────────────────────────

test("a width cannot be dragged to nothing or to absurdity", () => {
    assert.equal(clampWidth(0), MIN_COLUMN_WIDTH);
    assert.equal(clampWidth(-40), MIN_COLUMN_WIDTH);
    assert.equal(clampWidth(99999), MAX_COLUMN_WIDTH);
});

test("a width is stored rounded and clamped", () => {
    assert.equal(setWidth({}, "topic", 180.6).topic, 181);
    assert.equal(setWidth({}, "topic", 5).topic, MIN_COLUMN_WIDTH);
});

// ── Reconciling stored preferences ──────────────────────────────────────────

test("nothing stored yields the defaults", () => {
    assert.deepEqual(normalizePreferences(null), DEFAULT_PREFERENCES);
});

test("a column added since the layout was saved appears at the end", () => {
    // The first deploy after a new column ships. Dropping it would leave a
    // column that exists but can never be shown.
    const preferences = normalizePreferences({
        order: ["working_title", "topic"],
        hidden: [],
        widths: {},
        density: "compact",
    });

    assert.equal(preferences.order[0], "working_title");
    assert.equal(preferences.order.length, DEFAULT_ORDER.length);
    assert.equal(new Set(preferences.order).size, DEFAULT_ORDER.length);
});

test("a column removed since the layout was saved is dropped", () => {
    const preferences = normalizePreferences({
        order: ["working_title", "a_column_that_no_longer_exists", "topic"],
    });

    assert.equal(preferences.order.includes("a_column_that_no_longer_exists" as ColumnId), false);
});

test("a stored layout cannot hide a required column", () => {
    // Hand-edited storage, or a saved layout from before the column became
    // required. Either way the table has to stay usable.
    const preferences = normalizePreferences({ hidden: ["working_title", "topic"] });

    assert.deepEqual(preferences.hidden, ["topic"]);
});

test("a stored width outside the bounds is clamped rather than trusted", () => {
    const preferences = normalizePreferences({ widths: { topic: 99999, speaker: 0 } });

    assert.equal(preferences.widths.topic, MAX_COLUMN_WIDTH);
    assert.equal(preferences.widths.speaker, MIN_COLUMN_WIDTH);
});

test("garbage in storage falls back to the defaults", () => {
    assert.deepEqual(normalizePreferences("not an object"), DEFAULT_PREFERENCES);
    assert.deepEqual(normalizePreferences({ order: "nonsense", hidden: 42 }), {
        ...DEFAULT_PREFERENCES,
        // hidden was not an array, so the default hidden set applies.
        hidden: DEFAULT_PREFERENCES.hidden,
    });
});

// ── Persistence ─────────────────────────────────────────────────────────────

test("preferences survive a round trip through storage", () => {
    const storage = fakeStorage();
    const preferences = {
        ...DEFAULT_PREFERENCES,
        density: "compact" as const,
        hidden: ["audio_duration"] as ColumnId[],
    };

    savePreferences(preferences, storage);

    const loaded = loadPreferences(storage);
    assert.equal(loaded.density, "compact");
    assert.deepEqual(loaded.hidden, ["audio_duration"]);
});

test("unreadable storage yields the defaults instead of throwing", () => {
    // Private windows and blocked site data throw on access. A table that
    // refuses to render because it could not remember a column width would be
    // a poor trade.
    assert.deepEqual(loadPreferences(hostileStorage), DEFAULT_PREFERENCES);
});

test("unwritable storage does not throw out of a drag handler", () => {
    assert.doesNotThrow(() => savePreferences(DEFAULT_PREFERENCES, hostileStorage));
});

test("corrupt JSON in storage yields the defaults", () => {
    const storage = fakeStorage({ "keje:studio-table:v1": "{ not json" });

    assert.deepEqual(loadPreferences(storage), DEFAULT_PREFERENCES);
});

// ── Per-column minimum widths ───────────────────────────────────────────────

test("a column of controls has a higher floor than a column of text", () => {
    // Dragging a text column narrow is merely hard to read. Dragging the
    // actions column narrow loses the buttons, with nothing to say they exist.
    assert.equal(clampWidth(20, "topic"), MIN_COLUMN_WIDTH);
    assert.equal(clampWidth(20, "actions"), minWidthFor("actions"));
    assert.ok(minWidthFor("actions") > MIN_COLUMN_WIDTH);
});

test("a layout saved when actions held one button widens itself", () => {
    // The upgrade path: stored widths are re-clamped on load, so nobody has to
    // notice a clipped button and drag the column themselves.
    const stored = normalizePreferences({ widths: { actions: 90 } });

    assert.equal(stored.widths.actions, minWidthFor("actions"));
});

test("a width above the floor is left alone", () => {
    assert.equal(normalizePreferences({ widths: { actions: 200 } }).widths.actions, 200);
});

test("resizing obeys the same floor as loading", () => {
    assert.equal(setWidth({}, "actions", 30).actions, minWidthFor("actions"));
});
