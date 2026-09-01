"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { Check, RotateCcw, Settings2, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { listSpeakers, listTopics, studioKeys } from "@/lib/studio/api";
import {
    REQUIRED_COLUMNS,
    type ColumnId,
    type Density,
    type TablePreferences,
} from "@/lib/studio/table-preferences";
import { viewMatchesQuery, type SavedView } from "@/lib/studio/saved-view";
import {
    clearFilters,
    hasActiveFilters,
    updateQuery,
    type StudioProjectQuery,
} from "@/lib/studio/table-query";

/** The filter options, kept beside the toolbar that draws them. */
/*
 * Shared with the per-column header filters, deliberately. The toolbar and the
 * header are two surfaces onto one query, and two lists of status options
 * would eventually offer different ones — the header would gain "Outdated"
 * and the toolbar would not, or a status added to the enum would appear in
 * one place only.
 */
export const RENDER_OPTIONS: [string, string][] = [
    ["draft", "Draft"],
    ["media_ready", "Media ready"],
    ["queued", "Queued"],
    ["rendering", "Rendering"],
    ["rendered", "Rendered"],
    // Not a render_status at all — a derived state, persisted so it can be
    // asked for in SQL. The most useful entry in this list.
    ["outdated", "Outdated"],
    ["failed", "Failed"],
];

export const DRIVE_OPTIONS: [string, string][] = [
    ["pending", "Pending"],
    ["uploading", "Uploading"],
    ["uploaded", "Uploaded"],
    ["failed", "Failed"],
];

export const YOUTUBE_OPTIONS: [string, string][] = [
    ["pending", "Not uploaded"],
    ["uploading", "Uploading"],
    ["scheduled", "Scheduled"],
    ["published", "Published"],
    ["private", "Private"],
    ["unlisted", "Unlisted"],
    ["replacing", "Replacing"],
    ["replacement_failed", "Replacement failed"],
    ["failed", "Failed"],
];

export const COLUMN_LABELS: Record<ColumnId, string> = {
    working_title: "Working title",
    topic: "Topic",
    topic_sequence: "TEMA",
    speaker: "Speaker",
    render: "Render",
    drive: "Drive",
    youtube: "YouTube",
    updated_at: "Updated",
    created_at: "Created",
    audio_duration: "Audio",
    actions: "Actions",
};

export function StudioTableToolbar({
    query,
    total,
    isValidating,
    preferences,
    onQueryChange,
    onToggleColumn,
    onDensityChange,
    onResetLayout,
    savedView,
    onSaveView,
    onRestoreView,
    onClearSavedView,
}: {
    query: StudioProjectQuery;
    total: number;
    isValidating: boolean;
    preferences: TablePreferences;
    onQueryChange: (next: StudioProjectQuery) => void;
    onToggleColumn: (column: ColumnId) => void;
    onDensityChange: (density: Density) => void;
    onResetLayout: () => void;
    /** The view a plain /studio opens with, or null if none is saved. */
    savedView: SavedView | null;
    onSaveView: () => void;
    onRestoreView: () => void;
    onClearSavedView: () => void;
}) {
    const { data: topics } = useSWR(studioKeys.topics, listTopics, { revalidateOnFocus: false });
    const { data: speakers } = useSWR(studioKeys.speakers, listSpeakers, {
        revalidateOnFocus: false,
    });

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <SearchBox query={query} onQueryChange={onQueryChange} />

                <FilterMenu
                    label="Topic"
                    value={query.topic}
                    options={(topics ?? []).map((topic) => [topic.id, topic.name])}
                    onChange={(topic) => onQueryChange(updateQuery(query, { topic }))}
                />

                <FilterMenu
                    label="Speaker"
                    value={query.speaker}
                    // A first-class filter, not a missing value: "which of
                    // these have I forgotten to attribute" is a real question.
                    options={[
                        ["none", "No speaker"],
                        ...(speakers ?? []).map(
                            (speaker) => [speaker.id, speaker.name] as [string, string],
                        ),
                    ]}
                    onChange={(speaker) => onQueryChange(updateQuery(query, { speaker }))}
                />

                <FilterMenu
                    label="Render"
                    value={query.renderStatus}
                    options={RENDER_OPTIONS}
                    onChange={(renderStatus) => onQueryChange(updateQuery(query, { renderStatus }))}
                />

                <FilterMenu
                    label="Drive"
                    value={query.driveStatus}
                    options={DRIVE_OPTIONS}
                    onChange={(driveStatus) => onQueryChange(updateQuery(query, { driveStatus }))}
                />

                <FilterMenu
                    label="YouTube"
                    value={query.youtubeStatus}
                    options={YOUTUBE_OPTIONS}
                    onChange={(youtubeStatus) =>
                        onQueryChange(updateQuery(query, { youtubeStatus }))
                    }
                />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground" aria-live="polite">
                    {/* Only the filtered total. Reporting "23 of 247" would
                        mean a second count of the whole table on every
                        keystroke, to say something nobody acts on. */}
                    {hasActiveFilters(query)
                        ? `${total} matching ${total === 1 ? "project" : "projects"}`
                        : `${total} ${total === 1 ? "project" : "projects"}`}
                    {isValidating && <span className="ml-2 opacity-60">Updating…</span>}
                </p>

                <div className="flex items-center gap-2">
                    <ViewMenu
                    isCurrentSaved={viewMatchesQuery(savedView, query)}
                    hasSavedView={savedView !== null}
                    onSave={onSaveView}
                    onRestore={onRestoreView}
                    onClear={onClearSavedView}
                />

                <ColumnsMenu preferences={preferences} onToggleColumn={onToggleColumn} />
                    <DensityMenu
                        density={preferences.density}
                        onDensityChange={onDensityChange}
                        onResetLayout={onResetLayout}
                    />
                </div>
            </div>

            <ActiveFilters
                query={query}
                topics={topics ?? []}
                speakers={speakers ?? []}
                onQueryChange={onQueryChange}
            />
        </div>
    );
}

