"use client";

import useSWR from "swr";
import { toast } from "sonner";
import {
    apiErrorMessage,
    getDriveAbout,
    getYouTubeChannel,
    googleKeys,
    listDriveBackups,
    listYouTubePlaylists,
    listYouTubeRecentUploads,
    refreshDriveCatalog,
    refreshYouTubeCatalog,
} from "@/lib/studio/api";
import { Button } from "@/components/ui/button";
import { formatBytes, formatDateTime } from "@/lib/studio/format";
import type { GoogleIntegrations } from "@/lib/types/studio";

/**
 * Connected-account detail for the integrations page.
 *
 * Each section fetches independently so one failing Google call cannot take
 * the page down with it: a quota error on playlists still leaves the channel
 * profile and recent uploads rendering, each reporting its own problem.
 *
 * Nothing here ever receives a token — every read goes through Laravel.
 */

const SWR = { revalidateOnFocus: false, shouldRetryOnError: false } as const;

function SectionError({ label, onRetry }: { label: string; onRetry: () => void }) {
    return (
        <p className="text-xs text-muted-foreground">
            {label}{" "}
            <button type="button" className="underline" onClick={onRetry}>
                Retry
            </button>
        </p>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">{value}</dd>
        </div>
    );
}

const number = (value: number | null | undefined) =>
    value === null || value === undefined ? "—" : value.toLocaleString();

// ── YouTube ─────────────────────────────────────────────────────────────────

export function YouTubeIntegrationDetail({
    integrations,
    onReconnect,
}: {
    integrations: GoogleIntegrations;
    onReconnect: () => void;
}) {
    const connected = integrations.youtube.connected;
    const capabilities = integrations.youtube.capabilities;

    const channel = useSWR(connected ? googleKeys.channel : null, getYouTubeChannel, SWR);
    const playlists = useSWR(
        connected ? googleKeys.playlists : null,
        () => listYouTubePlaylists(),
        SWR,
    );
    const uploads = useSWR(
        connected ? googleKeys.recentUploads : null,
        listYouTubeRecentUploads,
        SWR,
    );

    if (!connected) return null;

    async function refresh() {
        try {
            await refreshYouTubeCatalog();
            await Promise.all([channel.mutate(), playlists.mutate(), uploads.mutate()]);
            toast.success("Refreshed from YouTube.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not refresh from YouTube."));
        }
    }

    return (
        <div className="flex flex-col gap-5">
            {/* Capabilities come from the granted scopes, so an older grant
                shows exactly which one thing needs a reconnect rather than
                implying the whole integration is broken. */}
            <div className="flex flex-col gap-1.5 text-sm">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Capabilities
                </p>
                <Capability ok={capabilities.read_channel} label="Read channel" />
                <Capability ok={capabilities.upload_video} label="Upload videos" />
                <Capability
                    ok={capabilities.manage_playlists}
                    label="Assign playlists"
                    missingHint="Reconnect required"
                />
            </div>

            {integrations.youtube.needs_scope_upgrade && (
                <div className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400">
                    <p className="font-medium">Reconnect to enable playlist assignment</p>
                    <p>
                        This connection was made before Keje asked for playlist permission. Uploads
                        and channel reads keep working; only adding videos to playlists needs it.
                    </p>
                    <Button size="sm" variant="outline" className="mt-2" onClick={onReconnect}>
                        Reconnect YouTube
                    </Button>
                </div>
            )}

            {channel.isLoading && <p className="text-xs text-muted-foreground">Loading channel…</p>}
            {channel.error && (
                <SectionError label="Could not load the channel." onRetry={() => void channel.mutate()} />
            )}

            {channel.data && (
                <div className="flex flex-col gap-3">
                    <div className="flex items-center gap-3">
                        {channel.data.thumbnail_url && (
                            // eslint-disable-next-line @next/next/no-img-element
                            <img src={channel.data.thumbnail_url} alt="" className="size-12 rounded-full" />
                        )}
                        <div className="min-w-0">
                            <p className="truncate font-medium">{channel.data.title}</p>
                            {channel.data.custom_url && (
                                <p className="truncate text-xs text-muted-foreground">
                                    {channel.data.custom_url}
                                </p>
                            )}
                        </div>
                    </div>

                    <dl className="grid grid-cols-3 gap-3">
                        <Stat
                            label="Subscribers"
                            value={
                                channel.data.hidden_subscriber_count
                                    ? "Hidden"
                                    : number(channel.data.subscriber_count)
                            }
                        />
                        <Stat label="Videos" value={number(channel.data.video_count)} />
                        <Stat label="Total views" value={number(channel.data.view_count)} />
                    </dl>

                    {/* Secondary, for diagnostics — the name is the primary fact. */}
                    <p className="font-mono text-xs text-muted-foreground">
                        {channel.data.channel_id}
                    </p>
                </div>
            )}

            <div className="flex flex-col gap-2 border-t pt-4">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Playlists
                </p>
                {playlists.isLoading && <p className="text-xs text-muted-foreground">Loading…</p>}
                {playlists.error && (
                    <SectionError
                        label="Could not load playlists."
                        onRetry={() => void playlists.mutate()}
                    />
                )}
                {playlists.data?.data.length === 0 && (
                    <p className="text-xs text-muted-foreground">
                        No playlists a video can be added to.
                    </p>
                )}
                {playlists.data?.data.slice(0, 8).map((playlist) => (
                    <div key={playlist.id} className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="truncate">{playlist.title}</span>
                        <span className="shrink-0 text-xs capitalize text-muted-foreground">
                            {playlist.item_count} videos · {playlist.privacy_status}
                        </span>
                    </div>
                ))}
            </div>

            <div className="flex flex-col gap-2 border-t pt-4">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Recent uploads
                </p>
                {uploads.isLoading && <p className="text-xs text-muted-foreground">Loading…</p>}
                {uploads.error && (
                    <SectionError
                        label="Could not load recent uploads."
                        onRetry={() => void uploads.mutate()}
                    />
                )}
                {uploads.data?.slice(0, 5).map((upload) => (
                    <a
                        key={upload.video_id}
                        href={upload.url}
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center gap-2 text-sm hover:underline"
                    >
                        {upload.thumbnail_url && (
                            // eslint-disable-next-line @next/next/no-img-element
                            <img src={upload.thumbnail_url} alt="" className="h-8 w-14 rounded object-cover" />
                        )}
                        <span className="min-w-0 flex-1 truncate">{upload.title}</span>
                        <span className="shrink-0 text-xs text-muted-foreground">
                            {formatDateTime(upload.published_at)}
                        </span>
                    </a>
                ))}
            </div>

            <Button size="sm" variant="outline" className="self-start" onClick={() => void refresh()}>
                Refresh from YouTube
            </Button>
        </div>
    );
}

