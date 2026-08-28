import Link from "next/link";
import { Button } from "@/components/ui/button";
import { APP_NAME, API_BASE_URL } from "@/lib/env";

export default function LandingPage() {
    return (
        <section className="mx-auto flex w-full max-w-5xl flex-col gap-10 px-4 py-16 md:py-24">
            <div className="flex flex-col gap-4">
                <span className="text-xs uppercase tracking-widest text-muted-foreground">
                    Public page
                </span>
                <h1 className="text-4xl font-semibold tracking-tight md:text-5xl">
                    Welcome to {APP_NAME}
                </h1>
                <p className="max-w-2xl text-muted-foreground">
                    This is the public, no-login landing page of the starter. Sign in to reach the
                    authenticated dashboard, or create a new account to try the full flow.
                </p>
            </div>

            <div className="flex flex-wrap gap-3">
                <Button asChild>
                    <Link href="/login">Sign in</Link>
                </Button>
                <Button asChild variant="outline">
                    <Link href="/register">Create account</Link>
                </Button>
            </div>

            <div className="rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                API base URL: <code className="font-mono">{API_BASE_URL}</code>
            </div>
        </section>
    );
}
