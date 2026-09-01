import { AxiosError } from "axios";
import { api } from "@/lib/api";
import type {
    ContentProject,
    ContentProjectSummary,
    ContentTopic,
    DriveAbout,
    DriveBackupFile,
    OldVideoDisposition,
    RenderStatusPayload,
    Speaker,
    TemplateLayout,
    YouTubeChannelProfile,
    YouTubeLanguage,
    YouTubeMetadata,
    YouTubePlaylist,
    YouTubePublication,
    YouTubeRecentUpload,
    YouTubeReplacement,
    FinishPlan,
    FinishResult,
    PaginatedResponse,
    PrunePreview,
    StorageInventory,
    StudioStats,
    YouTubeVideoCategory,
} from "@/lib/types/studio";
import {
    DEFAULT_QUERY,
    serializeQuery,
    toRequestParams,
    type StudioProjectQuery,
} from "@/lib/studio/table-query";

/**
 * Content Studio data access.
 *
 * Every studio call goes through `@/lib/api` (the one configured axios client)
 * — pages never talk to axios directly. SWR keys are the exported constants so
 * mutations can revalidate without repeating strings.
 */

export const studioKeys = {
    projects: "studio:projects",
    project: (id: string) => `studio:project:${id}`,
    preview: (id: string) => `studio:preview:${id}`,
    renderStatus: (id: string) => `studio:render-status:${id}`,
    topics: "studio:topics",
    topic: (id: string) => `studio:topic:${id}`,
    speakers: "studio:speakers",
    google: "studio:google",
    stats: "studio:stats",
    storage: "studio:storage",
    /*
     * Keyed by the serialised query, so each distinct view is cached
     * separately. That is what lets SWR hold the previous page on screen
     * while the next one loads instead of flashing an empty table.
     */
    projectList: (query: StudioProjectQuery) =>
        `studio:projects?${serializeQuery(query).toString()}`,
    replacement: (id: string) => `studio:replacement:${id}`,
    publications: (id: string) => `studio:publications:${id}`,
} as const;

// ── Topics ──────────────────────────────────────────────────────────────────

export async function listTopics(): Promise<ContentTopic[]> {
    const { data } = await api.get<{ data: ContentTopic[] }>("/topics");
    return data.data;
}

export async function getTopic(id: string): Promise<ContentTopic> {
    const { data } = await api.get<{ data: ContentTopic }>(`/topics/${id}`);
    return data.data;
}

export async function createTopic(input: {
    name: string;
    description?: string | null;
    youtube_playlist_id?: string | null;
}): Promise<ContentTopic> {
    const { data } = await api.post<{ data: ContentTopic }>("/topics", input);
    return data.data;
}

export async function updateTopic(
    id: string,
    input: Partial<Pick<ContentTopic, "name" | "description" | "youtube_playlist_id">>,
): Promise<ContentTopic> {
    const { data } = await api.patch<{ data: ContentTopic }>(`/topics/${id}`, input);
    return data.data;
}

export async function deleteTopic(id: string): Promise<void> {
    await api.delete(`/topics/${id}`);
}

// ── Speakers ────────────────────────────────────────────────────────────────

export async function listSpeakers(): Promise<Speaker[]> {
    const { data } = await api.get<{ data: Speaker[] }>("/speakers");
    return data.data;
}

export async function createSpeaker(input: {
    name: string;
    display_name?: string | null;
}): Promise<Speaker> {
    const { data } = await api.post<{ data: Speaker }>("/speakers", input);
    return data.data;
}

// ── Projects ────────────────────────────────────────────────────────────────

/**
 * One page of the Studio list.
 *
 * Takes the query rather than assembling one, so filters, sorting and paging
 * all travel through a single typed object. Ad-hoc URL building at call sites
 * is how two screens end up disagreeing about what an omitted parameter means.
 */
export async function listProjects(
    query: StudioProjectQuery = DEFAULT_QUERY,
): Promise<PaginatedResponse<ContentProjectSummary>> {
    const { data } = await api.get<PaginatedResponse<ContentProjectSummary>>("/content-projects", {
        params: toRequestParams(query),
    });
    return data;
}

/** Account-wide counts, which a single page of the list cannot supply. */
export async function getStudioStats(): Promise<StudioStats> {
    const { data } = await api.get<{ data: StudioStats }>("/content-projects/stats");
    return data.data;
}
export async function getProject(id: string): Promise<ContentProject> {
    const { data } = await api.get<{ data: ContentProject }>(`/content-projects/${id}`);
    return data.data;
}

export type ProjectInput = {
    working_title?: string;
    topic_id?: string | null;
    topic_sequence?: number | null;
    speaker_id?: string | null;
    primary_title?: string | null;
    subtitle?: string | null;
    part_number?: number | null;
    render_settings?: { loudnorm?: boolean } | null;
    youtube_metadata?: ContentProject["youtube"]["metadata"];
};

