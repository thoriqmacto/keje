"use client";

import { useRef, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { apiErrorMessage } from "@/lib/studio/api";
import { formatBytes, formatDuration } from "@/lib/studio/format";
import type { ContentProject } from "@/lib/types/studio";

/**
 * One file input plus the facts the server detected about the file.
 *
 * Those facts come from ffprobe, not from the browser — showing the real codec
 * and duration is how the user knows the recording is usable before rendering.
 */
export function MediaUploader({
    label,
    accept,
    hint,
    detected,
    onUpload,
}: {
    label: string;
    accept: string;
    hint: string;
    detected: { name: string | null; rows: [string, string][] } | null;
    onUpload: (file: File, onProgress: (percent: number) => void) => Promise<ContentProject>;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [progress, setProgress] = useState(0);

    async function onPick(file: File | undefined) {
        if (!file) return;

        setUploading(true);
        setProgress(0);
        try {
            await onUpload(file, setProgress);
            toast.success(`${label} uploaded.`);
        } catch (error) {
            toast.error(apiErrorMessage(error, `Could not upload the ${label.toLowerCase()}.`));
        } finally {
            setUploading(false);
            if (input.current) input.current.value = "";
        }
    }

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <Label>{label}</Label>
                    <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={uploading}
                    onClick={() => input.current?.click()}
                >
                    {uploading ? "Uploading…" : detected ? "Replace" : "Choose file"}
                </Button>
            </div>

            <input
                ref={input}
                type="file"
                accept={accept}
                className="hidden"
                onChange={(event) => void onPick(event.target.files?.[0])}
            />

            {uploading && (
                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-blue-500 transition-[width]"
                        style={{ width: `${Math.max(2, progress)}%` }}
                    />
                </div>
            )}

            {detected && (
                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 border-t pt-3 text-xs">
                    <dt className="text-muted-foreground">File</dt>
                    <dd className="truncate font-mono" title={detected.name ?? ""}>
                        {detected.name ?? "—"}
                    </dd>
                    {detected.rows.map(([key, value]) => (
                        <div key={key} className="contents">
                            <dt className="text-muted-foreground">{key}</dt>
                            <dd className="font-mono">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

/** Detected-facts block for the audio uploader. */
export function audioDetails(project: ContentProject) {
    const audio = project.source_audio;
    if (!audio) return null;

    return {
        name: audio.original_name,
        rows: [
            ["Duration", formatDuration(audio.duration)],
            ["Size", formatBytes(audio.size)],
            ["Codec", audio.codec ?? "—"],
            ["Sample rate", audio.sample_rate ? `${audio.sample_rate} Hz` : "—"],
            ["Channels", audio.channels?.toString() ?? "—"],
        ] as [string, string][],
    };
}

/** Detected-facts block for the background uploader. */
export function backgroundDetails(project: ContentProject) {
    const background = project.background_image;
    if (!background) return null;

    return {
        name: background.original_name,
        rows: [
            [
                "Dimensions",
                background.width && background.height
                    ? `${background.width} × ${background.height}`
                    : "—",
            ],
            ["Size", formatBytes(background.size)],
        ] as [string, string][],
    };
}
