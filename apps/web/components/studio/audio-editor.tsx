"use client";

import { useRef, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { apiErrorMessage, saveAudioEdits } from "@/lib/studio/api";
import { formatTimecode, parseTimecode } from "@/lib/studio/timecode";
import type { ContentProject } from "@/lib/types/studio";

/**
 * Sections of the recording to leave out.
 *
 * Non-destructive throughout: the uploaded MP3 is never rewritten, and this
 * only records decisions the renderer applies at encode time. A mis-typed
 * timestamp therefore costs a re-render, not the lecture.
 *
 * A plain <audio> element rather than a waveform library. Finding the moment
 * to cut is a listening job, and the accurate way to do it is to play up to
 * the spot and take the playhead — which is what "Use current time" does.
 * A canvas waveform would look better and help less.
 */
export function AudioEditorCard({
    project,
    audioUrl,
    onSaved,
}: {
    project: ContentProject;
    /** Short-lived signed link — an <audio> element cannot send a token. */
    audioUrl: string | null;
    onSaved: () => void;
}) {
    const player = useRef<HTMLAudioElement>(null);
    const [currentTime, setCurrentTime] = useState(0);
    const [start, setStart] = useState("");
    const [end, setEnd] = useState("");
    const [saving, setSaving] = useState(false);

    const summary = project.audio_edits;
    const cuts = summary?.cuts ?? [];
    const source = summary?.source_duration ?? null;

    async function persist(next: { start: number; end: number }[], message: string) {
        setSaving(true);
        try {
            await saveAudioEdits(
                project.id,
                next.map((cut) => ({ type: "cut", start: cut.start, end: cut.end })),
            );
            await onSaved();
            toast.success(message);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not save the removed sections."));
        } finally {
            setSaving(false);
        }
    }

    async function onAdd() {
        const from = parseTimecode(start);
        const to = parseTimecode(end);

        // The server validates all of this again — this is only to avoid a
        // round trip for something the person can see is wrong.
        if (from === null || to === null) {
            toast.error("Enter both times as mm:ss or seconds.");
            return;
        }

        if (to <= from) {
            toast.error("The end of a removed section must come after its start.");
            return;
        }

        await persist(
            [...cuts.map((cut) => ({ start: cut.start, end: cut.end })), { start: from, end: to }],
            `Removing ${formatTimecode(from)} to ${formatTimecode(to)}.`,
        );
        setStart("");
        setEnd("");
    }

    async function onRemove(index: number) {
        await persist(
            cuts.filter((_, i) => i !== index).map((cut) => ({ start: cut.start, end: cut.end })),
            "Section restored.",
        );
    }

    /** Play the seconds either side of a cut, so its effect can be heard. */
    function preview(cut: { start: number; end: number }) {
        const audio = player.current;
        if (!audio) return;

        audio.currentTime = Math.max(0, cut.start - 3);
        void audio.play();
    }

    return (
        <div className="flex flex-col gap-4">
            {audioUrl === null ? (
                <p className="text-sm text-muted-foreground">Preparing playback…</p>
            ) : (
            <audio
                ref={player}
                src={audioUrl}
                controls
                preload="metadata"
                className="w-full"
                onTimeUpdate={(event) => setCurrentTime(event.currentTarget.currentTime)}
            />
            )}

            <p className="text-xs text-muted-foreground">
                Playing: <span className="font-mono">{formatTimecode(currentTime)}</span>
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="cut_start">Remove from</Label>
                    <div className="flex gap-2">
                        <Input
                            id="cut_start"
                            placeholder="00:18"
                            value={start}
                            onChange={(event) => setStart(event.target.value)}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStart(formatTimecode(currentTime))}
                        >
                            Now
                        </Button>
                    </div>
                </div>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="cut_end">Remove to</Label>
                    <div className="flex gap-2">
                        <Input
                            id="cut_end"
                            placeholder="00:23"
                            value={end}
                            onChange={(event) => setEnd(event.target.value)}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setEnd(formatTimecode(currentTime))}
                        >
                            Now
                        </Button>
                    </div>
                </div>
            </div>

            <Button className="self-start" disabled={saving} onClick={() => void onAdd()}>
                {saving ? "Saving…" : "Add removal"}
            </Button>

            {cuts.length > 0 && (
                <div className="flex flex-col gap-2 border-t pt-4">
                    <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Removed sections
                    </p>

                    {cuts.map((cut, index) => (
                        <div
                            key={`${cut.start}-${cut.end}`}
                            className="flex flex-wrap items-center justify-between gap-2 text-sm"
                        >
                            <span className="font-mono">
                                {formatTimecode(cut.start)} → {formatTimecode(cut.end)}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {(cut.end - cut.start).toFixed(2)}s removed
                            </span>
                            <span className="flex gap-2">
                                <Button size="sm" variant="outline" onClick={() => preview(cut)}>
                                    Preview
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={saving}
                                    onClick={() => void onRemove(index)}
                                >
                                    Restore
                                </Button>
                            </span>
                        </div>
                    ))}
                </div>
            )}

            {source !== null && (
                <dl className="grid grid-cols-[10rem_1fr] gap-y-1 border-t pt-4 text-sm">
                    <dt className="text-muted-foreground">Original</dt>
                    <dd className="font-mono">{formatTimecode(source)}</dd>

                    <dt className="text-muted-foreground">Removed</dt>
                    <dd className="font-mono">{formatTimecode(summary?.removed_duration ?? 0)}</dd>

                    {/* What the render will actually be, which is also what
                        the progress bar measures itself against. */}
                    <dt className="text-muted-foreground">Rendered length</dt>
                    <dd className="font-mono font-medium">
                        {formatTimecode(summary?.effective_duration ?? source)}
                    </dd>
                </dl>
            )}
        </div>
    );
}
