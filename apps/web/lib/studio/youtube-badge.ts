/**
 * What the YouTube badge should say for one project.
 *
 * Pure and dependency-free so it can be tested directly — the precedence here
 * is the kind of thing that looks obvious and is wrong in one specific case
 * that only shows up when something has gone badly.
 *
 * The precedence, most important first:
 *
 *  1. A replacement in flight → "Replacing…". Nothing else is more relevant,
 *     and the pipeline status is mid-transition and briefly meaningless.
 *
 *  2. A failed replacement on a project whose video is still published →
 *     "Published · Replacement failed". This is the case worth writing a
 *     function for: the workflow broke, but the lecture is up and unchanged,
 *     and a bare "Failed" would send someone to check a video that is fine.
 *
 *  3. Whatever YouTube currently says, which beats our own pipeline value —
 *     that was frozen at upload, and a scheduled video publishes itself.
 *
 *  4. Our pipeline status, as the last resort.
 */
export type YouTubeBadgeInput = {
    /** Our pipeline status label, e.g. "Published". */
    label: string;
    /** What Google says now, when we have asked recently. */
    remoteLabel?: string | null;
    isReplacing?: boolean;
    replacementFailed?: boolean;
    /** Whether a video is live on the channel right now. */
    hasVideo?: boolean;
};

export function youtubeBadgeLabel(input: YouTubeBadgeInput): string {
    const current = input.remoteLabel ?? input.label;

    if (input.replacementFailed) {
        // The published video is untouched by a failed replacement, so the
        // headline stays what it was and the failure is the qualifier.
        return input.hasVideo ? `${current} · Replacement failed` : "Replacement failed";
    }

    if (input.isReplacing) {
        return "Replacing…";
    }

    return current;
}

/**
 * The tone the badge should take.
 *
 * A replacement in flight borrows the in-progress tone. A *failed* replacement
 * deliberately keeps the project's own status tone instead: the published
 * video is untouched, so nothing the audience can see is broken, and colouring
 * it red would misreport a working video as a broken one.
 */
export function youtubeBadgeStatus(input: YouTubeBadgeInput, status: string): string {
    return input.isReplacing && !input.replacementFailed ? "uploading" : status;
}

/**
 * The publish time to show under the badge, and what kind of claim it is.
 *
 * Two very different facts share this line, and the whole reason for a
 * function is that they must not be shown as if they were the same one:
 *
 *   confirmed   YouTube has the video and a publishAt. It will publish at
 *               that time whether or not Keje is running. This is a promise.
 *               Which of the two confirmed times to trust is its own
 *               question — see below.
 *
 *   planned     Somebody filled in a schedule and nothing has been uploaded
 *               yet — usually because the render is still queued. It is what
 *               *will be asked for*, and asking can still fail.
 *
 * A queued project used to show a bare "Pending" with the date it was already
 * scheduled for nowhere on the page. Showing that date undecorated would have
 * been the opposite mistake: a row reading "Pending · 12 Sep 19:00" looks like
 * YouTube is holding a slot it has never been told about.
 *
 * `overdue` exists because a plan, unlike a schedule, goes off. The upload
 * refuses a publishAt in the past outright (YouTube accepts it on some paths
 * and then silently never publishes), so a plan whose time has gone is not a
 * date to look forward to — it is a project that cannot be uploaded until
 * somebody changes it.
 *
 * ── Which confirmed time, when there are two ────────────────────────────
 *
 * `scheduledAt` is written by the upload and never again. Move a schedule
 * afterwards — through PATCH /youtube/metadata, or in YouTube Studio with the
 * sync picking it up — and the new time arrives as `remotePublishAt` while
 * `scheduledAt` keeps the one the video was first uploaded with. Showing the
 * frozen one is how a row goes on advertising a date that was changed weeks
 * ago.
 *
 * So what YouTube currently holds wins, which is the same rule
 * youtubeBadgeLabel already applies to the status and for the same reason.
 * The date simply had not been given it.
 */
export type YouTubeScheduleInput = {
    /** Recorded by our upload. Frozen at that moment; never updated after. */
    scheduledAt?: string | null;
    /** Intended, not yet sent. The API withholds it once a video exists. */
    plannedPublishAt?: string | null;
    /** What YouTube holds now. Wins over `scheduledAt` when we have asked. */
    remotePublishAt?: string | null;
    /**
     * The remote status `remotePublishAt` came with, which is what says
     * whether we have a current answer at all.
     *
     * `unavailable` is excluded deliberately: the sync writes that status
     * without touching the publish time, so the time beside it is whatever
     * was last seen and may be long gone. A deleted video must not keep
     * presenting a stale date as though YouTube had just confirmed it.
     */
    remoteStatus?: string | null;
};

export type YouTubeSchedule = {
    at: string;
    /** True when this is an intention rather than something YouTube holds. */
    planned: boolean;
    /** A plan whose time has passed, which now blocks the upload. */
    overdue: boolean;
};

export function youtubeSchedule(
    input: YouTubeScheduleInput,
    now: Date = new Date(),
): YouTubeSchedule | null {
    /*
     * Once we have a current answer from YouTube, it is the whole truth about
     * the date — including when it says there is no date. A published video
     * holds no publish time, and falling back to the one we recorded at
     * upload would have the row claim a schedule for something that has
     * already gone live.
     */
    if (input.remoteStatus && input.remoteStatus !== "unavailable") {
        return input.remotePublishAt
            ? { at: input.remotePublishAt, planned: false, overdue: false }
            : null;
    }

    // Not asked yet, or asked and told the video is gone. Our own record of
    // the upload is the best thing left.
    if (input.scheduledAt) {
        return { at: input.scheduledAt, planned: false, overdue: false };
    }

    if (!input.plannedPublishAt) {
        return null;
    }

    const at = Date.parse(input.plannedPublishAt);

    // An unparseable date is worse than no date: it renders as "Invalid Date"
    // and tells somebody their publication is broken when it is not.
    if (Number.isNaN(at)) {
        return null;
    }

    return { at: input.plannedPublishAt, planned: true, overdue: at <= now.getTime() };
}
