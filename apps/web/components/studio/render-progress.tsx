"use client";

import useSWR from "swr";
import { getRenderStatus, studioKeys } from "@/lib/studio/api";
import type { RenderStatus } from "@/lib/types/studio";

const IN_FLIGHT: RenderStatus[] = ["queued", "rendering"];

/**
 * Polls the render-status endpoint while a render is in flight.
 *
 * Polling, not WebSockets: Sprint 1 does not need a socket layer for a
 * single-user studio, and this stops entirely once the render settles.
 */
export function useRenderStatus(projectId: string, initialStatus: RenderStatus) {
    return useSWR(
        studioKeys.renderStatus(projectId),
        () => getRenderStatus(projectId),
        {
            fallbackData: {
                status: initialStatus,
                label: initialStatus,
                progress: 0,
                error: null,
                stalled: false,
                stalled_reason: null,
                has_output: false,
                rendered_at: null,
                cancel_requested: false,
                attempt: { id: null, status: null, started_at: null, finished_at: null },
            },
            // Poll only while something is actually happening.
            refreshInterval: (latest) =>
                latest && IN_FLIGHT.includes(latest.status) ? 2000 : 0,
            revalidateOnFocus: true,
        },
    );
}

export function RenderProgress({
    status,
    progress,
    stalledReason = null,
    cancelRequested = false,
    onCancel,
}: {
    status: RenderStatus;
    progress: number;
    /** Set once the API decides the wait is no longer normal. */
    stalledReason?: string | null;
    /** A stop was asked for; FFmpeg is still shutting down. */
    cancelRequested?: boolean;
    /** Omitted where stopping is not offered, which hides the control. */
    onCancel?: () => void;
}) {
    if (!IN_FLIGHT.includes(status)) return null;

    const queued = status === "queued";
    const stalled = queued && Boolean(stalledReason);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3 text-sm">
                <span className="text-muted-foreground">
                    {cancelRequested
                        ? "Stopping the render…"
                        : stalled
                          ? "Still waiting for a render worker"
                          : queued
                            ? "Waiting for a render worker…"
                            : "Rendering"}
                </span>
                <span className="flex items-center gap-3">
                    {!queued && !cancelRequested && (
                        <span className="font-mono text-xs">{progress}%</span>
                    )}
                    {/*
                        Beside the progress it is abandoning, rather than down
                        with Render at the bottom of the card. The reason to
                        stop is almost always something just noticed in the
                        preview or the titles, and the control belongs where
                        the eye already is.

                        Not a destructive-looking button: nothing is lost that
                        cannot be remade by pressing Render again, and styling
                        it as a danger would overstate what it costs.
                    */}
                    {onCancel && (
                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={cancelRequested}
                            className="text-xs font-medium text-muted-foreground underline underline-offset-4 hover:text-foreground disabled:opacity-60 disabled:no-underline"
                        >
                            {cancelRequested ? "Stopping…" : "Cancel"}
                        </button>
                    )}
                </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className={
                        // Grey once a stop is under way: the bar is still
                        // where the encode got to, but it is no longer
                        // progress towards anything.
                        cancelRequested
                            ? "h-full rounded-full bg-muted-foreground/40"
                            : stalled
                              ? "h-full w-1/4 rounded-full bg-amber-500"
                              : queued
                                ? "h-full w-1/4 animate-pulse rounded-full bg-amber-500"
                                : "h-full rounded-full bg-amber-500 transition-[width] duration-500"
                    }
                    style={
                        queued && !cancelRequested
                            ? undefined
                            : { width: `${Math.max(2, progress)}%` }
                    }
                />
            </div>

            {/* A pulsing bar at 0% reads as "working". Once the API says
                nothing has picked this up, say so — the render is not lost,
                but it is not progressing either, and only the operator can
                fix it. */}
            {stalled && !cancelRequested && (
                <div className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                    <p className="font-medium">This render has not started</p>
                    <p>{stalledReason}</p>
                    <p className="mt-1 text-xs">
                        Your project is safe — the render is still queued and will run as soon as
                        a worker picks it up.
                    </p>
                </div>
            )}
        </div>
    );
}
