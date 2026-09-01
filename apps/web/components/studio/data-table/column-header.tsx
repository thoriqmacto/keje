"use client";

import { ArrowDown, ArrowUp, ChevronsUpDown, GripVertical } from "lucide-react";
import type { ColumnId } from "@/lib/studio/table-preferences";
import type { StudioSortKey } from "@/lib/studio/table-query";

/**
 * One header cell: a sort control, a drag handle and a resize grip.
 *
 * The three are separate targets on purpose. Making the whole header draggable
 * is the obvious implementation and a bad one — every attempt to sort becomes a
 * half-drag, and the column jitters instead of sorting. So dragging is confined
 * to a small handle that appears on hover, and the header itself only ever
 * sorts.
 */
export function ColumnHeader({
    column,
    label,
    sortKey,
    align = "left",
    width,
    activeSort,
    activeDirection,
    onSort,
    onDragStart,
    onDragOver,
    onDrop,
    onResize,
    isDragging,
    isDropTarget,
    pinnedClassName = "",
}: {
    column: ColumnId;
    label: string;
    /** Absent for columns the server cannot order by, such as Actions. */
    sortKey?: StudioSortKey;
    align?: "left" | "right" | "center";
    width: number;
    activeSort: StudioSortKey;
    activeDirection: "asc" | "desc";
    onSort: (key: StudioSortKey) => void;
    onDragStart: (column: ColumnId) => void;
    onDragOver: (column: ColumnId) => void;
    onDrop: (column: ColumnId) => void;
    onResize: (column: ColumnId, width: number) => void;
    isDragging: boolean;
    isDropTarget: boolean;
    /** Sticky positioning for a pinned column; empty for the rest. */
    pinnedClassName?: string;
}) {
    const sorted = sortKey !== undefined && activeSort === sortKey;

    /*
     * aria-sort is what a screen reader announces, and it is only meaningful
     * on the column actually sorted — marking every sortable column "none"
     * would be noise on every header.
     */
    const ariaSort = sorted
        ? activeDirection === "asc"
            ? ("ascending" as const)
            : ("descending" as const)
        : undefined;

    function startResize(event: React.PointerEvent<HTMLSpanElement>) {
        event.preventDefault();
        event.stopPropagation();

        const startX = event.clientX;
        const startWidth = width;
        const handle = event.currentTarget;

        // Pointer capture keeps the drag alive when the cursor leaves the
        // 4px grip, which it does immediately on any real drag.
        handle.setPointerCapture(event.pointerId);

        const move = (moveEvent: PointerEvent) => {
            onResize(column, startWidth + (moveEvent.clientX - startX));
        };

        const done = () => {
            handle.releasePointerCapture(event.pointerId);
            handle.removeEventListener("pointermove", move);
            handle.removeEventListener("pointerup", done);
        };

        handle.addEventListener("pointermove", move);
        handle.addEventListener("pointerup", done);
    }

    return (
        <th
            scope="col"
            aria-sort={ariaSort}
            style={{ width, minWidth: width, maxWidth: width }}
            className={[
                "group relative select-none border-b px-3 py-2 text-xs font-medium",
                // Opaque, not a tint: a translucent header lets rows show
                // through as they scroll underneath it.
                pinnedClassName === "" ? "bg-muted/40" : `${pinnedClassName} bg-muted`,
                align === "right" ? "text-right" : align === "center" ? "text-center" : "text-left",
                isDragging ? "opacity-40" : "",
                // A left edge marks where the dragged column will land.
                isDropTarget ? "before:absolute before:inset-y-0 before:left-0 before:w-0.5 before:bg-primary" : "",
            ].join(" ")}
            onDragOver={(event) => {
                event.preventDefault();
                onDragOver(column);
            }}
            onDrop={(event) => {
                event.preventDefault();
                onDrop(column);
            }}
        >
            <div
                className={[
                    "flex items-center gap-1",
                    align === "right" ? "justify-end" : align === "center" ? "justify-center" : "",
                ].join(" ")}
            >
                {/* Small, and only visible on hover or focus: a permanently
                    drawn handle on every column is visual noise, but one that
                    cannot be reached by keyboard is worse. */}
                <span
                    draggable
                    role="button"
                    tabIndex={0}
                    aria-label={`Reorder ${label} column`}
                    className="cursor-grab opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-60"
                    onDragStart={() => onDragStart(column)}
                >
                    <GripVertical aria-hidden className="size-3" />
                </span>

                {sortKey === undefined ? (
                    <span>{label}</span>
                ) : (
                    <button
                        type="button"
                        className="inline-flex items-center gap-1 rounded hover:text-foreground focus-visible:outline focus-visible:outline-2"
                        onClick={() => onSort(sortKey)}
                    >
                        <span>{label}</span>
                        {/* The arrow is decorative; aria-sort above carries the
                            same fact to anyone who cannot see it. */}
                        {sorted ? (
                            activeDirection === "asc" ? (
                                <ArrowUp aria-hidden className="size-3" />
                            ) : (
                                <ArrowDown aria-hidden className="size-3" />
                            )
                        ) : (
                            <ChevronsUpDown aria-hidden className="size-3 opacity-30" />
                        )}
                    </button>
                )}
            </div>

            {/* Sits on the boundary, wider than it looks so it can be grabbed. */}
            <span
                role="separator"
                aria-orientation="vertical"
                aria-label={`Resize ${label} column`}
                className="absolute inset-y-0 -right-1 w-2 cursor-col-resize touch-none opacity-0 hover:opacity-100 group-hover:opacity-60"
                onPointerDown={startResize}
            >
                <span aria-hidden className="absolute inset-y-1 left-1 w-px bg-border" />
            </span>
        </th>
    );
}
