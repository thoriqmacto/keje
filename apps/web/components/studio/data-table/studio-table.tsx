"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Copy } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { ProjectStatusBadge } from "@/components/studio/status-badge";
import { ColumnHeader } from "@/components/studio/data-table/column-header";
import { HeaderFilterCell } from "@/components/studio/data-table/header-filters";
import { COLUMN_LABELS } from "@/components/studio/data-table/toolbar";
import { formatDateTime, formatDuration } from "@/lib/studio/format";
import { apiErrorMessage, duplicateProject } from "@/lib/studio/api";
import {
    youtubeBadgeLabel,
    youtubeBadgeStatus,
    youtubeSchedule,
    type YouTubeBadgeInput,
} from "@/lib/studio/youtube-badge";
import {
    moveColumn,
    setWidth,
    visibleColumns,
    type ColumnId,
    type TablePreferences,
} from "@/lib/studio/table-preferences";
import type { StudioProjectQuery, StudioSortKey } from "@/lib/studio/table-query";
import type { ContentProjectSummary, ContentTopic, Speaker } from "@/lib/types/studio";

/**
 * Which server sort key a column maps to, and how its cell is aligned.
 *
 * Actions has no sort key because there is nothing to order by; the header
 * renders as plain text rather than a dead button.
 */
const COLUMNS: Record<ColumnId, { sort?: StudioSortKey; align?: "left" | "right" | "center" }> = {
    working_title: { sort: "working_title" },
    topic: { sort: "topic" },
    topic_sequence: { sort: "topic_sequence", align: "right" },
    speaker: { sort: "speaker" },
    render: { sort: "render_status" },
    drive: { sort: "drive_status" },
    youtube: { sort: "youtube_status" },
    updated_at: { sort: "updated_at" },
    created_at: { sort: "created_at" },
    audio_duration: { sort: "audio_duration", align: "right" },
    actions: { align: "right" },
};

function badgeInput(project: ContentProjectSummary): YouTubeBadgeInput {
    return {
        label: project.youtube.label,
        remoteLabel: project.youtube.remote_label,
        isReplacing: project.youtube.is_replacing,
        replacementFailed: project.youtube.replacement_failed,
        hasVideo: project.youtube.status !== "pending",
    };
}

