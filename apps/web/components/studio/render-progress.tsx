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
}: {
    status: RenderStatus;
    progress: number;
}) {
    if (!IN_FLIGHT.includes(status)) return null;

    const queued = status === "queued";

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">
                    {queued ? "Waiting for a render worker…" : "Rendering"}
                </span>
                {!queued && <span className="font-mono text-xs">{progress}%</span>}
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className={
                        queued
                            ? "h-full w-1/4 animate-pulse rounded-full bg-amber-500"
                            : "h-full rounded-full bg-amber-500 transition-[width] duration-500"
                    }
                    style={queued ? undefined : { width: `${Math.max(2, progress)}%` }}
                />
            </div>
        </div>
    );
}
