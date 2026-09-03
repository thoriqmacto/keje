"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import useSWR from "swr";
import { Button } from "@/components/ui/button";
import { StudioTable } from "@/components/studio/data-table/studio-table";
import { StudioTablePagination } from "@/components/studio/data-table/pagination";
import { COLUMN_LABELS, StudioTableToolbar } from "@/components/studio/data-table/toolbar";
import { listProjects, listSpeakers, listTopics, studioKeys } from "@/lib/studio/api";
import {
    DEFAULT_PREFERENCES,
    clearPreferences,
    loadPreferences,
    savePreferences,
    toggleColumn,
    type ColumnId,
    type Density,
    type TablePreferences,
} from "@/lib/studio/table-preferences";
import {
    clearSavedView,
    loadSavedView,
    resolveInitialQuery,
    saveView,
    viewFromQuery,
    type SavedView,
} from "@/lib/studio/saved-view";
import {
    clearFilters,
    describeSort,
    hasActiveFilters,
    parseQuery,
    serializeQuery,
    toggleSort,
    type StudioProjectQuery,
    type StudioSortKey,
} from "@/lib/studio/table-query";

/**
 * The Content Studio list.
 *
 * Two kinds of state, kept deliberately apart:
 *
 *   the URL          which rows am I looking at — filters, search, sort, page.
 *                    Survives a refresh, the back button, and being pasted to
 *                    somebody else.
 *
 *   localStorage     how is my table arranged — column order, widths, which
 *                    are hidden, density. Personal to one browser, and no use
 *                    at all in a shared link.
 *
 * Nothing about the dataset is computed here. Sorting, filtering, searching and
 * paging all happen in SQL, so what the table shows is exactly what the server
 * decided — a browser can only sort the rows it was given, and at any real
 * volume those are not all the rows.
 */
