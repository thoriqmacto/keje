"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { KajianTematikPreview } from "@/components/studio/kajian-tematik-preview";
import {
    MediaUploader,
    audioDetails,
    backgroundDetails,
} from "@/components/studio/media-uploader";
import { RenderProgress, useRenderStatus } from "@/components/studio/render-progress";
import { ProjectStatusBadge } from "@/components/studio/status-badge";
import { TemplateTextForm } from "@/components/studio/template-text-form";
import { YouTubeMetadataForm } from "@/components/studio/youtube-form";
import { YouTubeDestinationSummary } from "@/components/studio/youtube-destination";
import { YouTubeCorrections } from "@/components/studio/youtube-corrections";
import { YouTubeHistory } from "@/components/studio/youtube-history";
import {
    youtubeBadgeLabel,
    youtubeBadgeStatus,
    youtubeSchedule,
    type YouTubeBadgeInput,
} from "@/lib/studio/youtube-badge";
import { ProjectPropertiesCard } from "@/components/studio/project-properties";
import { AudioEditorCard } from "@/components/studio/audio-editor";
import { PostRenderOptions } from "@/components/studio/post-render-options";
import { ThumbnailPicker } from "@/components/studio/thumbnail-picker";
import { useDocumentTitle } from "@/lib/use-document-title";
import {
    apiErrorMessage,
    backupToDrive,
    deleteProject,
    getPreview,
    getProject,
    startRender,
    syncYouTubeStatus,
    studioKeys,
    updateProject,
    uploadAudio,
    uploadBackground,
    uploadToYouTube,
} from "@/lib/studio/api";
import { formatBytes, formatDateTime, formatDuration } from "@/lib/studio/format";
import { getMediaLinks, useAuthedObjectUrl, type MediaLinks } from "@/lib/studio/media";
import type { ContentProject, YouTubeMetadata } from "@/lib/types/studio";

/**
 * The badge's inputs on the detail page.
 *
 * The full project carries the replacement inline rather than the two booleans
 * the list summary uses, so this adapts it to the same shared function — one
 * place decides what the badge says, on both screens.
 */
function detailBadge(project: ContentProject): YouTubeBadgeInput {
    return {
        label: project.youtube.label,
        remoteLabel: project.youtube.remote.label,
        isReplacing: project.replacement.active !== null,
        replacementFailed: project.replacement.active?.is_failed ?? false,
        hasVideo: project.youtube.video_id !== null,
    };
}

