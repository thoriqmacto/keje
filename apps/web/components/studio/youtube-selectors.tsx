"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import {
    googleKeys,
    getYouTubeChannel,
    listYouTubeCategories,
    listYouTubeLanguages,
    listYouTubePlaylists,
    studioKeys,
} from "@/lib/studio/api";
import { api } from "@/lib/api";
import { catalogOptions, filterByTitle } from "@/lib/studio/youtube-catalog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type {
    GoogleIntegrations,
    YouTubeLanguage,
    YouTubePlaylist,
    YouTubeVideoCategory,
} from "@/lib/types/studio";

/**
 * Choosers backed by the connected YouTube account.
 *
 * The point of this iteration: nobody should type `PLxxxx` or `27` when the
 * connected channel can be asked what it actually has. Google's ids are still
 * what gets stored — only the presentation changes.
 *
 * One rule runs through all of these: a stored id is never discarded because
 * the catalog could not be loaded. If YouTube is disconnected or erroring, the
 * raw id is shown with an explanation, so saving the form cannot quietly erase
 * a destination that was chosen while it was working.
 */

async function getIntegrations(): Promise<GoogleIntegrations> {
    const { data } = await api.get<{ data: GoogleIntegrations }>("/integrations/google");
    return data.data;
}

/** Shared SWR config: this data changes rarely and costs YouTube quota. */
const CATALOG_SWR = {
    revalidateOnFocus: false,
    revalidateIfStale: false,
    shouldRetryOnError: false,
} as const;

export function useGoogleIntegrations() {
    return useSWR(studioKeys.google, getIntegrations, { revalidateOnFocus: false });
}

/** One line of explanation under a chooser that could not populate. */
function FieldNote({ children }: { children: React.ReactNode }) {
    return <p className="text-xs text-muted-foreground">{children}</p>;
}

function NotConnected({ what }: { what: string }) {
    return (
        <FieldNote>
            <Link href="/settings/integrations" className="underline">
                Connect YouTube
            </Link>{" "}
            to choose {what}.
        </FieldNote>
    );
}

// ── Channel ─────────────────────────────────────────────────────────────────

/** Where uploads will go, so the destination is never a surprise. */
export function YouTubeChannelSummary({ compact = false }: { compact?: boolean }) {
    const { data: integrations } = useGoogleIntegrations();
    const connected = integrations?.youtube.connected ?? false;

    const { data: channel, isLoading } = useSWR(
        connected ? googleKeys.channel : null,
        getYouTubeChannel,
        CATALOG_SWR,
    );

    if (!connected) {
        return (
            <div className="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                <p className="font-medium text-foreground">YouTube is not connected</p>
                <p>
                    You can create and render this project now, then{" "}
                    <Link href="/settings/integrations" className="underline">
                        connect YouTube
                    </Link>{" "}
                    before publishing.
                </p>
            </div>
        );
    }

    if (isLoading) return <FieldNote>Loading channel…</FieldNote>;
    if (!channel) return <FieldNote>Channel unavailable.</FieldNote>;

    return (
        <div className={compact ? "flex items-center gap-2 text-sm" : "flex items-center gap-3"}>
            {channel.thumbnail_url && (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                    src={channel.thumbnail_url}
                    alt=""
                    className={compact ? "size-6 rounded-full" : "size-10 rounded-full"}
                />
            )}
            <div className="min-w-0">
                <p className="truncate font-medium">{channel.title}</p>
                {!compact && channel.custom_url && (
                    <p className="truncate text-xs text-muted-foreground">{channel.custom_url}</p>
                )}
            </div>
            <span className="ml-auto shrink-0 text-xs text-emerald-600 dark:text-emerald-400">
                Connected
            </span>
        </div>
    );
}

// ── Playlist ────────────────────────────────────────────────────────────────