export default function StudioListClient() {
    const router = useRouter();
    const pathname = usePathname();
    const searchParams = useSearchParams();

    const query = useMemo(
        () => parseQuery(new URLSearchParams(searchParams.toString())),
        [searchParams],
    );

    /*
     * Preferences load after mount rather than during render. localStorage is
     * not available on the server, and reading it during the first client
     * render would make that render disagree with the server's — React calls
     * that a hydration mismatch and throws the whole tree away.
     */
    const [preferences, setPreferences] = useState<TablePreferences>(DEFAULT_PREFERENCES);
    const [savedView, setSavedView] = useState<SavedView | null>(null);

    useEffect(() => {
        setPreferences(loadPreferences());
    }, []);

    /*
     * Apply a saved view, but only to a plain /studio.
     *
     * A URL carrying any dataset parameter wins outright — a shared link has
     * to show what the sender saw, and the back button has to return to what
     * was left. `replace` rather than `push` so the un-defaulted URL does not
     * become a history entry the back button lands on and immediately
     * redirects away from again.
     */
    useEffect(() => {
        const saved = loadSavedView();
        setSavedView(saved);

        const initial = resolveInitialQuery(new URLSearchParams(window.location.search), saved);

        if (initial !== null) {
            const params = serializeQuery(initial).toString();
            router.replace(params === "" ? pathname : `${pathname}?${params}`, { scroll: false });
        }
        // Once, on arrival. Re-running on every navigation would re-apply the
        // default over whatever the user had just chosen.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const updatePreferences = useCallback((next: TablePreferences) => {
        // Applied immediately and persisted alongside: a column drag has no
        // server round trip to wait for, and pretending otherwise would make
        // the table feel slower than it is.
        setPreferences(next);
        savePreferences(next);
    }, []);

    const setQuery = useCallback(
        (next: StudioProjectQuery) => {
            const params = serializeQuery(next).toString();
            router.push(params === "" ? pathname : `${pathname}?${params}`, { scroll: false });
        },
        [pathname, router],
    );

    const { data, error, isLoading, isValidating, mutate } = useSWR(
        studioKeys.projectList(query),
        () => listProjects(query),
        {
            // Statuses change while renders and uploads run elsewhere.
            refreshInterval: 15000,
            // The previous page stays on screen while the next one loads, so
            // paging does not flash an empty table between two full ones.
            keepPreviousData: true,
        },
    );

    // The Topic and Speaker header filters offer real names rather than asking
    // anybody to know a UUID. Cached and rarely changing, so they are fetched
    // once rather than with every page of results.
    const { data: topics } = useSWR(studioKeys.topics, listTopics, { revalidateOnFocus: false });
    const { data: speakers } = useSWR(studioKeys.speakers, listSpeakers, {
        revalidateOnFocus: false,
    });

    const projects = data?.data ?? [];
    const meta = data?.meta;

    const total = meta?.total ?? 0;

    return (
        /*
         * The page is exactly the viewport, and the table is the only thing
         * that scrolls.
         *
         * It used to be a stack of natural-height blocks with the table given
         * a guessed cap of `100vh - nav - 18rem`. Everything above it added up
         * to more than that 18rem, so the window scrolled *as well as* the
         * table — two scrollbars, and the toolbar drifting off the top exactly
         * when you wanted to change a filter. Worse, the 18 was a constant
         * nobody could keep honest: adding a row of chrome silently made it
         * wrong again.
         *
         * `max-h` rather than `h`, which is the difference between a short
         * list looking deliberate and looking broken: with three projects the
         * section is only as tall as it needs to be, where a fixed height
         * would draw a viewport-tall bordered box with three rows adrift at
         * the top of it. Past that the cap engages, and the table — the one
         * item allowed to shrink, via min-h-0 — gives back the difference.
         *
         * dvh rather than vh so mobile browser chrome sliding in and out does
         * not leave a permanent strip of page scroll behind it.
         */
        <section className="mx-auto flex max-h-[calc(100dvh-var(--app-nav-height))] w-full max-w-[110rem] flex-col gap-2 px-4 py-4">
            {/* Title and description on one line rather than two, with the
                count beside them: three short facts about the list, none of
                which is a control, so none of them needs its own row. */}
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h1 className="text-xl font-semibold tracking-tight">Content Studio</h1>
                <p className="text-xs text-muted-foreground" aria-live="polite">
                    {/* Only the filtered total. Reporting "23 of 247" would
                        mean a second count of the whole table on every
                        keystroke, to say something nobody acts on. */}
                    {hasActiveFilters(query)
                        ? `${total} matching ${total === 1 ? "project" : "projects"}`
                        : `${total} ${total === 1 ? "project" : "projects"}`}
                    {" · "}
                    {/* The ordering, said out loud. An arrow on one header is
                        easy to miss, and "why is this project first" is a
                        question the table should not make anybody work out. */}
                    {describeSort(query, COLUMN_LABELS[sortColumn(query.sort)])}
                    {isValidating && !isLoading && (
                        <span className="ml-2 opacity-60">Updating…</span>
                    )}
                </p>
            </div>

            <StudioTableToolbar
                query={query}
                preferences={preferences}
                onQueryChange={setQuery}
                onToggleColumn={(column: ColumnId) =>
                    updatePreferences({
                        ...preferences,
                        hidden: toggleColumn(preferences.hidden, column),
                    })
                }
                onDensityChange={(density: Density) =>
                    updatePreferences({ ...preferences, density })
                }
                onResetLayout={() => {
                    clearPreferences();
                    setPreferences(DEFAULT_PREFERENCES);
                }}
                savedView={savedView}
                onSaveView={() => {
                    const view = viewFromQuery(query);
                    saveView(view);
                    setSavedView(view);
                }}
                onRestoreView={() => {
                    const restored = savedView === null
                        ? null
                        : resolveInitialQuery(new URLSearchParams(""), savedView);

                    if (restored !== null) {
                        setQuery(restored);
                    }
                }}
                onClearSavedView={() => {
                    clearSavedView();
                    setSavedView(null);
                }}
            />

            {error ? (
                <ErrorState onRetry={() => void mutate()} />
            ) : isLoading ? (
                <TableSkeleton />
            ) : projects.length === 0 ? (
                // An empty result and an empty account need different offers:
                // "New Content" is useless advice to somebody whose filter is
                // simply too narrow.
                hasActiveFilters(query) ? (
                    <EmptyFiltered onClear={() => setQuery(clearFilters(query))} />
                ) : (
                    <EmptyStudio />
                )
            ) : (
                // min-h-0 is the load-bearing part, here and on the scroll
                // region inside. A flex item will not shrink below its content
                // height without it, so the table would push the pagination
                // off the bottom of the screen instead of scrolling.
                <div className="flex min-h-0 flex-1 flex-col">
                    <StudioTable
                        projects={projects}
                        query={query}
                        preferences={preferences}
                        topics={topics ?? []}
                        speakers={speakers ?? []}
                        onSort={(key: StudioSortKey) => setQuery(toggleSort(query, key))}
                        onQueryChange={setQuery}
                        onPreferencesChange={updatePreferences}
                    />
                    {meta && (
                        <StudioTablePagination
                            query={query}
                            meta={meta}
                            onQueryChange={setQuery}
                        />
                    )}
                </div>
            )}
        </section>
    );
}

