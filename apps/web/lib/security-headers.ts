/**
 * Security headers for the Next.js app.
 *
 * Extracted from next.config.ts so the CSP can be unit tested — a mistake here
 * is invisible until a browser silently blocks a request in production.
 */

/**
 * Origin of the Laravel API, derived from `NEXT_PUBLIC_API_BASE_URL`.
 *
 * The frontend is deployed on Vercel while Laravel is hosted elsewhere, so the
 * API is a *different origin*. Everything the browser sends there is therefore
 * cross-origin and must be named explicitly in the CSP:
 *
 *   - `fetch`/XHR from the axios client   → connect-src
 *   - background artwork in the studio    → img-src
 *   - signed MP4 playback URLs            → media-src
 *
 * With only `'self'`, the browser blocks these before they ever reach Laravel
 * and reports `(blocked:csp)`.
 *
 * Returns null rather than throwing when the variable is unset or unparseable,
 * so `next build` still succeeds — the CSP just omits the origin. It is read
 * at build time, which is when Next.js inlines `NEXT_PUBLIC_*`.
 *
 * `API_PROXY_TARGET` is deliberately not considered: that proxy runs on the
 * server, so those requests never leave the browser's own origin.
 */
export function apiOrigin(
    rawUrl: string | undefined = process.env.NEXT_PUBLIC_API_BASE_URL,
): string | null {
    if (!rawUrl) return null;

    let parsed: URL;

    try {
        parsed = new URL(rawUrl);
    } catch {
        // Malformed, or a bare host with no scheme. Not fatal — build on.
        return null;
    }

    // `new URL` happily parses `data:`, `javascript:` and friends, whose origin
    // serialises to the string "null". Only real network origins belong in an
    // allow-list, so anything that is not http(s) is dropped.
    if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
        return null;
    }

    return parsed.origin;
}

/**
 * The Content-Security-Policy value.
 *
 * `default-src 'self'` stays as the fallback for every directive not listed
 * here; the API origin is only granted to the three directives that actually
 * need it, never wildcarded.
 */
/**
 * Image hosts Google serves connected-account artwork from.
 *
 * YouTube thumbnails and channel avatars come from Google's CDN, not from
 * Laravel, so with only `'self'` the browser blocked every one of them and
 * reported nothing the user could see — the catalog pages simply rendered
 * with holes where the pictures should be.
 *
 * Named explicitly rather than `img-src https:` or a wildcard. These are the
 * only hosts the app has any reason to load an image from, and widening the
 * directive to fix a broken thumbnail would hand every other origin on the
 * internet a place to render pixels in this page.
 *
 * A server-side image proxy was the alternative. It buys nothing here: these
 * URLs are public CDN links to the user's own channel, so proxying adds
 * bandwidth, a cache to manage and an SSRF surface to defend, in exchange for
 * hiding a request Google is already the other end of.
 */
export const GOOGLE_IMAGE_HOSTS = [
    // Video thumbnails.
    "https://i.ytimg.com",
    "https://i9.ytimg.com",
    // Channel avatars and banners.
    "https://yt3.ggpht.com",
    "https://yt3.googleusercontent.com",
    // Google account pictures, and Drive file thumbnails.
    "https://lh3.googleusercontent.com",
] as const;

export function buildContentSecurityPolicy(
    origin: string | null = apiOrigin(),
): string {
    /** Append the API origin to a directive's sources, when we have one. */
    const allowingApi = (...sources: string[]): string =>
        [...sources, ...(origin ? [origin] : [])].join(" ");

    return [
        "default-src 'self'",
        // Next.js App Router injects inline scripts for hydration. Until
        // nonce-based CSP is wired up (see the Next.js docs on nonces), this
        // keeps 'unsafe-inline' and 'unsafe-eval' so the runtime works.
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com",
        // blob: covers the background preview, which is fetched with the
        // bearer token and turned into an object URL. The Google hosts are
        // what make connected-account thumbnails and avatars render at all.
        `img-src ${allowingApi("'self'", "data:", "blob:", ...GOOGLE_IMAGE_HOSTS)}`,
        `connect-src ${allowingApi("'self'")}`,
        // Rendered video is served from Laravel behind a short-lived signed URL.
        `media-src ${allowingApi("'self'", "blob:")}`,
        "frame-ancestors 'none'",
    ].join("; ");
}

export function buildSecurityHeaders(): { key: string; value: string }[] {
    return [
        // Prevent browsers from guessing a different MIME type than declared.
        { key: "X-Content-Type-Options", value: "nosniff" },
        // Block the page from being framed by other origins.
        { key: "X-Frame-Options", value: "SAMEORIGIN" },
        // Reduce referrer data sent to third parties.
        { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
        // Force HTTPS for one year (applied in production; no effect over plain HTTP).
        { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
        // Permissions Policy — disable features the app doesn't use.
        { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
        { key: "Content-Security-Policy", value: buildContentSecurityPolicy() },
    ];
}
