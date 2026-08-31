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
}: {
    status: RenderStatus;
    progress: number;
    /** Set once the API decides the wait is no longer normal. */
    stalledReason?: string | null;
}) {
    if (!IN_FLIGHT.includes(status)) return null;

    const queued = status === "queued";
    const stalled = queued && Boolean(stalledReason);

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">
                    {stalled
                        ? "Still waiting for a render worker"
                        : queued
                          ? "Waiting for a render worker…"
                          : "Rendering"}
                </span>
                {!queued && <span className="font-mono text-xs">{progress}%</span>}
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className={
                        stalled
                            ? "h-full w-1/4 rounded-full bg-amber-500"
                            : queued
                              ? "h-full w-1/4 animate-pulse rounded-full bg-amber-500"
                              : "h-full rounded-full bg-amber-500 transition-[width] duration-500"
                    }
                    style={queued ? undefined : { width: `${Math.max(2, progress)}%` }}
                />
            </div>

            {/* A pulsing bar at 0% reads as "working". Once the API says
                nothing has picked this up, say so — the render is not lost,
                but it is not progressing either, and only the operator can
                fix it. */}
            {stalled && (
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
