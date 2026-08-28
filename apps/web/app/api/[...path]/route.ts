import type { NextRequest } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

// Only these top-level API path segments are allowed through the proxy.
// This prevents the public internet from reaching internal-only backend routes.
const ALLOWED_PREFIXES = new Set(["v1", "ping"]);

function resolveProxyTarget(): string {
    if (process.env.API_PROXY_TARGET) return process.env.API_PROXY_TARGET;
    if (process.env.NEXT_PUBLIC_API_BASE_URL) {
        try {
            const u = new URL(process.env.NEXT_PUBLIC_API_BASE_URL);
            return `${u.protocol}//${u.host}`;
        } catch {
            /* fall through */
        }
    }
    // Neither env var is set — warn loudly so misconfigured deployments surface immediately.
    console.error(
        "[proxy] API_PROXY_TARGET and NEXT_PUBLIC_API_BASE_URL are both unset or invalid. " +
        "Falling back to http://localhost:8000 — set the env var in your deployment.",
    );
    return "http://localhost:8000";
}

function buildTargetUrl(req: NextRequest, path: string[]) {
    const search = req.nextUrl.search;
    const joined = path.join("/");
    return `${resolveProxyTarget()}/api/${joined}${search}`;
}

function forwardHeaders(req: NextRequest) {
    const headers = new Headers(req.headers);
    // Strip hop-by-hop and host-identifying headers before forwarding.
    [
        "host", "connection", "content-length", "accept-encoding",
        "x-forwarded-proto", "x-forwarded-host",
        // Do not forward cookies in bearer mode — they are not used for auth and
        // leaking them to the backend could expose session state unexpectedly.
        "cookie",
    ].forEach((h) => headers.delete(h));
    return headers;
}

async function proxy(method: string, req: NextRequest, params: { path: string[] }) {
    // Allowlist check — reject paths outside the known API surface.
    const topLevel = params.path[0];
    if (!topLevel || !ALLOWED_PREFIXES.has(topLevel)) {
        return new Response(JSON.stringify({ message: "Not found." }), {
            status: 404,
            headers: { "Content-Type": "application/json" },
        });
    }

    const target = buildTargetUrl(req, params.path);
    const headers = forwardHeaders(req);
    const hasBody = method !== "GET" && method !== "HEAD";
    const body = hasBody ? await req.blob() : undefined;

    const resp = await fetch(target, {
        method,
        headers,
        body,
        redirect: "manual",
        cache: "no-store",
    });

    const outHeaders = new Headers(resp.headers);
    outHeaders.delete("content-encoding");
    outHeaders.delete("transfer-encoding");

    return new Response(resp.body, {
        status: resp.status,
        statusText: resp.statusText,
        headers: outHeaders,
    });
}

type RouteContext = { params: Promise<{ path: string[] }> };

async function handle(method: string, req: NextRequest, ctx: RouteContext) {
    const params = await ctx.params;
    return proxy(method, req, params);
}

export const GET     = (req: NextRequest, ctx: RouteContext) => handle("GET",     req, ctx);
export const HEAD    = (req: NextRequest, ctx: RouteContext) => handle("HEAD",    req, ctx);
export const POST    = (req: NextRequest, ctx: RouteContext) => handle("POST",    req, ctx);
export const PUT     = (req: NextRequest, ctx: RouteContext) => handle("PUT",     req, ctx);
export const PATCH   = (req: NextRequest, ctx: RouteContext) => handle("PATCH",   req, ctx);
export const DELETE  = (req: NextRequest, ctx: RouteContext) => handle("DELETE",  req, ctx);
export const OPTIONS = (req: NextRequest, ctx: RouteContext) => handle("OPTIONS", req, ctx);
