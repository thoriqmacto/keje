"use client";

import { useEffect, useState, type ReactNode } from "react";
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
import type { GoogleIntegrations, GoogleServiceKey } from "@/lib/types/studio";

/** Messages for the ?youtube= / ?drive= codes the API callbacks redirect back with. */
const CALLBACK_MESSAGES: Record<string, { ok: boolean; text: string }> = {
    connected: { ok: true, text: "connected." },
    denied: { ok: false, text: "authorization was cancelled." },
    invalid: { ok: false, text: "returned an incomplete response." },
    invalid_state: {
        ok: false,
        text: "authorization link has expired or was already used. Please try again.",
    },
    failed: { ok: false, text: "could not be connected." },
};

const SERVICE_LABELS: Record<GoogleServiceKey, string> = {
    youtube: "YouTube",
    drive: "Google Drive",
};

async function getIntegrations(): Promise<GoogleIntegrations> {
    const { data } = await api.get<{ data: GoogleIntegrations }>("/integrations/google");
    return data.data;
}

export default function IntegrationsClient() {
    const params = useSearchParams();
    const { data, isLoading, mutate } = useSWR(studioKeys.google, getIntegrations);
    const [busy, setBusy] = useState<GoogleServiceKey | null>(null);

    // Surface the outcome of either OAuth round-trip exactly once.
    useEffect(() => {
        const services: GoogleServiceKey[] = ["youtube", "drive"];
        let handled = false;

        for (const service of services) {
            const code = params.get(service);
            if (!code) continue;

            handled = true;
            const message = CALLBACK_MESSAGES[code];

            if (message) {
                const text = `${SERVICE_LABELS[service]} ${message.text}`;
                if (message.ok) {
                    toast.success(text);
                } else {
                    toast.error(text);
                }
            }
        }

        if (!handled) return;

        void mutate();
        window.history.replaceState({}, "", "/settings/integrations");
    }, [params, mutate]);

    async function onConnect(service: GoogleServiceKey) {
        setBusy(service);
        try {
            const { data: body } = await api.post<{ data: { authorization_url: string } }>(
                `/integrations/${service}/redirect`,
            );
            // Full navigation: consent happens on Google's own origin.
            window.location.href = body.data.authorization_url;
        } catch (error) {
            toast.error(
                apiErrorMessage(
                    error,
                    `Could not start the ${SERVICE_LABELS[service]} connection.`,
                ),
            );
            setBusy(null);
        }
    }

    async function onDisconnect(service: GoogleServiceKey) {
        setBusy(service);
        try {
            await api.delete(`/integrations/${service}`);
            await mutate();
            toast.success(`${SERVICE_LABELS[service]} disconnected.`);
        } catch (error) {
            toast.error(
                apiErrorMessage(error, `Could not disconnect ${SERVICE_LABELS[service]}.`),
            );
        } finally {
            setBusy(null);
        }
    }

    const youtube = data?.youtube;
    const drive = data?.drive;

    return (
        <section className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-10">
            {/* Breadcrumb + section tabs; the "Settings" crumb and the Account
                tab are both routes back out of this sub-section. */}
            <SettingsHeader description="Google Drive backup and YouTube publishing. Credentials stay on the API server." />

            <p className="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                YouTube and Google Drive are authorized <strong>separately</strong>, and Keje asks
                each for only the permissions that feature needs. Connect either one on its own — if
                you connected Google before this change, reconnect them here individually.
            </p>

            {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

            {youtube && (
                <IntegrationCard
                    title="YouTube"
                    description="Uploads rendered videos and schedules publication."
                    connected={youtube.connected}
                    configured={youtube.configured}
                    envHint="GOOGLE_YOUTUBE_CLIENT_ID, GOOGLE_YOUTUBE_CLIENT_SECRET and GOOGLE_YOUTUBE_REDIRECT_URI"
                    connectedAt={youtube.connected_at}
                    busy={busy !== null}
                    onConnect={() => void onConnect("youtube")}
                    onDisconnect={() => void onDisconnect("youtube")}
                >
                    {youtube.connected && (
                        <>
                            <dl className="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
                                <dt className="text-muted-foreground">Channel</dt>
                                <dd>{youtube.channel_title ?? "—"}</dd>
                                <dt className="text-muted-foreground">Channel ID</dt>
                                <dd className="truncate font-mono text-xs">
                                    {youtube.channel_id ?? "—"}
                                </dd>
                                <dt className="text-muted-foreground">Connected</dt>
                                <dd>{formatDateTime(youtube.connected_at)}</dd>
                            </dl>

                            {/* A wrong channel must be loud: uploading a lecture
                                to the wrong place is not undoable. Drive backup
                                is unaffected by this. */}
                            {youtube.channel_matches_expected === false && (
                                <div className="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
                                    <p className="font-medium">Unexpected YouTube channel</p>
                                    <p>
                                        This account controls{" "}
                                        <span className="font-mono">{youtube.channel_id}</span>, but
                                        uploads are configured for{" "}
                                        <span className="font-mono">
                                            {youtube.expected_channel_id}
                                        </span>
                                        . YouTube uploads are blocked until you reconnect with the
                                        correct account. Google Drive backup still works.
                                    </p>
                                </div>
                            )}

                            {youtube.channel_matches_expected === true && (
                                <p className="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-400">
                                    Channel verified against the configured channel ID.
                                </p>
                            )}

                            {youtube.channel_matches_expected === null && (
                                <p className="text-xs text-muted-foreground">
                                    No expected channel is configured, or the channel could not be
                                    read. Set{" "}
                                    <code className="font-mono">YOUTUBE_EXPECTED_CHANNEL_ID</code>{" "}
                                    on the API to enable verification.
                                </p>
                            )}
                        </>
                    )}

                    <p className="text-xs text-muted-foreground">
                        Google may restrict uploads from unverified YouTube Data API projects to
                        private visibility until an API audit is completed. During development,
                        expect uploaded videos to stay private — this is a Google policy, not a Keje
                        bug.
                    </p>
                </IntegrationCard>
            )}

            {drive && (
                <IntegrationCard
                    title="Google Drive"
                    description="Backs up rendered MP4 files to Google Drive."
                    connected={drive.connected}
                    configured={drive.configured}
                    envHint="GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET and GOOGLE_DRIVE_REDIRECT_URI"
                    connectedAt={drive.connected_at}
                    busy={busy !== null}
                    onConnect={() => void onConnect("drive")}
                    onDisconnect={() => void onDisconnect("drive")}
                >
                    {drive.connected && (
                        <dl className="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Connected</dt>
                            <dd>{formatDateTime(drive.connected_at)}</dd>
                        </dl>
                    )}

                    <p className="text-xs text-muted-foreground">
                        Keje asks only for <code className="font-mono">drive.file</code>, which can
                        see the files it created and nothing else in your Drive.
                    </p>
                </IntegrationCard>
            )}
        </section>
    );
}

/**
 * The shell both connections share: status pill, body, and the connect /
 * reconnect / disconnect actions. Kept local — nothing else needs it.
 */
function IntegrationCard({
    title,
    description,
    connected,
    configured,
    envHint,
    busy,
    onConnect,
    onDisconnect,
    children,
}: {
    title: string;
    description: string;
    connected: boolean;
    configured: boolean;
    envHint: string;
    connectedAt: string | null;
    busy: boolean;
    onConnect: () => void;
    onDisconnect: () => void;
    children?: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <CardTitle>{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </div>
                    <span
                        className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                            connected
                                ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                                : "bg-muted text-muted-foreground"
                        }`}
                    >
                        {connected ? "Connected" : "Not connected"}
                    </span>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
                {!configured && (
                    <p className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                        {title} is not configured on the server. Set{" "}
                        <code className="font-mono">{envHint}</code> in the API environment.
                    </p>
                )}

                {children}

                <div className="flex flex-wrap gap-2">
                    <Button onClick={onConnect} disabled={busy || !configured}>
                        {connected ? "Reconnect" : `Connect ${title}`}
                    </Button>
                    {connected && (
                        <Button variant="outline" onClick={onDisconnect} disabled={busy}>
                            Disconnect
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
