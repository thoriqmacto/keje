"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    apiErrorMessage,
    cancelYouTubeReplacement,
    finalizeProject,
    retryYouTubeReplacement,
    startYouTubeReplacement,
    updateYouTubeMetadata,
} from "@/lib/studio/api";
import type { ContentProject, OldVideoDisposition } from "@/lib/types/studio";

/**
 * The two ways to correct a video that is already on YouTube.
 *
 * They are drawn as two separate actions with two very different weights
 * because they *are* two different operations. YouTube can edit a title,
 * description or privacy on the existing video; it cannot swap the file behind
 * it. Presenting both behind one "Re-upload" button would hide the fact that
 * fixing a typo the wrong way costs the URL, the view count and every comment.
 *
 * So: metadata is an ordinary button, and replacement is a confirmed,
 * consequence-stating flow that has to be typed into.
 */
export function YouTubeCorrections({
    project,
    onChanged,
}: {
    project: ContentProject;
    onChanged: () => void;
}) {
    const replacement = project.replacement.active;

    // A replacement in flight owns this video; nothing else may touch it.
    if (replacement !== null) {
        return <ReplacementProgress project={project} onChanged={onChanged} />;
    }

    return (
        <div className="flex flex-col gap-4 border-t pt-4">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Corrections
            </p>

            <MetadataCorrection project={project} onChanged={onChanged} />
            <RenderedContentCorrection project={project} onChanged={onChanged} />
            <CorrectionWindow project={project} onChanged={onChanged} />
        </div>
    );
}

/**
 * The working files kept so this project can still be corrected, and the way
 * to release them.
 *
 * Shown only while the window is open. Publishing used to delete these
 * immediately, which is why the offer to keep them needs saying out loud —
 * otherwise the disk usage looks like a bug rather than a decision.
 */
function CorrectionWindow({
    project,
    onChanged,
}: {
    project: ContentProject;
    onChanged: () => void;
}) {
    const [finalizing, setFinalizing] = useState(false);

    if (!project.retention.within_correction_window) {
        return null;
    }

    async function onFinalize() {
        setFinalizing(true);
        try {
            await finalizeProject(project.id);
            await onChanged();
            toast.success("Project finalised. The local working files have been released.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not finalise the project."));
        } finally {
            setFinalizing(false);
        }
    }

    return (
        <div className="flex flex-col gap-1.5 border-t pt-4">
            <p className="text-sm font-medium">Working files</p>
            <p className="text-xs text-muted-foreground">
                The recording and the render are still on the server so this video can be
                corrected. Finalising frees that space — after which the project can no
                longer be re-rendered or replaced.
            </p>
            <Button
                size="sm"
                variant="outline"
                className="mt-1 self-start"
                disabled={finalizing}
                onClick={() => void onFinalize()}
            >
                {finalizing ? "Finalising…" : "Finalise project and free space"}
            </Button>
        </div>
    );
}

