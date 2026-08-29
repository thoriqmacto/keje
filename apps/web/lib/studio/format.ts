/** Presentation helpers shared across the Content Studio. */

export function formatDuration(seconds: number | null | undefined): string {
    if (seconds == null || Number.isNaN(seconds)) return "—";

    const total = Math.round(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = total % 60;

    const pad = (value: number) => value.toString().padStart(2, "0");

    return hours > 0 ? `${hours}:${pad(minutes)}:${pad(secs)}` : `${minutes}:${pad(secs)}`;
}

export function formatBytes(bytes: number | null | undefined): string {
    if (bytes == null) return "—";
    if (bytes < 1024) return `${bytes} B`;

    const units = ["KB", "MB", "GB"];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return "—";

    return new Date(iso).toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
    });
}

/**
 * Convert a `datetime-local` value (which the browser gives in the user's own
 * timezone, without an offset) into an absolute ISO-8601 instant for the API.
 * Scheduling is meaningless without this: the backend stores UTC and YouTube
 * needs a real instant.
 */
export function localInputToIso(value: string): string | null {
    if (!value) return null;

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/** Inverse of {@link localInputToIso}, for populating the input. */
export function isoToLocalInput(iso: string | null | undefined): string {
    if (!iso) return "";

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return "";

    const pad = (value: number) => value.toString().padStart(2, "0");

    return (
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
        `T${pad(date.getHours())}:${pad(date.getMinutes())}`
    );
}
