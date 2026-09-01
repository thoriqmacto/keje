/**
 * Byte sizes, written the way a person reads them.
 *
 * Base 1024 with the short units, because that is what a disk-usage figure
 * means to somebody looking at a server — 4.8 GB here should match roughly
 * what `du -h` says, not the decimal number a marketing page would use.
 */
export function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return "0 B";
    }

    const units = ["B", "KB", "MB", "GB", "TB"];
    const exponent = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    const value = bytes / 1024 ** exponent;

    // Whole bytes never need a decimal; everything else reads better with one,
    // and two would imply a precision the filesystem does not offer.
    return exponent === 0
        ? `${Math.round(value)} B`
        : `${value.toFixed(value >= 100 ? 0 : 1)} ${units[exponent]}`;
}
