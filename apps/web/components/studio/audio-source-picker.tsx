"use client";

import { useState } from "react";
import useSWR from "swr";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { apiErrorMessage, listAudioSources, reuseAudio, studioKeys } from "@/lib/studio/api";
import { formatBytes, formatDateTime, formatDuration } from "@/lib/studio/format";
import type { AudioSource, ContentProject } from "@/lib/types/studio";

/**
 * Reuse a recording that is already on the server.
 *
 * One lecture often becomes several videos — the same three hours trimmed
 * three different ways — and re-uploading half a gigabyte for each of them is
 * a slow way to say "that one again".
 *
 * ── What the browser is allowed to say ──────────────────────────────────
 *
 * This is the one place in the studio that could plausibly have been built as
 * "tell the server which file to use", and it deliberately is not. Every row
 * here names a *project*; the server looks that project up scoped to the
 * caller and reads its own stored path. There is no file identifier in this
 * component, because there is none in the API it talks to.
 *
 * ── Why it copies ───────────────────────────────────────────────────────
 *
 * The server copies rather than sharing the file, and the panel says so. A
 * shared path would make one project's prune another project's data loss, and
 * somebody watching the Storage page needs to know why the same recording
 * appears twice.
 */

/**
 * The button, beside the upload control.
 *
 * Controlled rather than self-contained, because the two halves belong in two
 * different places: the button has to sit next to "Upload", where somebody
 * reaching for one will see the other, and the list has to span the card. A
 * component owning both could only render them adjacent.
 */
export function AudioSourceButton({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Button type="button" variant="ghost" size="sm" onClick={() => onOpenChange(!open)}>
            {open ? "Cancel" : "Use existing"}
        </Button>
    );
}

export function AudioSourceList({
    projectId,
    onReused,
}: {
    projectId: string;
    onReused: (project: ContentProject) => void;
}) {
    // Fetched when the panel opens rather than with the page: most visits to a
    // project never reach for this.
    const { data, error, isLoading } = useSWR(studioKeys.audioSources, listAudioSources, {
        revalidateOnFocus: false,
    });

    const [copying, setCopying] = useState<string | null>(null);

    // A project is never offered its own recording. The server refuses it too
    // — copying onto itself would clear the destination and then read from the
    // hole it left — but a row you cannot press is better than an error.
    const sources = (data ?? []).filter((source) => source.id !== projectId);

    async function onPick(source: AudioSource) {
        setCopying(source.id);
        try {
            onReused(await reuseAudio(projectId, source.id));
            toast.success("Recording copied. Trim it however this video needs.");
        } catch (err) {
            toast.error(apiErrorMessage(err, "Could not copy that recording."));
        } finally {
            setCopying(null);
        }
    }

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-dashed p-3">
            {error ? (
                <p className="text-xs text-muted-foreground">
                    Could not load the recordings already on the server.
                </p>
            ) : isLoading ? (
                <p className="text-xs text-muted-foreground">Looking for recordings…</p>
            ) : sources.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    No other project is holding a recording right now. Once one does, it can be
                    reused here without uploading it again.
                </p>
            ) : (
                <>
                    <ul className="flex max-h-72 flex-col gap-1 overflow-y-auto">
                        {sources.map((source) => (
                            <li key={source.id}>
                                <button
                                    type="button"
                                    disabled={copying !== null}
                                    onClick={() => void onPick(source)}
                                    className="flex w-full flex-col gap-0.5 rounded-md border px-3 py-2 text-left hover:bg-muted disabled:opacity-60"
                                >
                                    <span className="flex items-baseline justify-between gap-2">
                                        <span className="truncate text-sm font-medium">
                                            {source.working_title}
                                        </span>
                                        <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                                            {copying === source.id
                                                ? "Copying…"
                                                : formatDuration(source.duration)}
                                        </span>
                                    </span>
                                    <span className="truncate font-mono text-[11px] text-muted-foreground">
                                        {source.original_name ?? "recording"}
                                        {source.size ? ` · ${formatBytes(source.size)}` : ""}
                                        {source.topic ? ` · ${source.topic}` : ""}
                                    </span>
                                    <span className="text-[11px] text-muted-foreground">
                                        Last used {formatDateTime(source.updated_at)}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>

                    {/* Said plainly rather than left to be discovered on the
                        Storage page. Two projects trimming one lecture hold two
                        copies of it, and that is a deliberate trade — a shared
                        file would make either project's cleanup delete the
                        other's recording. */}
                    <p className="border-t pt-2 text-[11px] text-muted-foreground">
                        The recording is <strong>copied</strong> into this project, so trimming it
                        here never affects the original — and freeing either one later leaves the
                        other alone. It does use the disk space twice.
                    </p>
                </>
            )}
        </div>
    );
}
