"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    apiErrorMessage,
    generateThumbnailFrames,
    pushThumbnail,
    selectThumbnail,
    thumbnailFrameUrl,
} from "@/lib/studio/api";
import { formatTimecode, parseTimecode } from "@/lib/studio/timecode";
import type { ContentProject } from "@/lib/types/studio";

/**
 * Pick a frame from the rendered video as the YouTube thumbnail.
 *
 * Three candidates at a quarter, half and three-quarters in — never the first
 * or last frame, because a lecture opens and closes on near-static artwork and
 * a thumbnail of the title card is what the video already looks like in a list.
 *
 * Pushing is its own action, separate from the upload. A thumbnail that
 * YouTube refuses is a thumbnail problem: the video is published and stays
 * published, and "Retry thumbnail" can never reach videos.insert.
 */
export function ThumbnailPicker({
    project,
    onChanged,
}: {
    project: ContentProject;
    onChanged: () => void;
}) {
    const [candidates, setCandidates] = useState<{ timestamp: number; url: string }[]>([]);
    const [custom, setCustom] = useState("");
    const [busy, setBusy] = useState(false);

    const selected = project.thumbnail.timestamp;
    const status = project.thumbnail.youtube_status;

    async function run(work: () => Promise<void>, failure: string) {
        setBusy(true);
        try {
            await work();
        } catch (error) {
            toast.error(apiErrorMessage(error, failure));
        } finally {
            setBusy(false);
        }
    }

    const onGenerate = () =>
        run(async () => {
            setCandidates(await generateThumbnailFrames(project.id));
        }, "Could not read frames from the video.");

    const onCustom = () =>
        run(async () => {
            const at = parseTimecode(custom);
            if (at === null) {
                toast.error("Enter a time as mm:ss or seconds.");
                return;
            }
            const frames = await generateThumbnailFrames(project.id, at);
            setCandidates((existing) => [...frames, ...existing]);
        }, "Could not read that frame.");

    const onSelect = (timestamp: number) =>
        run(async () => {
            await selectThumbnail(project.id, timestamp);
            onChanged();
            toast.success("Thumbnail chosen.");
        }, "Could not save that thumbnail.");

    const onPush = () =>
        run(async () => {
            await pushThumbnail(project.id);
            onChanged();
            toast.success("Thumbnail sent to YouTube.");
        }, "YouTube did not accept the thumbnail.");

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" disabled={busy} onClick={() => void onGenerate()}>
                    {busy ? "Working…" : "Suggest frames"}
                </Button>
                {project.youtube.video_id && project.thumbnail.selected && (
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => void onPush()}>
                        {status === "failed" ? "Retry thumbnail" : "Send to YouTube"}
                    </Button>
                )}
            </div>

            {candidates.length > 0 && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {candidates.map((frame) => (
                        <button
                            key={frame.timestamp}
                            type="button"
                            disabled={busy}
                            onClick={() => void onSelect(frame.timestamp)}
                            className={`flex flex-col gap-1 rounded-md border p-1 text-left ${
                                selected === frame.timestamp ? "ring-2 ring-primary" : ""
                            }`}
                        >
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            <img
                                src={thumbnailFrameUrl(project.id, frame.timestamp)}
                                alt={`Frame at ${formatTimecode(frame.timestamp)}`}
                                className="aspect-video w-full rounded object-cover"
                            />
                            <span className="px-1 font-mono text-xs text-muted-foreground">
                                {formatTimecode(frame.timestamp)}
                                {selected === frame.timestamp && " · chosen"}
                            </span>
                        </button>
                    ))}
                </div>
            )}

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="thumb_at">Or a specific time</Label>
                <div className="flex gap-2">
                    <Input
                        id="thumb_at"
                        placeholder="04:22"
                        value={custom}
                        onChange={(event) => setCustom(event.target.value)}
                    />
                    <Button variant="outline" disabled={busy} onClick={() => void onCustom()}>
                        Generate
                    </Button>
                </div>
            </div>

            {status === "set" && (
                <p className="text-xs text-emerald-600 dark:text-emerald-400">
                    YouTube has this thumbnail.
                </p>
            )}

            {/* Never collapsed into "YouTube: Failed" — the video is fine. */}
            {status === "failed" && (
                <div className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                    <p className="font-medium">The thumbnail was not accepted</p>
                    <p>{project.thumbnail.youtube_error}</p>
                    <p className="mt-1 text-xs">
                        The video itself is unaffected and stays published.
                    </p>
                </div>
            )}
        </div>
    );
}
