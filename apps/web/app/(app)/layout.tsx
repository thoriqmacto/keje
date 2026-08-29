"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useAuth } from "@/components/auth-provider";
import { Button } from "@/components/ui/button";
import { APP_NAME } from "@/lib/env";

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
        <div className="flex min-h-screen flex-col">
            <header className="border-b">
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
                        <nav className="flex items-center gap-4 overflow-x-auto whitespace-nowrap text-sm text-muted-foreground">
                            <Link href="/dashboard" className="hover:text-foreground">
                                Dashboard
                            </Link>
                            <Link href="/studio" className="hover:text-foreground">
                                Studio
                            </Link>
                            <Link href="/studio/topics" className="hover:text-foreground">
                                Topics
                            </Link>
                            <Link href="/settings" className="hover:text-foreground">
                                Settings
                            </Link>
                        </nav>
                    </div>
                    <div className="flex shrink-0 items-center gap-3 text-sm">
                        <span className="text-muted-foreground hidden sm:inline">{user?.email}</span>
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
