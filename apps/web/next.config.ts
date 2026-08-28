import type { NextConfig } from "next";

const securityHeaders = [
    // Prevent browsers from guessing a different MIME type than declared.
    { key: "X-Content-Type-Options", value: "nosniff" },
    // Block the page from being framed by other origins.
    { key: "X-Frame-Options", value: "SAMEORIGIN" },
    // Reduce referrer data sent to third parties.
    { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
    // Force HTTPS for one year (applied in production; has no effect over plain HTTP).
    { key: "Strict-Transport-Security", value: "max-age=31536000; includeSubDomains" },
    // Permissions Policy — disable features the app doesn't use.
    { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
    // Content-Security-Policy.
    // NOTE: Next.js App Router injects inline scripts for hydration. Until nonce-based
    // CSP is wired up (see Next.js docs on nonces), script-src keeps 'unsafe-inline'
    // and 'unsafe-eval' to avoid breaking the runtime. Tighten per your threat model.
    {
        key: "Content-Security-Policy",
        value: [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
        ].join("; "),
    },
];

const nextConfig: NextConfig = {
    reactStrictMode: true,
    async headers() {
        return [
            {
                source: "/(.*)",
                headers: securityHeaders,
            },
        ];
    },
};

export default nextConfig;
