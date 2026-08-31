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