export function StudioTable({
    projects,
    query,
    preferences,
    topics,
    speakers,
    onSort,
    onQueryChange,
    onPreferencesChange,
}: {
    projects: ContentProjectSummary[];
    query: StudioProjectQuery;
    preferences: TablePreferences;
    /** Offered by the Topic and Speaker header filters. */
    topics: ContentTopic[];
    speakers: Speaker[];
    onSort: (key: StudioSortKey) => void;
    onQueryChange: (next: StudioProjectQuery) => void;
    onPreferencesChange: (next: TablePreferences) => void;
}) {
    const [dragging, setDragging] = useState<ColumnId | null>(null);
    const [dropTarget, setDropTarget] = useState<ColumnId | null>(null);

    const columns = visibleColumns(preferences);
    const rowPadding = preferences.density === "compact" ? "px-3 py-1.5" : "px-3 py-3";

    /*
     * Two columns are pinned, and neither is configurable.
     *
     * The title, because a row scrolled away from its own name is a row of
     * statuses belonging to nothing — which is exactly what happens on a
     * narrow screen with ten columns. And the actions, because the way out of
     * a row should not be something you scroll to find.
     *
     * Fixed rather than user-configurable on purpose: arbitrary pinning needs
     * running offset arithmetic for every pinned column, and the two that
     * matter are at the ends, where a single offset is zero.
     */
    const pinned = (column: ColumnId): string =>
        column === "working_title"
            ? "sticky left-0 z-20 bg-background"
            : column === "actions"
              ? "sticky right-0 z-20 bg-background"
              : "";

    function handleDrop(target: ColumnId) {
        if (dragging !== null) {
            onPreferencesChange({
                ...preferences,
                order: moveColumn(preferences.order, dragging, target),
            });
        }

        setDragging(null);
        setDropTarget(null);
    }

    return (
        /*
         * Scroll lives on this wrapper in both axes, and its height comes from
         * the space the page has left.
         *
         * `overflow-x: auto` alone computes `overflow-y` to `auto` as well,
         * which quietly made this div the sticky header's scroll container —
         * and with the height left to the content there was nowhere for the
         * header to stick to, so it scrolled away with the page while looking
         * like it was pinned. A bounded height gives it somewhere to stick,
         * and keeps the toolbar and the pagination in place while rows move.
         *
         * That bound used to be `100vh - nav - 18rem`, a constant that had to
         * be kept in step with however much chrome the page happened to draw
         * — and was not, so the window scrolled as well as the table. The page
         * caps itself at the viewport now and this takes what is left.
         *
         * `min-h-0` is the load-bearing half and not a tidy-up: a flex item
         * will not shrink below its own content height without it. Measured
         * with it removed, a 25-row page overflows the window by ~400px and
         * pushes the pagination off the bottom of the screen — which is the
         * failure this whole arrangement exists to prevent.
         *
         * It also settles the two-sticky-layer problem by construction: this
         * region cannot pass beneath the app header, so its own header has
         * nothing to collide with.
         */
        <div className="min-h-0 flex-1 overflow-auto rounded-lg border">
            <table className="w-full border-collapse text-sm" style={{ tableLayout: "fixed" }}>
                <thead>
                    {/* Sticky against the wrapper above, which is what
                        finally gives this somewhere to stick. The background
                        is opaque on purpose — a translucent one lets rows show
                        through as they scroll underneath. */}
                    <tr className="sticky top-0 z-10 bg-background">
                        {columns.map((column) => (
                            <ColumnHeader
                                key={column}
                                column={column}
                                label={COLUMN_LABELS[column]}
                                sortKey={COLUMNS[column].sort}
                                align={COLUMNS[column].align}
                                width={preferences.widths[column] ?? 150}
                                activeSort={query.sort}
                                activeDirection={query.direction}
                                onSort={onSort}
                                onDragStart={setDragging}
                                onDragOver={setDropTarget}
                                onDrop={handleDrop}
                                // Through setWidth so the drag obeys the same
                                // floor the stored layout does — otherwise a
                                // column of controls can be dragged narrower
                                // than its controls and lose them until reload.
                                onResize={(id, width) =>
                                    onPreferencesChange({
                                        ...preferences,
                                        widths: setWidth(preferences.widths, id, width),
                                    })
                                }
                                isDragging={dragging === column}
                                isDropTarget={dropTarget === column && dragging !== column}
                                pinnedClassName={pinned(column)}
                            />
                        ))}
                    </tr>

                    {/*
                        A second surface onto the same query the toolbar edits,
                        not a second filter state.

                        Deliberately *not* sticky. Pinning it too costs a
                        permanent second band of header on every screen, and
                        filters are set once and then scrolled past — unlike the
                        column labels, which you need to read a row you have
                        scrolled to. So it scrolls away and the labels stay.

                        `relative z-0` is load-bearing rather than decorative:
                        the pinned cells inside this row are `z-20` so they
                        outrank the row content they slide over, and without a
                        stacking context here they would also outrank the header
                        row above (`z-10`) and slide over *it* on the way out of
                        view. Giving the row its own context keeps that z-20
                        local, so the whole row passes under the header as one.
                    */}
                    <tr className="relative z-0 bg-background">
                        {columns.map((column) => (
                            <th
                                key={column}
                                className={`border-b px-2 pb-2 align-top ${pinned(column)}`}
                            >
                                <HeaderFilterCell
                                    column={column}
                                    query={query}
                                    topics={topics}
                                    speakers={speakers}
                                    onQueryChange={onQueryChange}
                                />
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody>
                    {projects.map((project) => (
                        <tr
                            key={project.id}
                            // The hover tint is applied to the pinned cells too
                            // via group-hover: a transparent pinned cell would
                            // let the scrolling row show through underneath it.
                            className="group border-b last:border-0 hover:bg-muted/40 [&>td.sticky]:group-hover:bg-muted"
                        >
                            {columns.map((column) => (
                                <td
                                    key={column}
                                    className={[
                                        rowPadding,
                                        "align-middle",
                                        COLUMNS[column].align === "right" ? "text-right" : "",
                                        COLUMNS[column].align === "center" ? "text-center" : "",
                                        pinned(column),
                                    ].join(" ")}
                                    style={{
                                        width: preferences.widths[column],
                                        maxWidth: preferences.widths[column],
                                    }}
                                >
                                    <Cell column={column} project={project} />
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Cell({ column, project }: { column: ColumnId; project: ContentProjectSummary }) {
    switch (column) {
        case "working_title":
            return (
                <Link
                    href={`/studio/${project.id}`}
                    className="block truncate font-medium hover:underline"
                    title={project.working_title}
                >
                    {project.working_title}
                </Link>
            );

        case "topic":
            return (
                <span className="block truncate text-muted-foreground">
                    {project.topic?.name ?? "—"}
                </span>
            );

        case "topic_sequence":
            // Tabular numerals so a column of TEMA numbers lines up.
            return <span className="tabular-nums">{project.topic_sequence ?? "—"}</span>;

        case "speaker":
            return (
                <span className="block truncate text-muted-foreground">
                    {project.speaker?.name ?? "—"}
                </span>
            );

        case "render":
            return (
                <span className="flex flex-col gap-0.5">
                    <ProjectStatusBadge
                        pipeline="render"
                        status={project.render.status}
                        label={project.render.label}
                    />
                    {/* Not a failure — a real render of an earlier revision.
                        Said in words as well as colour. */}
                    {project.render.stale && (
                        <span className="text-[11px] text-amber-700 dark:text-amber-400">
                            Outdated
                        </span>
                    )}
                </span>
            );

        case "drive":
            return (
                <ProjectStatusBadge
                    pipeline="drive"
                    status={project.drive.status}
                    label={project.drive.label}
                />
            );

        case "youtube":
            return <YouTubeCell project={project} />;

        case "updated_at":
            return (
                <span className="text-muted-foreground">{formatDateTime(project.updated_at)}</span>
            );

        case "created_at":
            return (
                <span className="text-muted-foreground">{formatDateTime(project.created_at)}</span>
            );

        case "audio_duration":
            return (
                <span className="tabular-nums text-muted-foreground">
                    {project.audio_duration ? formatDuration(project.audio_duration) : "—"}
                </span>
            );

        case "actions":
            return (
                <div className="flex items-center justify-end gap-1">
                    <DuplicateButton project={project} />
                    <Button asChild size="sm" variant="outline">
                        <Link href={`/studio/${project.id}`}>Open</Link>
                    </Button>
                </div>
            );
    }
}

/**
 * The YouTube column: where this video stands, and when it goes live.
 *
 * The date line is the part worth care. A project sitting in the render queue
 * showed nothing but "Pending", even when its publication date had been
 * decided days earlier — the one thing somebody scanning this column is
 * looking for. But a confirmed schedule and an intended one are different
 * promises, so they do not get to look identical: a plan is labelled as a
 * plan, and a confirmed schedule keeps the bare date it has always shown.
 */
function YouTubeCell({ project }: { project: ContentProjectSummary }) {
    const schedule = youtubeSchedule({
        scheduledAt: project.youtube.scheduled_at,
        plannedPublishAt: project.youtube.planned_publish_at,
    });

    return (
        <span className="flex flex-col gap-0.5">
            <ProjectStatusBadge
                pipeline="youtube"
                status={youtubeBadgeStatus(badgeInput(project), project.youtube.status)}
                label={youtubeBadgeLabel(badgeInput(project))}
            />

            {schedule && (
                <span
                    className={`text-[11px] ${
                        schedule.overdue
                            ? "text-amber-700 dark:text-amber-400"
                            : "text-muted-foreground"
                    }`}
                >
                    {/* A plan that has gone past is not a date to look forward
                        to — the upload refuses it — so it is flagged rather
                        than read out as though it were still coming. */}
                    {schedule.planned && (schedule.overdue ? "Was planned " : "Planned ")}
                    {formatDateTime(schedule.at)}
                </span>
            )}
        </span>
    );
}

/**
 * Start the next one from this one.
 *
 * A series is the same every week apart from the recording, so the row you are
 * looking at is usually the best starting point for the next episode. The
 * server decides what carries over; this only asks.
 *
 * An icon rather than a word: the column is narrow, Open is the primary action
 * and should stay the wide one, and a second full-width button beside it would
 * make every row read as a choice between two equals.
 */
function DuplicateButton({ project }: { project: ContentProjectSummary }) {
    const router = useRouter();
    const [busy, setBusy] = useState(false);

    async function onClick() {
        setBusy(true);
        try {
            const copy = await duplicateProject(project.id);
            toast.success("Copied. Add the recording to render it.");
            router.push(`/studio/${copy.id}`);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not duplicate the project."));
            setBusy(false);
        }
    }

    return (
        <Button
            size="sm"
            variant="ghost"
            className="h-8 px-2"
            disabled={busy}
            aria-label={`Duplicate ${project.working_title}`}
            title="Duplicate"
            onClick={() => void onClick()}
        >
            <Copy aria-hidden className="size-3.5" />
        </Button>
    );
}
