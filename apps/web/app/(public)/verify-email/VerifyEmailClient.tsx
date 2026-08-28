"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";

type Status = "verified" | "invalid" | "expired" | "unknown";

function readStatus(raw: string | null): Status {
    if (raw === "verified") return "verified";
    if (raw === "invalid") return "invalid";
    if (raw === "expired") return "expired";
    return "unknown";
}

const COPY: Record<Status, { title: string; description: string }> = {
    verified: {
        title: "Email verified",
        description: "Thanks for confirming your email. You can now sign in.",
    },
    invalid: {
        title: "Verification link invalid",
        description: "This link doesn't match the account it was issued for. Try requesting a new one.",
    },
    expired: {
        title: "Verification link expired",
        description: "Sign in and request a new verification email from your settings.",
    },
    unknown: {
        title: "Verifying email",
        description: "If you reached this page from your email link, the result will appear here.",
    },
};

export default function VerifyEmailClient() {
    const params = useSearchParams();
    const status = readStatus(params.get("status"));
    const { title, description } = COPY[status];

    return (
        <div className="mx-auto flex w-full max-w-md flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                    <CardDescription>{description}</CardDescription>
                </CardHeader>
                <CardContent className="text-sm text-muted-foreground">
                    {status === "verified" && (
                        <p>
                            You can close this tab or continue to the dashboard.
                        </p>
                    )}
                </CardContent>
                <CardFooter className="justify-between text-sm">
                    <Link href="/login" className="font-medium hover:underline underline-offset-4">
                        Back to sign in
                    </Link>
                    {status === "verified" && (
                        <Link
                            href="/dashboard"
                            className="font-medium hover:underline underline-offset-4"
                        >
                            Go to dashboard
                        </Link>
                    )}
                </CardFooter>
            </Card>
        </div>
    );
}
