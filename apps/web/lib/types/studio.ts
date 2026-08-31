/**
 * Content Studio API types.
 *
 * These mirror the Laravel API Resources. In particular `TemplateLayout` is the
 * shared layout contract: the backend resolves the Kajian Tematik template into
 * absolute 1280×720 boxes, FFmpeg draws exactly those, and the browser preview
 * reproduces them scaled. The preview must never invent its own coordinates.
 */

export type RenderStatus =
    | "draft"
    | "media_ready"
    | "queued"
    | "rendering"
    | "rendered"
    | "failed";

export type DriveStatus = "pending" | "uploading" | "uploaded" | "failed";

export type YouTubeStatus =
    | "pending"
    | "uploading"
    | "uploaded"
    | "scheduled"
    | "published"
    | "failed";

export type PrivacyStatus = "private" | "unlisted" | "public";

export type ContentTopic = {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    youtube_playlist_id: string | null;
    projects_count?: number;
    next_sequence?: number;
    projects?: ContentProjectSummary[];
    created_at: string | null;
    updated_at: string | null;
};

export type Speaker = {
    id: string;
    name: string;
    display_name: string | null;
    render_name: string;
    projects_count?: number;
    created_at: string | null;
    updated_at: string | null;
};

export type YouTubeMetadata = {
    title?: string | null;
    description?: string | null;
    tags?: string[] | null;
    category_id?: string | null;
    /** Project-level destination override; falls back to the topic's playlist. */
    playlist_id?: string | null;
    default_language?: string | null;
    privacy_status?: PrivacyStatus | null;
    publish_at?: string | null;
    made_for_kids?: boolean | null;
    notify_subscribers?: boolean | null;
};

export type ContentProjectSummary = {
    id: string;
    working_title: string;
    slug: string;
    template_key: string;
    topic?: { id: string; name: string; sequence: number | null } | null;
    topic_sequence: number | null;
    speaker?: { id: string; name: string } | null;
    audio_duration: number | null;
    has_audio: boolean;
    has_background: boolean;
    render: { status: RenderStatus; label: string; progress: number; stale: boolean };
    drive: { status: DriveStatus; label: string };
    youtube: { status: YouTubeStatus; label: string; scheduled_at: string | null };
    created_at: string | null;
    updated_at: string | null;
};

export type ContentProject = {
    id: string;
    working_title: string;
    slug: string;
    template_key: string;

    topic: {
        id: string;
        name: string;
        sequence: number | null;
        youtube_playlist_id: string | null;
    } | null;
    topic_sequence: number | null;
    speaker: { id: string; name: string; render_name: string } | null;

    /** Removed sections, with the arithmetic already done server-side. */
    audio_edits: {
        cuts: { type: string; start: number; end: number }[];
        source_duration: number | null;
        removed_duration: number;
        /** What the render will actually be — also what progress measures. */
        effective_duration: number | null;
    } | null;

    primary_title: string | null;
    subtitle: string | null;
    part_number: number | null;

    source_audio: {
        /** False once the file was pruned from the VPS after a Drive backup. */
        stored: boolean;
        original_name: string | null;
        mime: string | null;
        size: number | null;
        duration: number | null;
        codec: string | null;
        sample_rate: number | null;
        channels: number | null;
        bitrate: number | null;
    } | null;

    background_image: {
        stored: boolean;
        original_name: string | null;
        mime: string | null;
        size: number | null;
        width: number | null;
        height: number | null;
    } | null;

    is_renderable: boolean;

    render: {
        status: RenderStatus;
        label: string;
        progress: number;
        /** The output was produced from inputs that have since changed. */
        stale: boolean;
        error: string | null;
        rendered_at: string | null;
        output_size: number | null;
        output_duration: number | null;
        has_output: boolean;
        /** Set when local media was removed; the render lives in Drive now. */
        media_pruned_at: string | null;
        attempts: number;
    };

    drive: {
        status: DriveStatus;
        label: string;
        file_id: string | null;
        file_name: string | null;
        web_view_link: string | null;
        uploaded_at: string | null;
        error: string | null;
    };

    youtube: {
        status: YouTubeStatus;
        label: string;
        video_id: string | null;
        url: string | null;
        uploaded_at: string | null;
        publish_at: string | null;
        error: string | null;
        metadata: YouTubeMetadata | null;
    };

    /** Outcome of adding the uploaded video to a playlist. */
    youtube_playlist: {
        id: string | null;
        item_id: string | null;
        added_at: string | null;
        error: string | null;
    };

    render_settings: { loudnorm?: boolean } | null;
    created_at: string | null;
    updated_at: string | null;
};

