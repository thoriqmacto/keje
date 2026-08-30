"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/components/auth-provider";
import { authenticatedDestination } from "@/lib/auth/redirects";

/**
 * Shows `children` only to a genuinely signed-out visitor.
 *
 * The middleware `auth_hint` fast path stops the anonymous page flashing, but
 * the hint is forgeable and can be stale, so this is the authoritative half:
 * it waits for AuthProvider to settle the session against Laravel's /me and
 * redirects anyone who turns out to be signed in.
 *
 * While the session is unresolved it renders a neutral placeholder rather than
 * the sign-in call to action, so an authenticated user never sees a form they
 * cannot use.
 */
export function AnonymousOnly({ children }: { children: ReactNode }) {
    const { status } = useAuth();
    const router = useRouter();

    useEffect(() => {
        if (status !== "authenticated") return;
        // Read the query string here rather than with useSearchParams(): this
        // only ever runs in the browser, so the page stays statically
        // renderable and needs no Suspense boundary.
        const next = new URLSearchParams(window.location.search).get("next");
        router.replace(authenticatedDestination(next));
    }, [status, router]);

    if (status !== "anonymous") {
        return (
            <div
                aria-busy="true"
                className="flex min-h-[50vh] items-center justify-center text-sm text-muted-foreground"
            >
                Loading…
            </div>
        );
    }

    return <>{children}</>;
}