function Capability({
    ok,
    label,
    missingHint = "Not granted",
}: {
    ok: boolean;
    label: string;
    missingHint?: string;
}) {
    return (
        <p className="flex items-center gap-2">
            <span className={ok ? "text-emerald-600 dark:text-emerald-400" : "text-amber-600 dark:text-amber-400"}>
                {ok ? "✓" : "!"}
            </span>
            <span>{label}</span>
            {!ok && <span className="text-xs text-muted-foreground">{missingHint}</span>}
        </p>
    );
}

// ── Drive ───────────────────────────────────────────────────────────────────

export function DriveIntegrationDetail({ integrations }: { integrations: GoogleIntegrations }) {
    const connected = integrations.drive.connected;

    const about = useSWR(connected ? googleKeys.driveAbout : null, getDriveAbout, SWR);
    const backups = useSWR(
        connected ? googleKeys.driveBackups : null,
        () => listDriveBackups(),
        SWR,
    );

    if (!connected) return null;

    async function refresh() {
        try {
            await refreshDriveCatalog();
            await Promise.all([about.mutate(), backups.mutate()]);
            toast.success("Refreshed from Drive.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not refresh from Drive."));
        }
    }

    return (
        <div className="flex flex-col gap-5">
            {about.isLoading && <p className="text-xs text-muted-foreground">Loading account…</p>}
            {about.error && (
                <SectionError label="Could not load the Drive account." onRetry={() => void about.mutate()} />
            )}

            {about.data && (
                <>
                    <dl className="grid grid-cols-[8rem_1fr] gap-y-2 text-sm">
                        <dt className="text-muted-foreground">Google account</dt>
                        <dd className="truncate">{about.data.account.email ?? "—"}</dd>

                        <dt className="text-muted-foreground">Storage</dt>
                        <dd>
                            {about.data.storage.unlimited
                                ? "Unlimited"
                                : `${formatBytes(about.data.storage.usage)} of ${formatBytes(
                                      about.data.storage.limit,
                                  )} used`}
                            {about.data.storage.percent_used !== null && (
                                <span className="text-muted-foreground">
                                    {" "}
                                    ({about.data.storage.percent_used}%)
                                </span>
                            )}
                        </dd>

                        <dt className="text-muted-foreground">Backup folder</dt>
                        <dd>
                            {about.data.backup_folder_available ? (
                                about.data.backup_folder?.web_view_link ? (
                                    <a
                                        href={about.data.backup_folder.web_view_link}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline"
                                    >
                                        {about.data.backup_folder.name}
                                    </a>
                                ) : (
                                    about.data.backup_folder?.name
                                )
                            ) : (
                                <span className="text-amber-700 dark:text-amber-400">
                                    Backup folder unavailable
                                </span>
                            )}
                        </dd>
                    </dl>

                    <div className="flex flex-col gap-2 border-t pt-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            Recent Keje backups
                        </p>
                        {/* Deliberately not "your Drive files": the drive.file
                            scope only ever shows what Keje itself created. */}
                        <p className="text-xs text-muted-foreground">
                            Keje-accessible files only — it never requests access to the rest of
                            your Drive.
                        </p>

                        {backups.isLoading && <p className="text-xs text-muted-foreground">Loading…</p>}
                        {backups.error && (
                            <SectionError
                                label="Could not load backups."
                                onRetry={() => void backups.mutate()}
                            />
                        )}
                        {backups.data?.data.length === 0 && (
                            <p className="text-xs text-muted-foreground">No backups yet.</p>
                        )}
                        {backups.data?.data.slice(0, 5).map((file) => (
                            <div key={file.id} className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="min-w-0 truncate">
                                    {file.web_view_link ? (
                                        <a
                                            href={file.web_view_link}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="hover:underline"
                                        >
                                            {file.name}
                                        </a>
                                    ) : (
                                        file.name
                                    )}
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {formatBytes(file.size)} · {formatDateTime(file.created_at)}
                                </span>
                            </div>
                        ))}
                    </div>
                </>
            )}

            <Button size="sm" variant="outline" className="self-start" onClick={() => void refresh()}>
                Refresh from Drive
            </Button>
        </div>
    );
}