export type RenderStatusPayload = {
    status: RenderStatus;
    label: string;
    progress: number;
    error: string | null;
    /** Queued far longer than a worker should take — see stalled_reason. */
    stalled: boolean;
    stalled_reason: string | null;
    has_output: boolean;
    rendered_at: string | null;
    attempt: {
        id: string | null;
        status: string | null;
        started_at: string | null;
        finished_at: string | null;
    };
};

// ── Shared layout contract ──────────────────────────────────────────────────

/** One positioned run of text, in canvas pixels. */
export type LayoutTextElement = {
    key: string;
    element: string;
    type: "text";
    text: string;
    x: number;
    y: number;
    baseline: number;
    width: number;
    text_width: number;
    align: "left" | "center" | "right";
    font: string;
    font_size: number;
    color: string;
};

export type LayoutImageElement = {
    key: string;
    element: string;
    type: "image";
    asset: string;
    x: number;
    y: number;
    width: number;
    height: number;
};

export type LayoutWaveformElement = {
    key: string;
    element: string;
    type: "waveform";
    x: number;
    y: number;
    width: number;
    height: number;
    color: string;
    mode: string;
};

export type LayoutElement =
    | LayoutTextElement
    | LayoutImageElement
    | LayoutWaveformElement;

export type TemplateLayout = {
    template_key: string;
    template_name: string;
    canvas: { width: number; height: number };
    safe_margin: number;
    background: {
        fit: string;
        overlay: { enabled: boolean; stops: [number, number][] };
    };
    elements: LayoutElement[];
};

/**
 * Google is authorized per product, not once.
 *
 * YouTube and Drive have separate OAuth clients because Google refuses a
 * consent request carrying both products' scopes, so each has its own
 * connection state and neither implies the other.
 */
export type GoogleServiceKey = "youtube" | "drive";

type GoogleConnectionBase = {
    service: GoogleServiceKey;
    label: string;
    configured: boolean;
    connected: boolean;
    scopes: string[];
    connected_at: string | null;
};

export type YouTubeConnection = GoogleConnectionBase & {
    service: "youtube";
    channel_id: string | null;
    channel_title: string | null;
    /** null when no expected channel is configured, or the channel is unknown. */
    channel_matches_expected: boolean | null;
    expected_channel_id: string | null;
};

export type DriveConnection = GoogleConnectionBase & { service: "drive" };

/**
 * What a stored grant permits, derived server-side from the scopes Google
 * returned — not from configuration. A connection made before a scope existed
 * reports that one capability false and keeps the rest working.
 */
export type YouTubeCapabilities = {
    read_channel: boolean;
    upload_video: boolean;
    manage_playlists: boolean;
};

export type DriveCapabilities = {
    about: boolean;
    backup: boolean;
};

export type GoogleIntegrations = {
    youtube: YouTubeConnection & {
        capabilities: YouTubeCapabilities;
        needs_scope_upgrade: boolean;
    };
    drive: DriveConnection & {
        capabilities: DriveCapabilities;
        needs_scope_upgrade: boolean;
    };
};

// ── Connected Google catalog ────────────────────────────────────────────────

export type YouTubeChannelProfile = {
    channel_id: string;
    title: string | null;
    description: string | null;
    custom_url: string | null;
    thumbnail_url: string | null;
    country: string | null;
    default_language: string | null;
    view_count: number | null;
    subscriber_count: number | null;
    hidden_subscriber_count: boolean;
    video_count: number | null;
    uploads_playlist_id: string | null;
    privacy_status: string | null;
    long_uploads_status: string | null;
};

export type YouTubePlaylist = {
    id: string;
    title: string | null;
    description: string | null;
    thumbnail_url: string | null;
    item_count: number;
    privacy_status: string | null;
    published_at: string | null;
};

/** Google's stable id plus its localized name. The id is what gets stored. */
export type YouTubeVideoCategory = { id: string; title: string };

export type YouTubeLanguage = { id: string; title: string };

export type YouTubeRecentUpload = {
    video_id: string;
    title: string | null;
    thumbnail_url: string | null;
    published_at: string | null;
    url: string;
};

export type DriveAbout = {
    account: { name: string | null; email: string | null; photo_url: string | null };
    storage: {
        limit: number | null;
        usage: number | null;
        usage_in_drive: number | null;
        usage_in_trash: number | null;
        unlimited: boolean;
        percent_used: number | null;
    };
    backup_folder: DriveBackupFolder | null;
    backup_folder_available: boolean;
};

export type DriveBackupFolder = {
    id: string;
    name: string | null;
    web_view_link: string | null;
    created_at: string | null;
    modified_at: string | null;
    configured: boolean;
};

export type DriveBackupFile = {
    id: string;
    name: string | null;
    mime_type: string | null;
    size: number | null;
    created_at: string | null;
    modified_at: string | null;
    web_view_link: string | null;
};

/** Google's cursor, passed through rather than faked as an offset. */
export type Paginated<T> = { data: T[]; next_page_token: string | null };
