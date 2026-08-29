import type { NextConfig } from "next";
import { buildSecurityHeaders } from "./lib/security-headers";

// The header set — including the CSP, which grants the Laravel API origin the
// access it needs — lives in lib/security-headers.ts so it can be unit tested.
const securityHeaders = buildSecurityHeaders();

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
