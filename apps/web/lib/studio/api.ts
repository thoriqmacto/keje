import { AxiosError } from "axios";
import { api } from "@/lib/api";
import type {
    ContentProject,
    ContentProjectSummary,
    ContentTopic,
    RenderStatusPayload,
    Speaker,
    TemplateLayout,
} from "@/lib/types/studio";

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

export async function listProjects(): Promise<ContentProjectSummary[]> {
    const { data } = await api.get<{ data: ContentProjectSummary[] }>("/content-projects");
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

export async function startRender(id: string): Promise<void> {
    await api.post(`/content-projects/${id}/render`);
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
