import type { DriveStatus, RenderStatus, YouTubeStatus } from "@/lib/types/studio";

/**
 * Status pill for one of the three independent pipelines.
 *
 * Render, Drive and YouTube each get their own badge — the studio never
 * collapses them into a single "project status", because a failed Drive backup
 * says nothing about the render.
 */

type Tone = "neutral" | "info" | "progress" | "success" | "danger";

const TONE_CLASSES: Record<Tone, string> = {
    neutral: "bg-muted text-muted-foreground",
    info: "bg-blue-500/10 text-blue-600 dark:text-blue-400",
    progress: "bg-amber-500/10 text-amber-600 dark:text-amber-400",
    success: "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400",
    danger: "bg-red-500/10 text-red-600 dark:text-red-400",
};

const RENDER_TONES: Record<RenderStatus, Tone> = {
    draft: "neutral",
    media_ready: "info",
    queued: "progress",
    rendering: "progress",
    rendered: "success",
    failed: "danger",
};

const DRIVE_TONES: Record<DriveStatus, Tone> = {
    pending: "neutral",
    uploading: "progress",
    uploaded: "success",
    failed: "danger",
};

const YOUTUBE_TONES: Record<YouTubeStatus, Tone> = {
    pending: "neutral",
    uploading: "progress",
    uploaded: "success",
    scheduled: "info",
    published: "success",
    failed: "danger",
};

export function ProjectStatusBadge({
    pipeline,
    status,
    label,
}: {
    pipeline: "render" | "drive" | "youtube";
    status: string;
    label: string;
}) {
    const tone =
        pipeline === "render"
            ? (RENDER_TONES[status as RenderStatus] ?? "neutral")
            : pipeline === "drive"
              ? (DRIVE_TONES[status as DriveStatus] ?? "neutral")
              : (YOUTUBE_TONES[status as YouTubeStatus] ?? "neutral");

    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${TONE_CLASSES[tone]}`}
        >
            {label}
        </span>
    );
}