/**
 * Search, debounced.
 *
 * Local state so typing stays responsive, and a timer so a five-letter word
 * costs one request rather than five. The URL is only written when the typing
 * pauses — otherwise every keystroke would become a history entry and the back
 * button would walk backwards through the word letter by letter.
 */
function SearchBox({
    query,
    onQueryChange,
}: {
    query: StudioProjectQuery;
    onQueryChange: (next: StudioProjectQuery) => void;
}) {
    const [value, setValue] = useState(query.search);

    // Adopt changes that came from elsewhere — a cleared chip, the back
    // button — without fighting what is being typed.
    useEffect(() => {
        setValue(query.search);
    }, [query.search]);

    useEffect(() => {
        if (value === query.search) return;

        const timer = setTimeout(() => {
            onQueryChange(updateQuery(query, { search: value }));
        }, 350);

        return () => clearTimeout(timer);
    }, [value, query, onQueryChange]);

    return (
        <Input
            type="search"
            value={value}
            aria-label="Search content"
            placeholder="Search content…"
            className="h-9 w-full sm:w-64"
            onChange={(event) => setValue(event.target.value)}
            onKeyDown={(event) => {
                if (event.key === "Escape") setValue("");
            }}
        />
    );
}

/** A single-select filter. "All" clears it rather than being a value. */
function FilterMenu({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string | null;
    options: [string, string][];
    onChange: (value: string | null) => void;
}) {
    const selected = options.find(([id]) => id === value);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="h-9">
                    {label}
                    {selected && (
                        <span className="ml-1 rounded bg-primary/10 px-1 text-xs">
                            {selected[1]}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="max-h-80 overflow-y-auto">
                <DropdownMenuItem onSelect={() => onChange(null)}>
                    All {label.toLowerCase()}s
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                {options.map(([id, name]) => (
                    <DropdownMenuItem key={id} onSelect={() => onChange(id)}>
                        <span className="flex-1">{name}</span>
                        {id === value && <Check aria-hidden className="size-3" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ColumnsMenu({
    preferences,
    onToggleColumn,
}: {
    preferences: TablePreferences;
    onToggleColumn: (column: ColumnId) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="h-8">
                    <Settings2 aria-hidden className="size-3" />
                    Columns
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel>Visible columns</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {preferences.order.map((column) => (
                    <DropdownMenuCheckboxItem
                        key={column}
                        checked={!preferences.hidden.includes(column)}
                        // A table with no title, or no way to open a row, is
                        // not a preference worth respecting.
                        disabled={REQUIRED_COLUMNS.includes(column)}
                        onSelect={(event) => {
                            event.preventDefault();
                            onToggleColumn(column);
                        }}
                    >
                        {COLUMN_LABELS[column]}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function DensityMenu({
    density,
    onDensityChange,
    onResetLayout,
}: {
    density: Density;
    onDensityChange: (density: Density) => void;
    onResetLayout: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="h-8">
                    Density
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuCheckboxItem
                    checked={density === "comfortable"}
                    onSelect={() => onDensityChange("comfortable")}
                >
                    Comfortable
                </DropdownMenuCheckboxItem>
                <DropdownMenuCheckboxItem
                    checked={density === "compact"}
                    onSelect={() => onDensityChange("compact")}
                >
                    Compact
                </DropdownMenuCheckboxItem>
                <DropdownMenuSeparator />
                {/* Deliberately not "reset everything": clearing filters and
                    resetting the layout are different intentions, and one
                    button doing both surprises whoever wanted the other. */}
                <DropdownMenuItem onSelect={onResetLayout}>
                    <RotateCcw aria-hidden className="size-3" />
                    Reset table layout
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * What is currently narrowing the list, and a way to undo each part.
 *
 * Filters live behind dropdowns, so without this the only sign that a view is
 * narrowed is a row count that looks low — which reads as missing data rather
 * than as a filter somebody set ten minutes ago.
 */
function ActiveFilters({
    query,
    topics,
    speakers,
    onQueryChange,
}: {
    query: StudioProjectQuery;
    topics: { id: string; name: string }[];
    speakers: { id: string; name: string }[];
    onQueryChange: (next: StudioProjectQuery) => void;
}) {
    if (!hasActiveFilters(query)) return null;

    const chips: [string, string, () => void][] = [];

    if (query.search.trim() !== "") {
        chips.push([
            "search",
            `Search: “${query.search}”`,
            () => onQueryChange(updateQuery(query, { search: "" })),
        ]);
    }

    if (query.topic) {
        const name = topics.find((t) => t.id === query.topic)?.name ?? "Unknown topic";
        chips.push(["topic", `Topic: ${name}`, () => onQueryChange(updateQuery(query, { topic: null }))]);
    }

    if (query.speaker) {
        const name =
            query.speaker === "none"
                ? "No speaker"
                : (speakers.find((s) => s.id === query.speaker)?.name ?? "Unknown speaker");
        chips.push([
            "speaker",
            `Speaker: ${name}`,
            () => onQueryChange(updateQuery(query, { speaker: null })),
        ]);
    }

    for (const [key, options, label] of [
        ["renderStatus", RENDER_OPTIONS, "Render"],
        ["driveStatus", DRIVE_OPTIONS, "Drive"],
        ["youtubeStatus", YOUTUBE_OPTIONS, "YouTube"],
    ] as const) {
        const value = query[key];
        if (!value) continue;

        const name = options.find(([id]) => id === value)?.[1] ?? value;
        chips.push([
            key,
            `${label}: ${name}`,
            () => onQueryChange(updateQuery(query, { [key]: null })),
        ]);
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {chips.map(([key, label, clear]) => (
                <button
                    key={key}
                    type="button"
                    onClick={clear}
                    className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs hover:bg-muted"
                >
                    {label}
                    <X aria-hidden className="size-3" />
                    <span className="sr-only">Remove this filter</span>
                </button>
            ))}
            <Button
                variant="ghost"
                size="sm"
                className="h-6 text-xs"
                onClick={() => onQueryChange(clearFilters(query))}
            >
                Clear all
            </Button>
        </div>
    );
}

/**
 * The saved default view: save, restore, forget.
 *
 * Three separate actions rather than one toggle, because they mean three
 * different things and combining them is how people lose a view they meant to
 * keep. "Reset layout" lives in its own menu for the same reason: filters and
 * columns are different concerns and a single "reset" that did both would
 * surprise somebody every time.
 */
function ViewMenu({
    isCurrentSaved,
    hasSavedView,
    onSave,
    onRestore,
    onClear,
}: {
    isCurrentSaved: boolean;
    hasSavedView: boolean;
    onSave: () => void;
    onRestore: () => void;
    onClear: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="h-9">
                    View
                    {/* A dot rather than a word: the menu says the rest, and
                        the toolbar is already busy. */}
                    {hasSavedView && <span aria-hidden className="ml-1 text-primary">●</span>}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel>Default Studio view</DropdownMenuLabel>
                <DropdownMenuSeparator />

                <DropdownMenuItem
                    disabled={isCurrentSaved}
                    onSelect={onSave}
                >
                    {isCurrentSaved
                        ? "Current view is your default"
                        : "Save current view as default"}
                </DropdownMenuItem>

                <DropdownMenuItem disabled={!hasSavedView} onSelect={onRestore}>
                    Restore my default view
                </DropdownMenuItem>

                <DropdownMenuItem disabled={!hasSavedView} onSelect={onClear}>
                    Forget my default view
                </DropdownMenuItem>

                <DropdownMenuSeparator />
                <p className="px-2 py-1.5 text-xs text-muted-foreground">
                    Applies when you open Studio without filters in the link. A shared
                    link always shows what it says.
                </p>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