export async function createProject(input: ProjectInput): Promise<ContentProject> {
    const { data } = await api.post<{ data: ContentProject }>("/content-projects", input);
    return data.data;
}

/**
 * Save the removed sections.
 *
 * Non-destructive: the uploaded recording is untouched, so this is cheap to
 * change and costs only a re-render.
 */
export async function saveAudioEdits(
    id: string,
    cuts: { type: string; start: number; end: number }[],
): Promise<ContentProject> {
    const { data } = await api.put<{ data: ContentProject }>(
        `/content-projects/${id}/audio-edits`,
        { audio_edits: cuts },
    );
    return data.data;
}

export async function updateProject(id: string, input: ProjectInput): Promise<ContentProject> {
    const { data } = await api.patch<{ data: ContentProject }>(`/content-projects/${id}`, input);
    return data.data;
}

export async function deleteProject(id: string): Promise<void> {
    await api.delete(`/content-projects/${id}`);
}

// ── Media ───────────────────────────────────────────────────────────────────

/** Uploads report progress so the studio can show a bar for large recordings. */
export async function uploadAudio(
    id: string,
    file: File,
    onProgress?: (percent: number) => void,
): Promise<ContentProject> {
    return uploadFile(`/content-projects/${id}/audio`, "audio", file, onProgress);
}

export async function uploadBackground(
    id: string,
    file: File,
    onProgress?: (percent: number) => void,
): Promise<ContentProject> {
    return uploadFile(`/content-projects/${id}/background`, "background", file, onProgress);
}

async function uploadFile(
    url: string,
    field: string,
    file: File,
    onProgress?: (percent: number) => void,
): Promise<ContentProject> {
    const form = new FormData();
    form.append(field, file);

    const { data } = await api.post<{ data: ContentProject }>(url, form, {
        // Let the browser set the multipart boundary.
        headers: { "Content-Type": undefined },
        onUploadProgress: (event) => {
            if (onProgress && event.total) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        },
    });

    return data.data;
}

// ── Preview / render ────────────────────────────────────────────────────────

export async function getPreview(id: string): Promise<TemplateLayout> {
    const { data } = await api.get<{ data: TemplateLayout }>(`/content-projects/${id}/preview`);
    return data.data;
}

/**
 * Queue a render, and say what should happen once it succeeds.
 *
 * The choices travel with the request rather than being remembered in the
 * browser: the job may sit on the queue for a while, and the server
 * snapshots them onto the attempt.
 */
export async function startRender(
    id: string,
    postActions: { drive_backup: boolean; youtube_upload: boolean } = {
        drive_backup: false,
        youtube_upload: false,
    },
): Promise<void> {
    await api.post(`/content-projects/${id}/render`, { post_actions: postActions });
}

export async function getRenderStatus(id: string): Promise<RenderStatusPayload> {
    const { data } = await api.get<{ data: RenderStatusPayload }>(
        `/content-projects/${id}/render-status`,
    );
    return data.data;
}

// ── Google ──────────────────────────────────────────────────────────────────

export async function backupToDrive(id: string): Promise<void> {
    await api.post(`/content-projects/${id}/drive`);
}

export async function uploadToYouTube(
    id: string,
    metadata: ContentProject["youtube"]["metadata"],
): Promise<void> {
    await api.post(`/content-projects/${id}/youtube`, metadata ?? {});
}

// ── Errors ──────────────────────────────────────────────────────────────────

/**
 * First human-readable message from a Laravel error response — the first
 * validation error if there is one, else the top-level message.
 */
export function apiErrorMessage(error: unknown, fallback: string): string {
    const axiosError = error as AxiosError<{
        message?: string;
        errors?: Record<string, string[]>;
    }>;

    const first = axiosError.response?.data?.errors
        ? Object.values(axiosError.response.data.errors).flat()[0]
        : undefined;

    return first ?? axiosError.response?.data?.message ?? fallback;
}

// ── Connected Google catalog ────────────────────────────────────────────────
//
// Every read goes through Laravel, which holds the OAuth tokens and caches the
// responses. The browser never talks to Google directly and never sees a token.

export const googleKeys = {
    channel: "google:youtube:channel",
    playlists: "google:youtube:playlists",
    categories: "google:youtube:categories",
    languages: "google:youtube:languages",
    recentUploads: "google:youtube:recent-uploads",
    driveAbout: "google:drive:about",
    driveBackups: "google:drive:backups",
} as const;

export async function getYouTubeChannel(): Promise<YouTubeChannelProfile | null> {
    const { data } = await api.get<{ data: YouTubeChannelProfile | null }>(
        "/integrations/youtube/channel",
    );
    return data.data;
}