export function YouTubePlaylistSelector({
    value,
    onChange,
    label = "Playlist",
    inheritedFrom,
    id = "yt_playlist",
}: {
    value: string | null | undefined;
    onChange: (playlistId: string | null) => void;
    label?: string;
    /** Shown when the value comes from the topic rather than this project. */
    inheritedFrom?: string | null;
    id?: string;
}) {
    const { data: integrations } = useGoogleIntegrations();
    const connected = integrations?.youtube.connected ?? false;
    const [search, setSearch] = useState("");

    const { data, isLoading, error, mutate } = useSWR(
        connected ? googleKeys.playlists : null,
        () => listYouTubePlaylists(),
        CATALOG_SWR,
    );

    // Memoised so the fallback [] is not a new array on every render, which
    // would make the filter below recompute needlessly.
    const playlists = useMemo<YouTubePlaylist[]>(() => data?.data ?? [], [data]);

    const options = useMemo(
        () =>
            catalogOptions(
                filterByTitle(playlists, search),
                (playlist) => `${playlist.title} \u00b7 ${playlist.item_count} videos`,
                value,
                { loading: isLoading, unknownLabel: (id) => `${id} (not in this channel)` },
            ),
        [playlists, search, value, isLoading],
    );

    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>{label}</Label>

            {!connected && (
                <>
                    {value ? (
                        <FieldNote>
                            Playlist <span className="font-mono">{value}</span> — connect YouTube to
                            resolve its name.
                        </FieldNote>
                    ) : (
                        <NotConnected what="a playlist" />
                    )}
                </>
            )}

            {connected && (
                <>
                    {playlists.length > 8 && (
                        <Input
                            placeholder="Search playlists…"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="mb-1"
                        />
                    )}

                    <select
                        id={id}
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                        value={value ?? ""}
                        disabled={isLoading || Boolean(error)}
                        onChange={(event) => onChange(event.target.value || null)}
                    >
                        <option value="">
                            {inheritedFrom ? `Use the topic's playlist (${inheritedFrom})` : "No playlist"}
                        </option>

                        {/* catalogOptions prepends a stored id the catalog
                            does not list — a playlist deleted since, or a page
                            not loaded. Keeping it as an option is what stops
                            saving from erasing it. */}
                        {options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>

                    {isLoading && <FieldNote>Loading playlists…</FieldNote>}

                    {error && (
                        <FieldNote>
                            Could not load playlists.{" "}
                            <button type="button" className="underline" onClick={() => void mutate()}>
                                Retry
                            </button>
                        </FieldNote>
                    )}

                    {!isLoading && !error && playlists.length === 0 && (
                        <FieldNote>
                            This channel has no playlists a video can be added to.
                        </FieldNote>
                    )}

                    {inheritedFrom && !value && (
                        <FieldNote>Linked from the topic.</FieldNote>
                    )}
                </>
            )}
        </div>
    );
}

// ── Category ────────────────────────────────────────────────────────────────

export function YouTubeCategorySelector({
    value,
    onChange,
    id = "yt_category",
}: {
    value: string | null | undefined;
    onChange: (categoryId: string | null) => void;
    id?: string;
}) {
    const { data: integrations } = useGoogleIntegrations();
    const connected = integrations?.youtube.connected ?? false;

    const { data, isLoading, error, mutate } = useSWR(
        connected ? googleKeys.categories : null,
        listYouTubeCategories,
        CATALOG_SWR,
    );

    const categories: YouTubeVideoCategory[] = data ?? [];

    const options = catalogOptions(
        categories,
        (category) => `${category.title} (${category.id})`,
        value,
        { loading: isLoading, unknownLabel: (id) => `${id} (not assignable here)` },
    );

    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>Category</Label>

            {!connected ? (
                value ? (
                    <FieldNote>
                        <span className="font-mono">{value}</span> — reconnect YouTube to resolve the
                        category name.
                    </FieldNote>
                ) : (
                    <NotConnected what="a category" />
                )
            ) : (
                <>
                    <select
                        id={id}
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                        value={value ?? ""}
                        disabled={isLoading || Boolean(error)}
                        onChange={(event) => onChange(event.target.value || null)}
                    >
                        <option value="">No category</option>

                        {options.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>

                    {isLoading && <FieldNote>Loading categories…</FieldNote>}

                    {error && (
                        <FieldNote>
                            Could not load categories.{" "}
                            <button type="button" className="underline" onClick={() => void mutate()}>
                                Retry
                            </button>
                        </FieldNote>
                    )}
                </>
            )}
        </div>
    );
}

// ── Language ────────────────────────────────────────────────────────────────

export function YouTubeLanguageSelector({
    value,
    onChange,
    id = "yt_language",
}: {
    value: string | null | undefined;
    onChange: (language: string | null) => void;
    id?: string;
}) {
    const { data: integrations } = useGoogleIntegrations();
    const connected = integrations?.youtube.connected ?? false;

    const { data, isLoading } = useSWR(
        connected ? googleKeys.languages : null,
        listYouTubeLanguages,
        CATALOG_SWR,
    );

    const languages: YouTubeLanguage[] = data ?? [];

    if (!connected) return null;

    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor={id}>Video language</Label>
            <select
                id={id}
                className="h-9 rounded-md border bg-background px-3 text-sm"
                value={value ?? ""}
                disabled={isLoading}
                onChange={(event) => onChange(event.target.value || null)}
            >
                <option value="">Not set</option>
                {catalogOptions(languages, (language) => language.title, value, {
                    loading: isLoading,
                }).map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {isLoading && <FieldNote>Loading languages…</FieldNote>}
        </div>
    );
}