/** The column a sort key belongs to, for the "sorted by" line. */
function sortColumn(sort: StudioSortKey): ColumnId {
    switch (sort) {
        case "render_status":
            return "render";
        case "drive_status":
            return "drive";
        case "youtube_status":
            return "youtube";
        default:
            return sort as ColumnId;
    }
}

/**
 * A shaped placeholder rather than the word "Loading".
 *
 * Only for the first load. Every later fetch keeps the previous page on
 * screen, because replacing a full table with a skeleton on every sort makes
 * the page flash and reads as slower than it is.
 */
function TableSkeleton() {
    return (
        <div className="min-h-0 overflow-hidden rounded-lg border" aria-busy="true" aria-live="polite">
            <span className="sr-only">Loading projects…</span>
            {Array.from({ length: 8 }).map((_, row) => (
                <div key={row} className="flex gap-4 border-b px-3 py-3 last:border-0">
                    {[280, 160, 80, 140, 120, 120].map((width, cell) => (
                        <div
                            key={cell}
                            style={{ width }}
                            className="h-4 animate-pulse rounded bg-muted"
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

/**
 * A failed request is not an empty table.
 *
 * Showing "no projects" for a network error is the wrong answer to the wrong
 * question, and it invites somebody to go looking for content that never went
 * anywhere. The filters stay in the URL, so retrying resumes the same view.
 */
function ErrorState({ onRetry }: { onRetry: () => void }) {
    return (
        <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-8">
            <h2 className="text-lg font-medium">Could not load Studio projects.</h2>
            <p className="text-sm text-muted-foreground">
                Your filters are still applied — retrying will load the same view.
            </p>
            <Button size="sm" variant="outline" onClick={onRetry}>
                Retry
            </Button>
        </div>
    );
}

function EmptyFiltered({ onClear }: { onClear: () => void }) {
    return (
        <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-8">
            <h2 className="text-lg font-medium">No projects match these filters.</h2>
            <Button size="sm" variant="outline" onClick={onClear}>
                Clear filters
            </Button>
        </div>
    );
}

function EmptyStudio() {
    return (
        <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-8">
            <h2 className="text-lg font-medium">No content yet</h2>
            <p className="text-sm text-muted-foreground">
                Create your first project to upload a lecture recording, add the Kajian Tematik
                title information and render a video.
            </p>
            <Button asChild size="sm">
                <Link href="/studio/new">New Content</Link>
            </Button>
        </div>
    );
}
