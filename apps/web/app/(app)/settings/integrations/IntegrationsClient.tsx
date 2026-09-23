"use client";

import { useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
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
import {
    apiErrorMessage,
    checkGoogleHealth,
    getGoogleHealth,
    studioKeys,
} from "@/lib/studio/api";
import { formatDateTime } from "@/lib/studio/format";
import { healthTone, isStale } from "@/lib/studio/google-health";
import type { GoogleHealth, GoogleIntegrations, GoogleServiceKey } from "@/lib/types/studio";

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
    const { data: health, mutate: mutateHealth } = useSWR(studioKeys.googleHealth, getGoogleHealth);
    const [busy, setBusy] = useState<GoogleServiceKey | null>(null);
    const [checking, setChecking] = useState(false);

    /**
     * Probe both services now, rather than waiting for the hourly run.
     *
     * The button for somebody who has just fixed a client secret or
     * re-enabled an API and wants to be believed immediately. It is slow —
     * a token exchange plus an API call per service — which is why it is a
     * button and not what the page does on load.
     */
    async function onCheckNow() {
        setChecking(true);
        try {
            const result = await checkGoogleHealth();
            // Seed the cache with what came back instead of re-fetching:
            // the POST already returned the fresh verdicts.
            await mutateHealth(result, { revalidate: false });
            toast.success("Checked both connections.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not check the connections."));
        } finally {
            setChecking(false);
        }
    }

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
        // A fresh grant clears the recorded health server-side; re-read so a
        // banner about the connection just fixed disappears immediately.
        void mutateHealth();
        window.history.replaceState({}, "", "/settings/integrations");
    }, [params, mutate, mutateHealth]);

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
            await mutateHealth();
            toast.success(`${SERVICE_LABELS[service]} disconnected.`);
        } catch (error) {
            toast.error(
                apiErrorMessage(error, `Could not disconnect ${SERVICE_LABELS[service]}.`),
            );
        } finally {
            setBusy(null);
        }
    }

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

            {/*
                Keje checks these on a schedule so a dead grant is found
                within the hour rather than at upload time. The button is for
                the minute after somebody fixes something and does not want to
                wait for the next run to be believed.
            */}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">
                    {health
                        ? describeLastCheck(health.youtube, health.drive)
                        : "Checking connection health…"}
                </p>
                <Button variant="outline" size="sm" onClick={() => void onCheckNow()} disabled={checking}>
                    {checking ? "Checking…" : "Check now"}
                </Button>
            </div>

            {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

            {data && (
                <IntegrationCard
                    title="YouTube"
                    description="Uploads rendered videos and schedules publication."
                    connected={data.youtube.connected}
                    configured={data.youtube.configured}
                    envHint="GOOGLE_YOUTUBE_CLIENT_ID, GOOGLE_YOUTUBE_CLIENT_SECRET and GOOGLE_YOUTUBE_REDIRECT_URI"
                    connectedAt={data.youtube.connected_at}
                    health={health?.youtube}
                    busy={busy !== null}
                    onConnect={() => void onConnect("youtube")}
                    onDisconnect={() => void onDisconnect("youtube")}
                >
                    {data.youtube.connected && (
                        <>
                            {/* Settings manages the connection; browsing the
                                channel is a different job and has its own
                                page. A catalog embedded here buried the
                                connect/disconnect controls it exists for. */}
                            <dl className="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
                                <dt className="text-muted-foreground">Channel</dt>
                                <dd>{data.youtube.channel_title ?? "—"}</dd>
                                <dt className="text-muted-foreground">Connected</dt>
                                <dd>{formatDateTime(data.youtube.connected_at)}</dd>
                            </dl>

                            {data.youtube.needs_scope_upgrade && (
                                <div className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                                    <p className="font-medium">Reconnect to enable playlist assignment</p>
                                    <p>
                                        This connection was made before Keje asked for playlist
                                        permission. Uploads and channel reads keep working; only
                                        adding videos to playlists needs it.
                                    </p>
                                </div>
                            )}

                            <Button asChild variant="outline" size="sm" className="self-start">
                                <Link href="/youtube">Browse YouTube</Link>
                            </Button>

                            {/* A wrong channel must be loud: uploading a lecture
                                to the wrong place is not undoable. Drive backup
                                is unaffected by this. */}
                            {data.youtube.channel_matches_expected === false && (
                                <div className="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
                                    <p className="font-medium">Unexpected YouTube channel</p>
                                    <p>
                                        This account controls{" "}
                                        <span className="font-mono">{data.youtube.channel_id}</span>, but
                                        uploads are configured for{" "}
                                        <span className="font-mono">
                                            {data.youtube.expected_channel_id}
                                        </span>
                                        . YouTube uploads are blocked until you reconnect with the
                                        correct account. Google Drive backup still works.
                                    </p>
                                </div>
                            )}

                            {data.youtube.channel_matches_expected === true && (
                                <p className="rounded-md bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-400">
                                    Channel verified against the configured channel ID.
                                </p>
                            )}

                            {data.youtube.channel_matches_expected === null && (
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

            {data && (
                <IntegrationCard
                    title="Google Drive"
                    description="Backs up rendered MP4 files to Google Drive."
                    connected={data.drive.connected}
                    configured={data.drive.configured}
                    envHint="GOOGLE_DRIVE_CLIENT_ID, GOOGLE_DRIVE_CLIENT_SECRET and GOOGLE_DRIVE_REDIRECT_URI"
                    connectedAt={data.drive.connected_at}
                    health={health?.drive}
                    busy={busy !== null}
                    onConnect={() => void onConnect("drive")}
                    onDisconnect={() => void onDisconnect("drive")}
                >
                    {data.drive.connected && (
                        <>
                            <dl className="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
                                <dt className="text-muted-foreground">Connected</dt>
                                <dd>{formatDateTime(data.drive.connected_at)}</dd>
                            </dl>

                            <Button asChild variant="outline" size="sm" className="self-start">
                                <Link href="/drive">Browse Google Drive</Link>
                            </Button>
                        </>
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
    health,
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
    health?: GoogleHealth;
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
                    {/*
                        The pill says whether it *works*, not whether a row
                        exists. "Connected" sitting above "Google rejected the
                        stored credentials" is exactly the reassurance that
                        sent people to discover the truth at upload time.
                        Until the first check lands it falls back to the
                        stored state, which is all that is known.
                    */}
                    <span
                        className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                            health
                                ? SEVERITY_PILL[healthTone(health.status).severity]
                                : connected
                                  ? SEVERITY_PILL.ok
                                  : SEVERITY_PILL.info
                        }`}
                    >
                        {health
                            ? healthTone(health.status).label
                            : connected
                              ? "Connected"
                              : "Not connected"}
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

                {health && <HealthPanel health={health} />}

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

/** One palette, so the pill and the panel cannot disagree about a severity. */
const SEVERITY_PILL: Record<string, string> = {
    ok: "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400",
    info: "bg-muted text-muted-foreground",
    warning: "bg-amber-500/10 text-amber-700 dark:text-amber-400",
    critical: "bg-red-500/10 text-red-600 dark:text-red-400",
};

const SEVERITY_PANEL: Record<string, string> = {
    ok: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-400",
    info: "bg-muted text-muted-foreground",
    warning: "bg-amber-500/10 text-amber-800 dark:text-amber-400",
    critical: "bg-red-500/10 text-red-600 dark:text-red-400",
};

/**
 * What the check found, and what to do about it.
 *
 * The guidance is the reason this is a panel rather than another pill. Every
 * broken status has a different fix — a dead refresh token needs a reconnect,
 * a mismatched client secret needs an .env edit and a config:cache, a
 * disabled API needs the Cloud console — and the old single sentence
 * ("reconnect") was right for one of them and wasted work for the rest.
 */
function HealthPanel({ health }: { health: GoogleHealth }) {
    const tone = healthTone(health.status);
    const stale = isStale(health.checked_at);

    return (
        <div className={`flex flex-col gap-2 rounded-md px-3 py-2 text-sm ${SEVERITY_PANEL[tone.severity]}`}>
            <p className="font-medium">{health.message}</p>

            {/*
                Only ever set for a grant from a consent screen still in
                Testing, which Google expires seven days after issuing. Every
                other connection has no countdown at all, and showing an
                invented one would be worse than showing none.
            */}
            {health.expires_in_human && (
                <p>
                    Stops working in about <strong>{health.expires_in_human}</strong>
                    {health.expires_at ? ` (${formatDateTime(health.expires_at)})` : ""}.
                </p>
            )}

            {/* "Broken" is skimmed; "broken since Tuesday" is acted on. */}
            {health.failing_since && (
                <p className="text-xs opacity-80">
                    Failing since {formatDateTime(health.failing_since)}.
                </p>
            )}

            {health.guidance.length > 0 && (
                <ol className="list-decimal space-y-1 pl-5 text-xs opacity-90">
                    {health.guidance.map((step) => (
                        <li key={step}>{step}</li>
                    ))}
                </ol>
            )}

            <p className="text-xs opacity-70">
                {health.checked_at
                    ? `Last checked ${formatDateTime(health.checked_at)}${
                          stale ? " — the scheduled check may not be running." : ""
                      }`
                    : "Not checked yet."}
            </p>
        </div>
    );
}

/**
 * One line about how current these answers are.
 *
 * Reports the older of the two, because the page shows both and the stalest
 * one is what determines whether any of it can be trusted.
 */
function describeLastCheck(youtube: GoogleHealth, drive: GoogleHealth): string {
    const times = [youtube.checked_at, drive.checked_at].filter(
        (value): value is string => value !== null,
    );

    if (times.length === 0) {
        return "These connections have not been checked yet. Keje checks them hourly.";
    }

    const oldest = times.reduce((a, b) => (new Date(a) < new Date(b) ? a : b));

    return isStale(oldest)
        ? `Last checked ${formatDateTime(oldest)}. That is older than the hourly schedule — check the scheduler is running.`
        : `Checked hourly. Last check ${formatDateTime(oldest)}.`;
}