export default function ProjectDetailClient({ projectId }: { projectId: string }) {
    const router = useRouter();

    const {
        data: project,
        isLoading,
        mutate,
    } = useSWR(studioKeys.project(projectId), () => getProject(projectId));

    const render = useRenderStatus(projectId, project?.render.status ?? "draft");
    // The working title is behind a bearer token, so the page renders with
    // its static metadata title and refines it once the project arrives.
    useDocumentTitle(project?.working_title);

    const renderStatus = render.data?.status ?? project?.render.status ?? "draft";

    // What should happen once the render succeeds. Sent with the request and
    // snapshotted onto the attempt, so it cannot drift while the job queues.
    const [postActions, setPostActions] = useState({
        drive_backup: false,
        youtube_upload: false,
    });

    // Template text is edited locally and saved explicitly, so typing does not
    // fire a request per keystroke.
    const [primaryTitle, setPrimaryTitle] = useState("");
    const [subtitle, setSubtitle] = useState("");
    const [partNumber, setPartNumber] = useState("");
    const [metadata, setMetadata] = useState<YouTubeMetadata>({});
    const [savingText, setSavingText] = useState(false);
    const [savingMeta, setSavingMeta] = useState(false);
    const [links, setLinks] = useState<MediaLinks | null>(null);

    useEffect(() => {
        if (!project) return;
        setPrimaryTitle(project.primary_title ?? "");
        setSubtitle(project.subtitle ?? "");
        setPartNumber(project.part_number?.toString() ?? "");
        setMetadata(project.youtube.metadata ?? {});
    }, [project]);

    const backgroundUrl = useAuthedObjectUrl(
        project?.background_image ? `/content-projects/${projectId}/background` : null,
    );

    // The resolved layout doubles as validation: a 422 here is what the user
    // needs to see before spending minutes on a render.
    const { data: layout, error: layoutError } = useSWR(
        project ? studioKeys.preview(projectId) : null,
        () => getPreview(projectId),
    );

    const layoutMessage = layoutError
        ? apiErrorMessage(layoutError, "This text cannot be laid out on the template.")
        : null;

    // Refresh the project once a render settles so output size/links appear.
    useEffect(() => {
        if (renderStatus === "rendered" || renderStatus === "failed") {
            void mutate();
        }
    }, [renderStatus, mutate]);

    // Also fetched when there is only source audio and no render: the audio
    // editor needs a signed link long before anything has been encoded.
    const needsLinks = Boolean(project?.render.has_output || project?.source_audio?.stored);

    useEffect(() => {
        if (needsLinks) {
            getMediaLinks(projectId).then(setLinks).catch(() => setLinks(null));
        } else {
            setLinks(null);
        }
    }, [needsLinks, projectId, project?.render.rendered_at]);

    const suggestedYouTubeTitle = useMemo(() => {
        if (!project) return "";

        const parts = [
            [primaryTitle, subtitle].filter(Boolean).join(" "),
            project.topic
                ? `${project.topic.name}${project.topic_sequence ? ` #${project.topic_sequence}` : ""}`
                : null,
            project.part_number ? `Part ${project.part_number}` : null,
        ].filter(Boolean);

        return parts.join(" | ").slice(0, 100);
    }, [project, primaryTitle, subtitle]);

    async function saveText() {
        setSavingText(true);
        try {
            await updateProject(projectId, {
                primary_title: primaryTitle || null,
                subtitle: subtitle || null,
                part_number: partNumber ? Number(partNumber) : null,
            });
            await mutate();
            toast.success("Title information saved.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not save the title information."));
        } finally {
            setSavingText(false);
        }
    }

    async function saveMetadata() {
        setSavingMeta(true);
        try {
            await updateProject(projectId, { youtube_metadata: metadata });
            await mutate();
            toast.success("YouTube metadata saved.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not save the YouTube metadata."));
        } finally {
            setSavingMeta(false);
        }
    }

    async function onRender() {
        try {
            await startRender(projectId, postActions);
            toast.success("Render queued.");
            await Promise.all([mutate(), render.mutate()]);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not queue the render."));
        }
    }

    async function onDelete() {
        try {
            await deleteProject(projectId);
            toast.success("Project deleted.");
            router.push("/studio");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not delete the project."));
        }
    }

    if (isLoading || !project) {
        return (
            <div className="mx-auto w-full max-w-6xl px-4 py-10 text-sm text-muted-foreground">
                Loading…
            </div>
        );
    }

    const inFlight = renderStatus === "queued" || renderStatus === "rendering";

    return (
        <section className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-10">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <Link href="/studio" className="text-xs text-muted-foreground hover:underline">
                        ← Content Studio
                    </Link>
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {project.working_title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {project.topic?.name ?? "No topic"}
                        {project.topic_sequence ? ` · TEMA #${project.topic_sequence}` : ""}
                        {project.speaker ? ` · ${project.speaker.name}` : ""}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <ProjectStatusBadge
                        pipeline="render"
                        status={project.render.status}
                        label={project.render.label}
                    />
                    <ProjectStatusBadge
                        pipeline="drive"
                        status={project.drive.status}
                        label={`Drive: ${project.drive.label}`}
                    />
                    <ProjectStatusBadge
                        pipeline="youtube"
                        status={youtubeBadgeStatus(detailBadge(project), project.youtube.status)}
                        label={`YouTube: ${youtubeBadgeLabel(detailBadge(project))}`}
                    />
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-[1.15fr_1fr]">
                {/* ── Preview ────────────────────────────────────────────── */}
                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Kajian Tematik preview</CardTitle>
                            <CardDescription>
                                Approximation of the rendered frame, positioned from the same
                                template layout FFmpeg uses.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {layout ? (
                                <KajianTematikPreview
                                    layout={layout}
                                    backgroundUrl={backgroundUrl}
                                />
                            ) : layoutMessage ? (
                                <p className="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
                                    {layoutMessage}
                                </p>
                            ) : (
                                <p className="text-sm text-muted-foreground">Building preview…</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* ── Render ─────────────────────────────────────────── */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Render</CardTitle>
                            <CardDescription>
                                Runs on the Laravel queue with FFmpeg. You can leave this page.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <RenderProgress
                                status={renderStatus}
                                progress={render.data?.progress ?? 0}
                                stalledReason={render.data?.stalled_reason ?? null}
                            />

                            {/* The MP4 exists and is a real render — of an
                                earlier revision. Saying "Rendered" here would
                                claim it matches the project as it stands. */}
                            {project.render.stale && !inFlight && (
                                <div className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                                    <p className="font-medium">This render is out of date</p>
                                    <p>
                                        The project changed after it was made, so the video below
                                        no longer matches. Render again to bring it up to date.
                                    </p>
                                </div>
                            )}

                            {!inFlight && (
                                <PostRenderOptions
                                    value={postActions}
                                    onChange={setPostActions}
                                    disabled={!project.is_renderable}
                                />
                            )}

                            {project.render.error && (
                                <div className="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
                                    <p className="font-medium">Render failed</p>
                                    <p>{project.render.error}</p>
                                </div>
                            )}

                            {!project.is_renderable && !project.render.media_pruned_at && (
                                <p className="text-sm text-muted-foreground">
                                    Upload the lecture audio and a background image, and enter a
                                    primary title, to enable rendering.
                                </p>
                            )}

                            {/* A pruned project looks identical to an empty one
                                unless we say otherwise: its paths are gone, so
                                is_renderable and has_output are both false. */}
                            {project.render.media_pruned_at && (
                                <div className="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                                    <p className="font-medium text-foreground">
                                        Stored in Google Drive
                                    </p>
                                    <p>
                                        The source audio, artwork and rendered MP4 were removed from
                                        the server on{" "}
                                        {formatDateTime(project.render.media_pruned_at)} to free
                                        disk. This project cannot be re-rendered — its source audio
                                        is no longer held here.
                                    </p>
                                    {project.drive.web_view_link && (
                                        <a
                                            href={project.drive.web_view_link}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-1 inline-block underline"
                                        >
                                            Open the video in Google Drive
                                        </a>
                                    )}
                                </div>
                            )}

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    onClick={() => void onRender()}
                                    disabled={inFlight || !project.is_renderable}
                                >
                                    {inFlight
                                        ? "Rendering…"
                                        : project.render.status === "rendered"
                                          ? "Render again"
                                          : project.render.status === "failed"
                                            ? "Retry render"
                                            : "Render video"}
                                </Button>

                                {links?.download_url && (
                                    <Button asChild variant="outline">
                                        <a href={links.download_url}>Download MP4</a>
                                    </Button>
                                )}
                            </div>

                            {(project.render.has_output || project.render.media_pruned_at) && (
                                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 border-t pt-3 text-xs">
                                    <dt className="text-muted-foreground">Rendered</dt>
                                    <dd>{formatDateTime(project.render.rendered_at)}</dd>
                                    <dt className="text-muted-foreground">Size</dt>
                                    <dd className="font-mono">
                                        {formatBytes(project.render.output_size)}
                                    </dd>
                                    <dt className="text-muted-foreground">Duration</dt>
                                    <dd className="font-mono">
                                        {formatDuration(project.render.output_duration)}
                                    </dd>
                                    <dt className="text-muted-foreground">Attempts</dt>
                                    <dd className="font-mono">{project.render.attempts}</dd>
                                </dl>
                            )}

                            {links?.video_url && (
                                // Signed URL, so the element can stream with
                                // range requests instead of buffering a blob.
                                <video
                                    key={links.video_url}
                                    controls
                                    preload="metadata"
                                    className="w-full rounded-lg border bg-black"
                                    src={links.video_url}
                                />
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ── Editing ────────────────────────────────────────────── */}
                <div className="flex flex-col gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Media</CardTitle>
                            <CardDescription>
                                Upload the original recording — no Audacity step needed. The
                                background should be clean artwork with no title text.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <MediaUploader
                                label="Lecture audio"
                                accept=".mp3,.mpeg,.mpg,.m4a,.wav,.aac,audio/*"
                                hint="MP3, MPEG, M4A, WAV or AAC."
                                detected={audioDetails(project)}
                                onUpload={async (file, onProgress) => {
                                    const updated = await uploadAudio(projectId, file, onProgress);
                                    await mutate();
                                    return updated;
                                }}
                            />
                            <MediaUploader
                                label="Background image"
                                accept=".jpg,.jpeg,.png,.webp,image/*"
                                hint="JPG, PNG or WebP. Cropped to fill 1280×720."
                                detected={backgroundDetails(project)}
                                onUpload={async (file, onProgress) => {
                                    const updated = await uploadBackground(
                                        projectId,
                                        file,
                                        onProgress,
                                    );
                                    await mutate();
                                    return updated;
                                }}
                            />
                        </CardContent>
                    </Card>

                    {/* Editable for the project's whole life. A project
                        created without a speaker used to be stuck that way,
                        which is what showed as "—" in the Studio list. */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Project properties</CardTitle>
                            <CardDescription>
                                Grouping and attribution. Changing the topic, TEMA or speaker
                                changes the video, so it will need rendering again.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ProjectPropertiesCard project={project} onSaved={() => void mutate()} />
                        </CardContent>
                    </Card>

                    {project.source_audio?.stored && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Audio editing</CardTitle>
                                <CardDescription>
                                    Remove sections you do not want. The uploaded recording is
                                    never changed — the cuts are applied when it renders.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <AudioEditorCard
                                    project={project}
                                    audioUrl={links?.audio_url ?? null}
                                    onSaved={() => void mutate()}
                                />
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Kajian Tematik title</CardTitle>
                            <CardDescription>
                                Type in normal case — the template uppercases it.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <TemplateTextForm
                                primaryTitle={primaryTitle}
                                subtitle={subtitle}
                                partNumber={partNumber}
                                layoutError={layoutMessage}
                                saving={savingText}
                                onChange={(patch) => {
                                    if (patch.primaryTitle !== undefined)
                                        setPrimaryTitle(patch.primaryTitle);
                                    if (patch.subtitle !== undefined) setSubtitle(patch.subtitle);
                                    if (patch.partNumber !== undefined)
                                        setPartNumber(patch.partNumber);
                                }}
                                onSave={() => void saveText()}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>YouTube metadata</CardTitle>
                            <CardDescription>
                                Saved with the project. Google does not need to be connected to
                                render.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <YouTubeMetadataForm
                                topicPlaylistTitle={project.topic?.youtube_playlist_id ?? null}
                                metadata={metadata}
                                saving={savingMeta}
                                onChange={(patch) =>
                                    setMetadata((current) => ({ ...current, ...patch }))
                                }
                                onSave={() => void saveMetadata()}
                                onPrefill={() =>
                                    setMetadata((current) => ({
                                        ...current,
                                        title: suggestedYouTubeTitle,
                                    }))
                                }
                            />
                        </CardContent>
                    </Card>

                    <PublicationCard
                        project={project}
                        onDrive={async () => {
                            try {
                                await backupToDrive(projectId);
                                toast.success("Google Drive backup queued.");
                                await mutate();
                            } catch (error) {
                                toast.error(
                                    apiErrorMessage(error, "Could not queue the Drive backup."),
                                );
                            }
                        }}
                        onChanged={() => void mutate()}
                        onYouTube={async () => {
                            try {
                                await uploadToYouTube(projectId, metadata);
                                toast.success("YouTube upload queued.");
                                await mutate();
                            } catch (error) {
                                toast.error(
                                    apiErrorMessage(error, "Could not queue the YouTube upload."),
                                );
                            }
                        }}
                    />

                    <Card>
                        <CardHeader>
                            <CardTitle>Danger zone</CardTitle>
                            <CardDescription>
                                Deletes the project, its source media and any renders.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button variant="outline" onClick={() => void onDelete()}>
                                Delete project
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </section>
    );
}

/** Drive and YouTube — independent pipelines, each with its own next action. */
function PublicationCard({
    project,
    onDrive,
    onYouTube,
    onChanged,
}: {
    project: ContentProject;
    onDrive: () => Promise<void>;
    onYouTube: () => Promise<void>;
    onChanged: () => void;
}) {
    const [syncing, setSyncing] = useState(false);

    /** Read-only: never changes privacy, never re-uploads. */
    async function onSyncYouTube() {
        setSyncing(true);
        try {
            await syncYouTubeStatus(project.id);
            onChanged();
            toast.success("Refreshed from YouTube.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not reach YouTube."));
        } finally {
            setSyncing(false);
        }
    }

    const rendered = project.render.has_output;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Backup and publish</CardTitle>
                <CardDescription>
                    Independent of each other — a failed Drive backup never invalidates the render.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
                <div className="flex flex-col gap-2">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-medium">Google Drive</span>
                        <ProjectStatusBadge
                            pipeline="drive"
                            status={project.drive.status}
                            label={project.drive.label}
                        />
                    </div>
                    {project.drive.error && (
                        <p className="text-xs text-red-600 dark:text-red-400">
                            {project.drive.error}
                        </p>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!rendered}
                            onClick={() => void onDrive()}
                        >
                            {project.drive.status === "failed"
                                ? "Retry backup"
                                : "Back up to Drive"}
                        </Button>
                        {project.drive.web_view_link && (
                            <a
                                href={project.drive.web_view_link}
                                target="_blank"
                                rel="noreferrer"
                                className="text-xs underline"
                            >
                                Open in Drive
                            </a>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-2 border-t pt-4">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-medium">YouTube</span>
                        <ProjectStatusBadge
                            pipeline="youtube"
                            status={youtubeBadgeStatus(detailBadge(project), project.youtube.status)}
                            label={youtubeBadgeLabel(detailBadge(project))}
                        />
                    </div>
                    {project.youtube.error && (
                        <p className="text-xs text-red-600 dark:text-red-400">
                            {project.youtube.error}
                        </p>
                    )}
                    {/* The same distinction the Studio list draws: a schedule
                        YouTube is holding, versus one this project has only
                        decided on. Both belong here — the second is what a
                        queued project is waiting to do — but reading them as
                        the same promise is how somebody comes to believe a
                        video is safely scheduled when nothing was uploaded. */}
                    <YouTubeScheduleLine project={project} />
                    {/* Where this video is about to go, resolved to names.
                        Shown before the upload button on purpose: the moment
                        to notice a wrong destination is before publishing. */}
                    <YouTubeDestinationSummary project={project} onChanged={onChanged} />

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!rendered}
                            onClick={() => void onYouTube()}
                        >
                            {project.youtube.status === "failed"
                                ? "Retry upload"
                                : "Upload to YouTube"}
                        </Button>
                        {project.youtube.video_id && (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={syncing}
                                onClick={() => void onSyncYouTube()}
                            >
                                {syncing ? "Checking…" : "Refresh from YouTube"}
                            </Button>
                        )}
                        {project.youtube.url && (
                            <a
                                href={project.youtube.url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-xs underline"
                            >
                                {project.youtube.url}
                            </a>
                        )}
                    </div>

                    {/* What Google says now, kept apart from our own pipeline
                        status. A scheduled video publishes itself, and people
                        change privacy from the YouTube app. */}
                    {project.youtube.remote.label && (
                        <dl className="grid grid-cols-[8rem_1fr] gap-y-1 text-xs">
                            <dt className="text-muted-foreground">On YouTube</dt>
                            <dd className="font-medium">{project.youtube.remote.label}</dd>

                            {project.youtube.remote.publish_at && (
                                <>
                                    <dt className="text-muted-foreground">Publishes</dt>
                                    <dd>{formatDateTime(project.youtube.remote.publish_at)}</dd>
                                </>
                            )}

                            <dt className="text-muted-foreground">Checked</dt>
                            <dd>{formatDateTime(project.youtube.remote.synced_at)}</dd>
                        </dl>
                    )}

                    {/* The video on YouTube came from an older render, so
                        editing its description would fix nothing. Said here,
                        next to the video, rather than in the corrections
                        block where it would read as a caption to a button. */}
                    {project.youtube.video_is_outdated && (
                        <p className="text-xs text-amber-700 dark:text-amber-400">
                            The current render differs from the video on YouTube.
                        </p>
                    )}

                    {/* Two corrections, deliberately not one button: editing
                        the metadata keeps the URL and the comments, replacing
                        the video cannot. */}
                    {project.youtube.video_id && (
                        <YouTubeCorrections project={project} onChanged={onChanged} />
                    )}

                    <YouTubeHistory project={project} />

                    {/* Choosing a frame is part of publishing, and only
                        possible once there is a render to take one from. */}
                    {rendered && (
                        <div className="border-t pt-4">
                            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                Thumbnail
                            </p>
                            <ThumbnailPicker project={project} onChanged={onChanged} />
                        </div>
                    )}

                    {project.youtube.remote.sync_error && (
                        <p className="text-xs text-amber-700 dark:text-amber-400">
                            Could not reach YouTube: {project.youtube.remote.sync_error} Showing the
                            last known state.
                        </p>
                    )}
                    {!rendered && (
                        <p className="text-xs text-muted-foreground">
                            Render the video first.
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * When this video goes live, and how firmly.
 *
 * A confirmed schedule reads as it always has. A plan — set in the YouTube
 * form but never sent, which is every project still waiting on its render —
 * says so, because "Scheduled for Friday" about a video nobody has uploaded
 * is a sentence that costs somebody a Friday.
 */
function YouTubeScheduleLine({ project }: { project: ContentProject }) {
    const schedule = youtubeSchedule({
        scheduledAt: project.youtube.publish_at,
        plannedPublishAt: project.youtube.planned_publish_at,
    });

    if (schedule === null) {
        return null;
    }

    if (!schedule.planned) {
        return (
            <p className="text-xs text-muted-foreground">
                Scheduled for {formatDateTime(schedule.at)}
            </p>
        );
    }

    return (
        <p
            className={`text-xs ${
                schedule.overdue ? "text-amber-700 dark:text-amber-400" : "text-muted-foreground"
            }`}
        >
            {schedule.overdue
                ? // Not a warning about the video — there isn't one yet. It is
                  // a warning about the upload, which refuses a publish time
                  // that has already passed rather than silently never running.
                  `Was planned for ${formatDateTime(schedule.at)} — choose a new time before uploading`
                : `Planned for ${formatDateTime(schedule.at)}, once uploaded`}
        </p>
    );
}
