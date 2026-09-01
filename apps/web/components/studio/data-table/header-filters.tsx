"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
    DRIVE_OPTIONS,
    RENDER_OPTIONS,
    YOUTUBE_OPTIONS,
} from "@/components/studio/data-table/toolbar";
import {
    UPDATED_WINDOWS,
    updateQuery,
    type StudioProjectQuery,
} from "@/lib/studio/table-query";
import type { ColumnId } from "@/lib/studio/table-preferences";
import type { ContentTopic, Speaker } from "@/lib/types/studio";

/**
 * A filter row under the column headers.
 *
 * A second surface onto the *same* query the toolbar edits — not a second
 * filter state. Two independent sets of filter controls is the obvious way to
 * build this and the wrong one: they disagree the moment either is used, and
 * the user is left unable to tell which one the table is obeying. So both
 * write through the same `onQueryChange`, and both read from the same query.
 *
 * Kept deliberately small. A filter row that doubles the height of the header
 * costs more rows on screen than the filtering saves.
 */
export function HeaderFilterCell({
    column,
    query,
    topics,
    speakers,
    onQueryChange,
}: {
    column: ColumnId;
    query: StudioProjectQuery;
    topics: ContentTopic[];
    speakers: Speaker[];
    onQueryChange: (next: StudioProjectQuery) => void;
}) {
    const set = (patch: Partial<StudioProjectQuery>) => onQueryChange(updateQuery(query, patch));

    switch (column) {
        case "working_title":
            return (
                <DebouncedFilterInput
                    label="Filter by working title"
                    placeholder="Filter title…"
                    value={query.workingTitle}
                    onCommit={(workingTitle) => set({ workingTitle })}
                />
            );

        case "topic":
            return (
                <FilterPopover
                    label="Topic"
                    value={query.topic}
                    options={topics.map((topic): [string, string] => [topic.id, topic.name])}
                    onChange={(topic) => set({ topic })}
                />
            );

        case "topic_sequence":
            return (
                <DebouncedFilterInput
                    label="Filter by TEMA"
                    placeholder="#"
                    value={query.topicSequence ?? ""}
                    inputMode="numeric"
                    onCommit={(value) => set({ topicSequence: value === "" ? null : value })}
                />
            );

        case "speaker":
            return (
                <FilterPopover
                    label="Speaker"
                    value={query.speaker}
                    // "No speaker" is a real thing to look for — "which of
                    // these have I forgotten to attribute" — and it cannot be
                    // expressed by naming somebody.
                    options={[
                        ["none", "No speaker"],
                        ...speakers.map((speaker): [string, string] => [speaker.id, speaker.name]),
                    ]}
                    onChange={(speaker) => set({ speaker })}
                />
            );

        case "render":
            return (
                <FilterPopover
                    label="Render"
                    value={query.renderStatus}
                    options={RENDER_OPTIONS}
                    onChange={(renderStatus) => set({ renderStatus })}
                />
            );

        case "drive":
            return (
                <FilterPopover
                    label="Drive"
                    value={query.driveStatus}
                    options={DRIVE_OPTIONS}
                    onChange={(driveStatus) => set({ driveStatus })}
                />
            );

        case "youtube":
            return (
                <FilterPopover
                    label="YouTube"
                    value={query.youtubeStatus}
                    options={YOUTUBE_OPTIONS}
                    onChange={(youtubeStatus) => set({ youtubeStatus })}
                />
            );

        case "updated_at":
            return (
                <FilterPopover
                    label="Updated"
                    value={query.updatedWithin}
                    options={UPDATED_WINDOWS.map((w): [string, string] => [w.value, w.label])}
                    onChange={(updatedWithin) => set({ updatedWithin })}
                />
            );

        // Audio duration, Created and Actions get nothing. A filter box on a
        // column nobody filters by is clutter that costs a row of height.
        default:
            return null;
    }
}

/**
 * A text filter that waits for a pause before applying.
 *
 * Typing "lecture" would otherwise be seven requests and seven history
 * entries, and the back button would walk back through them one letter at a
 * time.
 */
function DebouncedFilterInput({
    label,
    placeholder,
    value,
    inputMode,
    onCommit,
}: {
    label: string;
    placeholder: string;
    value: string;
    inputMode?: "numeric";
    onCommit: (value: string) => void;
}) {
    const [draft, setDraft] = useState(value);

    // Re-sync when the query changes from elsewhere — a cleared chip, the back
    // button, a restored saved view. Without this the box would go on showing
    // a filter that is no longer applied.
    useEffect(() => setDraft(value), [value]);

    useEffect(() => {
        if (draft === value) return;

        const timer = setTimeout(() => onCommit(draft), 350);

        return () => clearTimeout(timer);
        // onCommit is recreated per render by design; depending on it would
        // reset the timer on every keystroke and never fire.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [draft, value]);

    return (
        <Input
            aria-label={label}
            placeholder={placeholder}
            value={draft}
            inputMode={inputMode}
            onChange={(event) => setDraft(event.target.value)}
            onKeyDown={(event) => {
                if (event.key === "Escape") {
                    setDraft("");
                    onCommit("");
                }
            }}
            className="h-7 w-full min-w-0 px-2 text-xs"
        />
    );
}

/**
 * A compact status filter.
 *
 * A popover rather than a `<select>`: the status columns are narrow, and a
 * native select stretched to a column's width truncates its own labels. The
 * trigger shows the active value so the filter is visible without opening it.
 */
function FilterPopover({
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
                <Button
                    variant="ghost"
                    size="sm"
                    // The accessible name carries the column and the current
                    // value; the visible text has room for one of them.
                    aria-label={
                        selected ? `${label} filter: ${selected[1]}` : `Filter by ${label}`
                    }
                    className={`h-7 w-full justify-start px-2 text-xs font-normal ${
                        selected ? "text-foreground" : "text-muted-foreground"
                    }`}
                >
                    <span className="truncate">{selected ? selected[1] : "All"}</span>
                    {/* Not colour alone: a dot beside the text so an active
                        filter is visible without relying on a tint. */}
                    {selected && <span aria-hidden className="ml-auto text-primary">●</span>}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="start" className="max-h-80 overflow-y-auto">
                <DropdownMenuCheckboxItem checked={value === null} onCheckedChange={() => onChange(null)}>
                    All
                </DropdownMenuCheckboxItem>

                {options.map(([id, optionLabel]) => (
                    <DropdownMenuCheckboxItem
                        key={id}
                        checked={value === id}
                        onCheckedChange={() => onChange(value === id ? null : id)}
                    >
                        {optionLabel}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
