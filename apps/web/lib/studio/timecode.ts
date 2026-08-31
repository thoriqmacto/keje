/**
 * Timestamps for the audio editor, in both directions.
 *
 * People read "18:42" and type either that or "1122"; the API only ever deals
 * in decimal seconds. Keeping the conversion here — and free of imports —
 * means the parsing rules are asserted directly rather than through a form.
 */

/** Seconds as mm:ss.ss, or h:mm:ss.ss once it runs past an hour. */
export function formatTimecode(seconds: number | null | undefined): string {
    if (seconds == null || !Number.isFinite(seconds) || seconds < 0) return "00:00.00";

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = seconds % 60;

    const mm = String(minutes).padStart(2, "0");
    // Two decimals: a lecture cut is chosen by ear, and hundredths are as
    // fine as that gets. Whole seconds would round a cut into speech.
    const ss = rest.toFixed(2).padStart(5, "0");

    return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`;
}

/**
 * "1:18:42.5", "18:42", "1122.5" → seconds. null when it is not a time at all,
 * so the caller can say so rather than sending NaN to the API.
 */
export function parseTimecode(value: string): number | null {
    const trimmed = value.trim();
    if (trimmed === "") return null;

    const parts = trimmed.split(":");
    if (parts.length > 3) return null;

    let total = 0;

    for (const part of parts) {
        if (!/^\d*\.?\d*$/.test(part) || part === "" || part === ".") return null;

        const n = Number(part);
        if (!Number.isFinite(n)) return null;

        // Only the last segment may carry a fraction or exceed 59; "1:75" is
        // a typo, not 2:15.
        total = total * 60 + n;
    }

    if (parts.length > 1 && parts.slice(1).some((p) => Number(p) >= 60)) return null;

    return Number.isFinite(total) && total >= 0 ? Math.round(total * 1000) / 1000 : null;
}