/** Destination playlists only — the channel's uploads playlist is excluded. */
export async function listYouTubePlaylists(
    pageToken?: string,
): Promise<{ data: YouTubePlaylist[]; nextPageToken: string | null }> {
    const { data } = await api.get<{
        data: YouTubePlaylist[];
        meta: { next_page_token: string | null };
    }>("/integrations/youtube/playlists", { params: pageToken ? { page_token: pageToken } : {} });

    return { data: data.data, nextPageToken: data.meta?.next_page_token ?? null };
}

export async function listYouTubeCategories(): Promise<YouTubeVideoCategory[]> {
    const { data } = await api.get<{ data: YouTubeVideoCategory[] }>(
        "/integrations/youtube/categories",
    );
    return data.data;
}

export async function listYouTubeLanguages(): Promise<YouTubeLanguage[]> {
    const { data } = await api.get<{ data: YouTubeLanguage[] }>("/integrations/youtube/languages");
    return data.data;
}

export async function listYouTubeRecentUploads(): Promise<YouTubeRecentUpload[]> {
    const { data } = await api.get<{ data: YouTubeRecentUpload[] }>(
        "/integrations/youtube/recent-uploads",
    );
    return data.data;
}

/** Drops the server-side cache and re-reads. Never re-runs OAuth consent. */
export async function refreshYouTubeCatalog(): Promise<void> {
    await api.post("/integrations/youtube/refresh");
}

export async function getDriveAbout(): Promise<DriveAbout> {
    const { data } = await api.get<{ data: DriveAbout }>("/integrations/drive/about");
    return data.data;
}

export async function listDriveBackups(
    pageToken?: string,
): Promise<{ data: DriveBackupFile[]; nextPageToken: string | null }> {
    const { data } = await api.get<{
        data: DriveBackupFile[];
        meta: { next_page_token: string | null };
    }>("/integrations/drive/backups", { params: pageToken ? { page_token: pageToken } : {} });

    return { data: data.data, nextPageToken: data.meta?.next_page_token ?? null };
}

export async function refreshDriveCatalog(): Promise<void> {
    await api.post("/integrations/drive/refresh");
}

/**
 * Add an already-uploaded video to its playlist.
 *
 * Deliberately separate from the upload endpoint: this can never publish a
 * second copy of the video.
 */
/**
 * Ask YouTube what it currently says about this video.
 *
 * Read-only. It never changes privacy and never re-uploads — if someone made
 * a public video private from the YouTube app, that is the truth.
 */
/**
 * Extract candidate frames from the rendered video.
 *
 * Cheap: FFmpeg seeks by keyframe and stops after one frame, so this is a few
 * quick seeks rather than anything resembling a transcode.
 */
/**
 * The local topic for a YouTube playlist, created on first use.
 *
 * Keeps a playlist and a topic as one concept: the renderer draws the topic
 * name and historical projects point at the topic row, so the shadow has to
 * exist — but nobody should have to maintain it by hand.
 */
export async function resolveTopicFromPlaylist(
    playlistId: string,
    title: string | null,
): Promise<ContentTopic> {
    const { data } = await api.post<{ data: ContentTopic }>("/topics/from-playlist", {
        youtube_playlist_id: playlistId,
        title,
    });
    return data.data;
}

export async function generateThumbnailFrames(
    projectId: string,
    timestamp?: number,
): Promise<{ timestamp: number; url: string }[]> {
    const { data } = await api.post<{ data: { timestamp: number; url: string }[] }>(
        `/content-projects/${projectId}/thumbnail/frames`,
        timestamp === undefined ? {} : { timestamp },
    );
    return data.data;
}

export async function selectThumbnail(projectId: string, timestamp: number): Promise<ContentProject> {
    const { data } = await api.post<{ data: ContentProject }>(
        `/content-projects/${projectId}/thumbnail/select`,
        { timestamp },
    );
    return data.data;
}

/**
 * Send the chosen thumbnail to YouTube.
 *
 * thumbnails.set only — this can never reach videos.insert, so retrying a
 * refused thumbnail cannot publish a second copy of the video.
 */
export async function pushThumbnail(projectId: string): Promise<ContentProject> {
    const { data } = await api.post<{ data: ContentProject }>(
        `/content-projects/${projectId}/thumbnail/push`,
    );
    return data.data;
}

/** Same-origin proxy path, so the <img> carries the session. */
export function thumbnailFrameUrl(projectId: string, timestamp: number): string {
    return `/api/v1/content-projects/${projectId}/thumbnail?timestamp=${timestamp}`;
}

export async function syncYouTubeStatus(projectId: string): Promise<ContentProject> {
    const { data } = await api.post<{ data: ContentProject }>(
        `/content-projects/${projectId}/youtube/sync`,
    );
    return data.data;
}

