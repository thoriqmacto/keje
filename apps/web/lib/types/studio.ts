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
    youtube: {
        status: YouTubeStatus;
        label: string;
        scheduled_at: string | null;
        /** What YouTube says now — a scheduled video publishes itself. */
        remote_status: string | null;
        remote_label: string | null;
        remote_synced_at: string | null;
        /** A correction in flight, so the list can say so plainly. */
        is_replacing: boolean;
        /** Broke mid-workflow. The published video may still be fine. */
        replacement_failed: boolean;
    };
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
        /** The fingerprint of the render this video was made from. */
        render_input_hash: string | null;
        /**
         * The video on YouTube came from an older render, so the frames are
         * wrong rather than the description. The one signal that separates
         * "edit the metadata" from "replace the video".
         */
        video_is_outdated: boolean;
        /** Google's current view, kept apart from our pipeline status. */
        remote: {
            status: string | null;
            label: string | null;
            privacy_status: string | null;
            publish_at: string | null;
            synced_at: string | null;
            sync_error: string | null;
        };
    };

    /** Outcome of adding the uploaded video to a playlist. */
    youtube_playlist: {
        id: string | null;
        item_id: string | null;
        added_at: string | null;
        error: string | null;
    };

    /** Chosen frame, and how YouTube received it. Never folded into `youtube`. */
    thumbnail: {
        timestamp: number | null;
        selected: boolean;
        generated_at: string | null;
        youtube_status: string | null;
        youtube_error: string | null;
        youtube_synced_at: string | null;
    };

    /** In-flight correction, and whether a new one may be started. */
    replacement: {
        active: YouTubeReplacement | null;
        can_replace: boolean;
        blocked_reason: string | null;
        blocked_message: string | null;
        needs_render: boolean;
        needs_reconnect: boolean;
        needs_media: boolean;
    };

    /** Working files are kept for a while so a mistake can still be fixed. */
    retention: {
        finalized_at: string | null;
        within_correction_window: boolean;
    };

    render_settings: { loudnorm?: boolean } | null;
    created_at: string | null;
    updated_at: string | null;
};

/** What to do with the video being replaced. */
export type OldVideoDisposition = "delete" | "keep_private";

export type ReplacementStatus =
    | "pending"
    | "uploading"
    | "uploaded"
    | "disposing_old"
    | "old_disposed"
    | "finalizing"
    | "cancelling"
    | "completed"
    | "cancelled"
    | "failed";

/**
 * A correction in flight.
 *
 * `old_still_current` is the field that matters most while something is going
 * wrong: it answers "is my published video still there", which is the only
 * question anyone actually has at that moment.
 */
export type YouTubeReplacement = {
    id: string;
    status: ReplacementStatus;
    label: string;
    old_still_current: boolean;
    old_video_id: string;
    new_video_id: string | null;
    old_disposition: OldVideoDisposition;
    stage: "upload" | "dispose_old" | "finalize" | null;
    upload_progress: number;
    is_active: boolean;
    is_failed: boolean;
    is_cancellable: boolean;
    error: string | null;
    blocking_summary: string | null;
    started_at: string | null;
    uploaded_at: string | null;
    old_disposed_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
};

/**
 * One video this project has had on YouTube.
 *
 * Replacing changes the public URL, so superseded entries are kept: they are
 * the only record of a link that may still be circulating.
 */
export type YouTubePublication = {
    id: string;
    video_id: string;
    url: string | null;
    title: string | null;
    privacy_status: string | null;
    is_current: boolean;
    disposition: string | null;
    exists_on_youtube: boolean;
    render_input_hash: string | null;
    uploaded_at: string | null;
    became_current_at: string | null;
    replaced_at: string | null;
    remote_deleted_at: string | null;
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
    /**
     * videos.update and videos.delete — editing or removing a video that
     * already exists, which the upload scope does not cover. Without it a
     * connection can publish and cannot correct.
     */
    manage_videos: boolean;
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

// ── Paginated collections ───────────────────────────────────────────────────

/**
 * What a server-paginated list returns.
 *
 * `meta` is normalised on the API side rather than passing Laravel's own
 * paginator payload through: the browser should not have to know about
 * `prev_page_url` or parse link objects to draw a footer.
 */
export type PaginationMeta = {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    /** 1-based index of the first row on this page; null when empty. */
    from: number | null;
    to: number | null;
};

/**
 * Named apart from `Paginated`, which is Google's token-based paging.
 *
 * Two different models: Google hands back an opaque cursor to continue from,
 * while Keje's own lists are offset-based with a known total — you cannot ask
 * a cursor API for page four. Collapsing them into one name would invite a
 * component to expect a `total` that a catalog response never has.
 */
export type PaginatedResponse<T> = {
    data: T[];
    meta: PaginationMeta;
};

/**
 * Account-wide counts for the dashboard.
 *
 * Its own endpoint because the list is paginated: five numbers about every
 * project cannot be read off one page of them.
 */
export type StudioStats = {
    total: number;
    drafts: number;
    rendering: number;
    ready_to_upload: number;
    scheduled: number;
    published: number;
    /** Videos whose frames no longer match the project they came from. */
    outdated: number;
};
