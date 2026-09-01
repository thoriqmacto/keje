"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import useSWR from "swr";
import { Button } from "@/components/ui/button";
import { StudioTable } from "@/components/studio/data-table/studio-table";
import { FinishAll } from "@/components/studio/finish-all";
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

    return (
        <section className="mx-auto flex w-full max-w-[110rem] flex-col gap-6 px-4 py-10">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-3xl font-semibold tracking-tight">Content Studio</h1>
                    <p className="text-muted-foreground">
                        Lecture recordings and artwork in, YouTube-ready video out.
                    </p>
                </div>
                {/* No New Content button here any more: it lives in the app
                    header now, where it is reachable from every page rather
                    than only this one. Two equally prominent copies of the
                    same action on the same screen is one too many — the empty
                    state below still offers it, because that is the one place
                    somebody needs pointing at it. */}
            </div>

            {/* Offered beside the table's own actions rather than in the
                toolbar's filter row: it acts on the dataset those filters
                describe, so it belongs next to the result count. */}
            <div className="flex flex-wrap items-start gap-2">
                <FinishAll query={query} onFinished={() => void mutate()} />
            </div>

            <StudioTableToolbar
                query={query}
                total={meta?.total ?? 0}
                isValidating={isValidating && !isLoading}
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

            {/* The ordering, said out loud. An arrow on one header is easy to
                miss, and "why is this project first" is a question the table
                should not make anybody work out. */}
            <p className="-mt-3 text-xs text-muted-foreground">
                {describeSort(query, COLUMN_LABELS[sortColumn(query.sort)])}
            </p>

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
                <div className="flex flex-col">
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
        <div className="overflow-hidden rounded-lg border" aria-busy="true" aria-live="polite">
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
        <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-10">
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
        <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-10">
            <h2 className="text-lg font-medium">No projects match these filters.</h2>
            <Button size="sm" variant="outline" onClick={onClear}>
                Clear filters
            </Button>
        </div>
    );
}

function EmptyStudio() {
    return (
        <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-10">
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
