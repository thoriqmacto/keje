import { NextRequest, NextResponse } from "next/server";

const PROTECTED_PREFIXES = ["/dashboard", "/notes", "/settings"];

export function middleware(req: NextRequest) {
    const { pathname } = req.nextUrl;
    const isProtected = PROTECTED_PREFIXES.some(
        (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
    );

    if (!isProtected) return NextResponse.next();

    const hasHint = req.cookies.get("auth_hint")?.value === "1";
    if (hasHint) return NextResponse.next();

    const url = req.nextUrl.clone();
    url.pathname = "/login";
    url.searchParams.set("next", pathname);
    return NextResponse.redirect(url);
}

export const config = {
    matcher: ["/dashboard/:path*", "/notes/:path*", "/settings/:path*"],
};
