/**
 * The tab title, in the same shape as the root layout's metadata template.
 *
 * Kept free of imports so it can be asserted without Next or the `@/` alias:
 * the pages that set their title in the browser must produce exactly what the
 * server-rendered ones do, and that is worth a test rather than a convention.
 */
export function formatDocumentTitle(title: string, appName: string): string {
    const trimmed = title.trim();

    return trimmed === "" || trimmed === appName ? appName : `${appName} | ${trimmed}`;
}
