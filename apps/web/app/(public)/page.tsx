import Link from "next/link";
import { Button } from "@/components/ui/button";
import { AnonymousOnly } from "@/components/anonymous-only";
import { APP_NAME } from "@/lib/env";

export default function LandingPage() {
    // The markup stays a server component: AnonymousOnly only decides whether
    // to display it, so nothing here is pushed into the client bundle.
    return (
        <AnonymousOnly>
            <section className="mx-auto flex w-full max-w-5xl flex-col gap-10 px-4 py-16 md:py-24">
                <div className="flex flex-col gap-4">
                    <h1 className="text-4xl font-semibold tracking-tight md:text-5xl">
                        {APP_NAME}
                    </h1>
                    <p className="text-xl text-muted-foreground">
                        YouTube content production for lecture recordings.
                    </p>
                    <p className="max-w-2xl text-muted-foreground">
                        Turn lecture audio and artwork into Kajian Tematik videos, then manage
                        Drive backup and YouTube publishing.
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
            </section>
        </AnonymousOnly>
    );
}
