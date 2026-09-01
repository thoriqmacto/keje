"use client";

import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { PAGE_SIZES, updateQuery, type PageSize, type StudioProjectQuery } from "@/lib/studio/table-query";
import type { PaginationMeta } from "@/lib/types/studio";

/**
 * The table footer: where you are, and how to move.
 *
 * States the range rather than only the page number, because "Showing 26–50 of
 * 247" answers both "how far in am I" and "how much is there" at once, which a
 * page number on its own does not.
 */
export function StudioTablePagination({
    query,
    meta,
    onQueryChange,
}: {
    query: StudioProjectQuery;
    meta: PaginationMeta;
    onQueryChange: (next: StudioProjectQuery) => void;
}) {
    const page = meta.current_page;
    const lastPage = Math.max(1, meta.last_page);
    const atStart = page <= 1;
    const atEnd = page >= lastPage;

    const goTo = (next: number) =>
        onQueryChange(updateQuery(query, { page: Math.min(lastPage, Math.max(1, next)) }));

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t px-3 py-2 text-xs">
            <p className="text-muted-foreground">
                {meta.total === 0
                    ? "No projects"
                    : `Showing ${meta.from}–${meta.to} of ${meta.total}`}
            </p>

            <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-1">
                    <span className="text-muted-foreground">Rows per page</span>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm" className="h-7 px-2">
                                {query.perPage}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {PAGE_SIZES.map((size) => (
                                <DropdownMenuCheckboxItem
                                    key={size}
                                    checked={size === query.perPage}
                                    // Resetting to page one is handled by
                                    // updateQuery: page 9 of a 25-row view is
                                    // rarely page 9 of a 100-row one.
                                    onSelect={() =>
                                        onQueryChange(
                                            updateQuery(query, { perPage: size as PageSize }),
                                        )
                                    }
                                >
                                    {size}
                                </DropdownMenuCheckboxItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <span className="text-muted-foreground">
                    Page {page} of {lastPage}
                </span>

                {/* Disabled rather than hidden at the ends: controls that
                    disappear make the row jump about as you page. */}
                <div className="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-7 px-2"
                        aria-label="First page"
                        disabled={atStart}
                        onClick={() => goTo(1)}
                    >
                        <ChevronsLeft aria-hidden className="size-3" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-7 px-2"
                        aria-label="Previous page"
                        disabled={atStart}
                        onClick={() => goTo(page - 1)}
                    >
                        <ChevronLeft aria-hidden className="size-3" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-7 px-2"
                        aria-label="Next page"
                        disabled={atEnd}
                        onClick={() => goTo(page + 1)}
                    >
                        <ChevronRight aria-hidden className="size-3" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-7 px-2"
                        aria-label="Last page"
                        disabled={atEnd}
                        onClick={() => goTo(lastPage)}
                    >
                        <ChevronsRight aria-hidden className="size-3" />
                    </Button>
                </div>
            </div>
        </div>
    );
}