/** Editing the video in place. The cheap, non-destructive case. */
function MetadataCorrection({
    project,
    onChanged,
}: {
    project: ContentProject;
    onChanged: () => void;
}) {
    const [saving, setSaving] = useState(false);

    async function onUpdate() {
        setSaving(true);
        try {
            await updateYouTubeMetadata(project.id, project.youtube.metadata ?? {});
            await onChanged();
            toast.success(`Updated on YouTube. Video ID remains ${project.youtube.video_id}.`);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not update the video on YouTube."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="flex flex-col gap-1.5">
            <p className="text-sm font-medium">YouTube metadata</p>
            <p className="text-xs text-muted-foreground">
                Title, description, tags, privacy and schedule. The video keeps its link,
                its views and its comments.
            </p>
            <Button
                size="sm"
                variant="outline"
                className="mt-1 self-start"
                disabled={saving}
                onClick={() => void onUpdate()}
            >
                {saving ? "Updating…" : "Update existing YouTube video"}
            </Button>
        </div>
    );
}

/**
 * Replacing the video file.
 *
 * Only offered when the render actually differs from what was published —
 * otherwise this would delete a video and upload an identical one.
 */
function RenderedContentCorrection({
    project,
    onChanged,
}: {
    project: ContentProject;
    onChanged: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [confirmation, setConfirmation] = useState("");
    const [disposition, setDisposition] = useState<OldVideoDisposition>("delete");
    const [starting, setStarting] = useState(false);

    const { can_replace, blocked_message, needs_render, needs_reconnect } = project.replacement;

    async function onStart() {
        setStarting(true);
        try {
            await startYouTubeReplacement(project.id, disposition);
            await onChanged();
            setOpen(false);
            setConfirmation("");
            toast.success("Replacement started. Your published video has not changed yet.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not start the replacement."));
        } finally {
            setStarting(false);
        }
    }

    if (!can_replace) {
        return (
            <div className="flex flex-col gap-1.5">
                <p className="text-sm font-medium">Rendered content</p>
                <p className="text-xs text-muted-foreground">{blocked_message}</p>
                {needs_render && (
                    <p className="text-xs text-muted-foreground">
                        Render the corrected video, then this becomes available.
                    </p>
                )}
                {needs_reconnect && (
                    <a href="/settings/integrations" className="text-xs underline">
                        Reconnect YouTube
                    </a>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm font-medium">Rendered content</p>
            {/* The reason this section exists at all. Said plainly, because
                the alternative is someone editing the description over and
                over wondering why the frames never change. */}
            <p className="text-xs text-amber-700 dark:text-amber-400">
                The current render differs from the video on YouTube. Anything drawn on
                the frames — the speaker, the TEMA number, the titles — can only be
                corrected by replacing the video.
            </p>

            {!open ? (
                <Button
                    size="sm"
                    variant="outline"
                    className="self-start"
                    onClick={() => setOpen(true)}
                >
                    Replace YouTube video
                </Button>
            ) : (
                <div className="flex flex-col gap-3 rounded-md border border-red-300 p-3 dark:border-red-900">
                    <p className="text-sm font-medium">Replace YouTube video?</p>

                    <ol className="list-decimal space-y-0.5 pl-4 text-xs text-muted-foreground">
                        <li>upload the corrected render privately</li>
                        <li>verify the replacement upload</li>
                        <li>
                            {disposition === "delete"
                                ? "permanently delete the current YouTube video"
                                : "set the current YouTube video to private"}
                        </li>
                        <li>move the replacement into its final visibility</li>
                    </ol>

                    {project.youtube.url && (
                        <p className="text-xs">
                            Current video:{" "}
                            <span className="font-mono">{project.youtube.video_id}</span>
                        </p>
                    )}

                    {/* The consequence people do not expect, stated before the
                        confirmation rather than after it. */}
                    <p className="text-xs text-amber-700 dark:text-amber-400">
                        The replacement receives a <strong>new YouTube URL</strong>. Views,
                        likes, comments and shares on the old video will not move to it.
                    </p>

                    <fieldset className="flex flex-col gap-1.5">
                        <legend className="mb-1 text-xs font-medium">Old video handling</legend>
                        <label className="flex items-center gap-2 text-xs">
                            <input
                                type="radio"
                                name="disposition"
                                checked={disposition === "delete"}
                                onChange={() => setDisposition("delete")}
                            />
                            Delete old video permanently
                        </label>
                        <label className="flex items-center gap-2 text-xs">
                            <input
                                type="radio"
                                name="disposition"
                                checked={disposition === "keep_private"}
                                onChange={() => setDisposition("keep_private")}
                            />
                            Keep old video, set to private
                        </label>
                    </fieldset>

                    {/* Only permanent deletion is typed for. Hiding a video is
                        reversible from YouTube Studio; deleting one is not, and
                        a confirmation that guards both equally teaches people
                        to type through it. */}
                    {disposition === "delete" && (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="replace_confirm" className="text-xs">
                                Type REPLACE to confirm
                            </Label>
                            <Input
                                id="replace_confirm"
                                value={confirmation}
                                onChange={(event) => setConfirmation(event.target.value)}
                                placeholder="REPLACE"
                            />
                        </div>
                    )}

                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                setOpen(false);
                                setConfirmation("");
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            disabled={
                                starting || (disposition === "delete" && confirmation !== "REPLACE")
                            }
                            onClick={() => void onStart()}
                        >
                            {starting ? "Starting…" : "Replace video"}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * A replacement in flight.
 *
 * The checklist leads with what is safe rather than what is happening, because
 * the question during a replacement is never "which step is running" — it is
 * "is my video still up".
 */
function ReplacementProgress({
    project,
    onChanged,
}: {
    project: ContentProject;
    onChanged: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const replacement = project.replacement.active;

    if (replacement === null) return null;

    async function act(
        action: (id: string) => Promise<unknown>,
        message: string,
        failure: string,
    ) {
        setBusy(true);
        try {
            await action(project.id);
            await onChanged();
            toast.success(message);
        } catch (error) {
            toast.error(apiErrorMessage(error, failure));
        } finally {
            setBusy(false);
        }
    }

    const uploaded = replacement.new_video_id !== null;
    const disposed = replacement.old_disposed_at !== null;
    const done = replacement.completed_at !== null;

    return (
        <div className="flex flex-col gap-3 border-t pt-4">
            <p className="text-sm font-medium">Replacing YouTube video</p>

            <ul className="flex flex-col gap-1 text-xs">
                <Step done={uploaded} label="Replacement uploaded privately" detail={replacement.new_video_id} />
                <Step
                    done={disposed}
                    label={
                        replacement.old_disposition === "delete"
                            ? "Previous video removed"
                            : "Previous video set to private"
                    }
                    detail={replacement.old_video_id}
                />
                <Step done={done} label="Publication finalised" detail={null} />
            </ul>

            {/* The reassurance, computed on the server from whether the old
                video has actually been disposed of — not guessed from a status
                name, which cannot tell a failed upload from a failed finalise. */}
            {replacement.old_still_current && (
                <p className="text-xs text-muted-foreground">
                    Your published video has not changed yet.
                </p>
            )}

            {replacement.is_failed && (
                <p className="text-xs text-red-600 dark:text-red-400">
                    {replacement.error ?? replacement.blocking_summary}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                {replacement.is_failed && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                            void act(
                                retryYouTubeReplacement,
                                "Continuing from where it stopped.",
                                "Could not continue the replacement.",
                            )
                        }
                    >
                        {/* Named for the step it will actually resume, so it
                            never reads as "upload again" on a replacement that
                            has already uploaded. */}
                        {replacement.stage === "dispose_old"
                            ? "Retry removing old video"
                            : replacement.stage === "finalize"
                              ? "Retry finalisation"
                              : "Retry replacement upload"}
                    </Button>
                )}

                {replacement.is_cancellable && (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                            void act(
                                cancelYouTubeReplacement,
                                "Replacement cancelled.",
                                "Could not cancel the replacement.",
                            )
                        }
                    >
                        {uploaded ? "Cancel and delete new copy" : "Cancel replacement"}
                    </Button>
                )}
            </div>

            {/* Once the old video is gone there is nothing to restore, so
                cancelling would only mean abandoning the new one too. */}
            {disposed && !done && (
                <p className="text-xs text-muted-foreground">
                    The previous video has been removed, so this replacement can no longer be
                    undone — only finished.
                </p>
            )}
        </div>
    );
}

function Step({
    done,
    label,
    detail,
}: {
    done: boolean;
    label: string;
    detail: string | null;
}) {
    return (
        <li className="flex items-center gap-2">
            <span aria-hidden className={done ? "text-green-600" : "text-muted-foreground"}>
                {done ? "✓" : "○"}
            </span>
            <span className={done ? "" : "text-muted-foreground"}>{label}</span>
            {detail && <span className="font-mono text-muted-foreground">{detail}</span>}
        </li>
    );
}
