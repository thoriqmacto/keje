"use client";

import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import useSWR from "swr";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { SettingsHeader } from "@/components/settings/settings-nav";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { apiErrorMessage, studioKeys } from "@/lib/studio/api";
import { formatDateTime } from "@/lib/studio/format";
import type { GoogleConnection } from "@/lib/types/studio";

/** Messages for the ?google= codes the API callback redirects back with. */
const CALLBACK_MESSAGES: Record<string, { ok: boolean; text: string }> = {
    connected: { ok: true, text: "Google connected." },
    denied: { ok: false, text: "Google authorization was cancelled." },
    invalid: { ok: false, text: "Google returned an incomplete response." },
    invalid_state: {
        ok: false,
        text: "That authorization link has expired or was already used. Please try again.",
    },
    failed: { ok: false, text: "Could not complete the Google connection." },
};

async function getConnection(): Promise<GoogleConnection> {
    const { data } = await api.get<{ data: GoogleConnection }>("/integrations/google");
    return data.data;
}

export default function IntegrationsClient() {
    const params = useSearchParams();
    const { data: google, isLoading, mutate } = useSWR(studioKeys.google, getConnection);
    const [busy, setBusy] = useState(false);

    // Surface the outcome of the OAuth round-trip exactly once.
    useEffect(() => {
        const code = params.get("google");
        if (!code) return;

        const message = CALLBACK_MESSAGES[code];
        if (message) {
            if (message.ok) {
                toast.success(message.text);
            } else {
                toast.error(message.text);
            }
        }

        void mutate();
        window.history.replaceState({}, "", "/settings/integrations");
    }, [params, mutate]);

    async function onConnect() {
        setBusy(true);
        try {
            const { data } = await api.post<{ data: { authorization_url: string } }>(
                "/integrations/google/redirect",
            );
            // Full navigation: consent happens on Google's own origin.
            window.location.href = data.data.authorization_url;
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not start the Google connection."));
            setBusy(false);
        }
    }

    async function onDisconnect() {
        setBusy(true);
        try {
            await api.delete("/integrations/google");
            await mutate();
            toast.success("Google disconnected.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not disconnect Google."));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-10">
            {/* Breadcrumb + section tabs; the "Settings" crumb and the Account
                tab are both routes back out of this sub-section. */}
            <SettingsHeader description="Google Drive backup and YouTube publishing. Credentials stay on the API server." />

            {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

            {google && (
                <Card>
                    <CardHeader>
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle>Google</CardTitle>
                                <CardDescription>
                                    Drive backup and YouTube Data API v3.
                                </CardDescription>
                            </div>
                            <span
                                className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                                    google.connected
                                        ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                                        : "bg-muted text-muted-foreground"
                                }`}
                            >
                                {google.connected ? "Connected" : "Not connected"}
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-5">
                        {!google.configured && (
                            <p className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                                Google is not configured on the server. Set{" "}
                                <code className="font-mono">GOOGLE_CLIENT_ID</code>,{" "}
                                <code className="font-mono">GOOGLE_CLIENT_SECRET</code> and{" "}
                                <code className="font-mono">GOOGLE_REDIRECT_URI</code> in the API
                                environment.
                            </p>
                        )}

                        {google.connected && (
                            <>
                                <dl className="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
                                    <dt className="text-muted-foreground">Google account</dt>
                                    <dd>{google.account_email ?? "—"}</dd>
                                    <dt className="text-muted-foreground">YouTube channel</dt>
                                    <dd>{google.youtube_channel_title ?? "—"}</dd>
                                    <dt className="text-muted-foreground">Channel ID</dt>
                                    <dd className="truncate font-mono text-xs">
                                        {google.youtube_channel_id ?? "—"}
                                    </dd>
                                    <dt className="text-muted-foreground">Connected</dt>
                                    <dd>{formatDateTime(google.connected_at)}</dd>
                                </dl>

                                {/* A wrong channel must be loud: uploading a
                                    lecture to the wrong place is not undoable. */}
                                {google.channel_matches_expected === false && (
                                    <div className="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
                                        <p className="font-medium">Unexpected YouTube channel</p>
                                        <p>
                                            This account controls{" "}
                                            <span className="font-mono">
                                                {google.youtube_channel_id}
                                            </span>
                                            , but uploads are configured for{" "}
                                            <span className="font-mono">
                                                {google.expected_channel_id}
                                            </span>
                                            . Uploads are blocked until you reconnect with the
                                            correct account.
                                        </p>
                                    </div>
                                )}

                                {google.channel_matches_expected === true && (
                                    <p className="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-400">
                                        Channel verified against the configured channel ID.
                                    </p>
                                )}

                                {google.channel_matches_expected === null && (
                                    <p className="text-xs text-muted-foreground">
                                        No expected channel is configured, or the channel could not
                                        be read. Set{" "}
                                        <code className="font-mono">
                                            YOUTUBE_EXPECTED_CHANNEL_ID
                                        </code>{" "}
                                        on the API to enable verification.
                                    </p>
                                )}
                            </>
                        )}

                        <div className="flex flex-wrap gap-2">
                            <Button onClick={() => void onConnect()} disabled={busy || !google.configured}>
                                {google.connected ? "Reconnect" : "Connect Google"}
                            </Button>
                            {google.connected && (
                                <Button
                                    variant="outline"
                                    onClick={() => void onDisconnect()}
                                    disabled={busy}
                                >
                                    Disconnect
                                </Button>
                            )}
                        </div>

                        <p className="text-xs text-muted-foreground">
                            Google may restrict uploads from unverified YouTube Data API projects to
                            private visibility until an API audit is completed. During development,
                            expect uploaded videos to stay private — this is a Google policy, not a
                            Keje bug.
                        </p>
                    </CardContent>
                </Card>
            )}
        </section>
    );
}