export async function assignYouTubePlaylist(projectId: string): Promise<ContentProject> {
    const { data } = await api.post<{ data: ContentProject }>(
        `/content-projects/${projectId}/youtube/playlist`,
    );
    return data.data;
}

// ── Correcting a published video ────────────────────────────────────────────
//
// Two operations, kept apart in the client exactly as they are on the server.
// A single `reupload()` helper would be one careless call site away from
// deleting a published lecture to fix a typo.

/**
 * Edit the metadata of the video already on YouTube.
 *
 * Same video id, same URL, same comments. Cannot upload.
 */
export async function updateYouTubeMetadata(
    projectId: string,
    metadata: Partial<YouTubeMetadata>,
): Promise<ContentProject> {
    const { data } = await api.patch<{ data: ContentProject }>(
        `/content-projects/${projectId}/youtube/metadata`,
        metadata,
    );
    return data.data;
}

/**
 * Replace the video file, which YouTube can only do by publishing a new video.
 *
 * The new video gets a new URL, and the old one's views, likes and comments do
 * not move with it. Destructive when the disposition is `delete`.
 */
export async function startYouTubeReplacement(
    projectId: string,
    oldDisposition: OldVideoDisposition,
): Promise<YouTubeReplacement> {
    const { data } = await api.post<{ data: YouTubeReplacement }>(
        `/content-projects/${projectId}/youtube/replacement`,
        { old_disposition: oldDisposition },
    );
    return data.data;
}

export async function getYouTubeReplacement(
    projectId: string,
): Promise<YouTubeReplacement | null> {
    const { data } = await api.get<{ data: YouTubeReplacement | null }>(
        `/content-projects/${projectId}/youtube/replacement`,
    );
    return data.data;
}

/** Resume a stalled replacement. Never re-uploads — the server resumes. */
export async function retryYouTubeReplacement(
    projectId: string,
): Promise<YouTubeReplacement> {
    const { data } = await api.post<{ data: YouTubeReplacement }>(
        `/content-projects/${projectId}/youtube/replacement/retry`,
    );
    return data.data;
}

export async function cancelYouTubeReplacement(
    projectId: string,
): Promise<YouTubeReplacement> {
    const { data } = await api.post<{ data: YouTubeReplacement }>(
        `/content-projects/${projectId}/youtube/replacement/cancel`,
    );
    return data.data;
}

export async function listYouTubePublications(
    projectId: string,
): Promise<YouTubePublication[]> {
    const { data } = await api.get<{ data: YouTubePublication[] }>(
        `/content-projects/${projectId}/youtube/publications`,
    );
    return data.data;
}

/**
 * Declare the project finished, releasing the files kept for corrections.
 *
 * The counterpart to the correction window: after this the project cannot be
 * re-rendered, so it is deliberately a decision rather than a timer.
 */
export async function finalizeProject(projectId: string): Promise<ContentProject> {
    const { data } = await api.post<{ data: ContentProject }>(
        `/content-projects/${projectId}/finalize`,
    );
    return data.data;
}

// ── Local storage housekeeping ──────────────────────────────────────────────
//
// No function here takes a path. The browser sends an intent and the server
// decides which files that means — an endpoint accepting a path would be a
// remote file manager wearing a storage page.

export async function getStorageInventory(): Promise<StorageInventory> {
    const { data } = await api.get<{ data: StorageInventory }>("/storage");
    return data.data;
}

export async function getPrunePreview(): Promise<PrunePreview> {
    const { data } = await api.get<{ data: PrunePreview }>("/storage/prune-preview");
    return data.data;
}

export async function pruneStorage(): Promise<{ pruned: number; skipped: number; bytes_freed: number }> {
    const { data } = await api.post<{ data: { pruned: number; skipped: number; bytes_freed: number } }>(
        "/storage/prune",
    );
    return data.data;
}

// ── Bulk re-render ──────────────────────────────────────────────────────────

/**
 * What a bulk re-render would do, over the whole filtered view.
 *
 * Takes the same query the list does, so "this view" means every matching
 * project rather than the page on screen.
 */
export async function getFinishPlan(query: StudioProjectQuery): Promise<FinishPlan> {
    const { data } = await api.get<{ data: FinishPlan }>("/content-projects/finish-plan", {
        params: toRequestParams(query),
    });
    return data.data;
}

/** Queue the renders. Never touches YouTube or Drive. */
export async function finishOutdated(query: StudioProjectQuery): Promise<FinishResult> {
    const { data } = await api.post<{ data: FinishResult }>(
        "/content-projects/finish-all",
        {},
        { params: toRequestParams(query) },
    );
    return data.data;
}
