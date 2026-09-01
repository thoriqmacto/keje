"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/components/auth-provider";
import { Button } from "@/components/ui/button";
import { APP_NAME } from "@/lib/env";
import { layoutMetricsStyle } from "@/lib/layout-metrics";

export default function AppLayout({ children }: { children: React.ReactNode }) {
    const { status, user, logout } = useAuth();
    const router = useRouter();

    useEffect(() => {
        if (status === "anonymous") {
            router.replace("/login");
        }
    }, [status, router]);

    if (status !== "authenticated") {
        return (
            <div className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">
                Loading…
            </div>
        );
    }

    return (
        <div className="flex min-h-screen flex-col" style={layoutMetricsStyle}>
            {/*
                Sticky rather than scrolling away. On a long project page the
                navigation was reachable only by scrolling back to the top,
                which is a long way up from the bottom of a render log.

                z-30 puts it above the Studio table's own sticky header (z-10)
                and its pinned columns (z-20), so the two sticky layers stack
                in the order they are read rather than fighting.

                The background is opaque on purpose: a translucent bar lets
                table rows show through as they pass underneath, which reads
                as a rendering fault rather than a design.
            */}
            <header className="sticky top-0 z-30 border-b bg-background">
                {/*
                    min-w-0 + overflow-x-auto let the nav scroll on a narrow
                    screen instead of pushing past the viewport, which was
                    hiding "Settings" behind the Sign out button on mobile.
                */}
                <div className="mx-auto flex h-14 w-full max-w-5xl items-center justify-between gap-3 px-4">
                    <div className="flex min-w-0 items-center gap-6">
                        <Link href="/dashboard" className="shrink-0 font-semibold tracking-tight">
                            {APP_NAME}
                        </Link>
                        <nav
                            aria-label="Main"
                            className="flex items-center gap-4 overflow-x-auto whitespace-nowrap text-sm text-muted-foreground"
                        >
                            <Link href="/dashboard" className="hover:text-foreground">
                                Dashboard
                            </Link>
                            <Link href="/studio" className="hover:text-foreground">
                                Studio
                            </Link>
                            {/* Shown whether or not the integration is
                                connected: a nav item that appears and
                                disappears reads as a bug, and the pages
                                themselves explain how to connect. Topics
                                moved here — YouTube playlists are the
                                grouping now. */}
                            <Link href="/youtube" className="hover:text-foreground">
                                YouTube
                            </Link>
                            <Link href="/drive" className="hover:text-foreground">
                                Drive
                            </Link>
                            {/* What Keje is keeping on the server, and why.
                                Local disk is the one resource the app can
                                exhaust on its own. */}
                            <Link href="/storage" className="hover:text-foreground">
                                Storage
                            </Link>
                            <Link href="/settings" className="hover:text-foreground">
                                Settings
                            </Link>
                        </nav>
                    </div>
                    <div className="flex shrink-0 items-center gap-2 text-sm">
                        {/*
                            Creating content is the app's primary action, so it
                            lives in the chrome rather than on one page. It sits
                            outside the scrolling nav strip deliberately: the
                            one control that must never be scrolled to is the
                            one that should not be in a scrolling container.

                            The label collapses to "New" on narrow screens
                            while the accessible name stays complete — a button
                            reading "New" tells a screen reader nothing.
                        */}
                        <Button asChild size="sm">
                            <Link href="/studio/new" aria-label="New Content">
                                <span aria-hidden>+&nbsp;</span>
                                <span className="hidden sm:inline">New Content</span>
                                <span className="sm:hidden">New</span>
                            </Link>
                        </Button>
                        <span className="hidden text-muted-foreground lg:inline">{user?.email}</span>
                        <Button size="sm" variant="outline" onClick={() => void logout()}>
                            Sign out
                        </Button>
                    </div>
                </div>
            </header>
            <main className="flex-1">{children}</main>
        </div>
    );
}
