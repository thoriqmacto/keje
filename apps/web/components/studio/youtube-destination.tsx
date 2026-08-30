"use client";

import { useState } from "react";
import useSWR from "swr";
import { toast } from "sonner";
import {
    apiErrorMessage,
    assignYouTubePlaylist,
    googleKeys,
    listYouTubeCategories,
    listYouTubePlaylists,
} from "@/lib/studio/api";
import { Button } from "@/components/ui/button";
import { useGoogleIntegrations } from "@/components/studio/youtube-selectors";
import {
    playlistState,
    resolvePlaylistDestination,
    resolveTitle,
} from "@/lib/studio/youtube-catalog";
import type { ContentProject } from "@/lib/types/studio";

/**
 * Where this video is about to go, resolved to human-readable names.
 *
 * Shown before the upload button because that is the last moment a wrong
 * destination is cheap to fix. Ids appear only as a fallback when the catalog
 * cannot resolve them — the name is the primary fact.
 *
 * Also surfaces a failed playlist assignment. That failure is deliberately not
 * allowed to fail the upload (the video exists; re-uploading would duplicate
 * it), which used to mean nobody ever found out. Retry here calls
 * playlistItems.insert only.
 */
export function YouTubeDestinationSummary({
    project,
    onChanged,
}: {
    project: ContentProject;
    onChanged: () => void;
}) {
    const { data: integrations } = useGoogleIntegrations();
    const connected = integrations?.youtube.connected ?? false;
    const canManagePlaylists = integrations?.youtube.capabilities?.manage_playlists ?? false;
    const [retrying, setRetrying] = useState(false);

    const swr = { revalidateOnFocus: false, revalidateIfStale: false, shouldRetryOnError: false };

    const { data: playlistPage } = useSWR(
        connected ? googleKeys.playlists : null,
        () => listYouTubePlaylists(),
        swr,
    );
    const { data: categories } = useSWR(
        connected ? googleKeys.categories : null,
        listYouTubeCategories,
        swr,
    );

    const metadata = project.youtube.metadata;

    const { playlistId, inheritedFromTopic } = resolvePlaylistDestination(
        metadata?.playlist_id,
        project.topic?.youtube_playlist_id,
    );

    const playlistName = resolveTitle(playlistPage?.data, playlistId, "None");
    const categoryName = resolveTitle(categories, metadata?.category_id, "Not set");

    const playlistError = project.youtube_playlist?.error ?? null;
    const state = playlistState({
        itemId: project.youtube_playlist?.item_id,
        error: playlistError,
        canManagePlaylists,
    });

    async function retryPlaylist() {
        setRetrying(true);
        try {
            await assignYouTubePlaylist(project.id);
            toast.success("Added to the playlist.");
            onChanged();
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not add the video to the playlist."));
        } finally {
            setRetrying(false);
        }
    }

    return (
        <div className="rounded-md border px-3 py-2 text-xs">
            <dl className="grid grid-cols-[6rem_1fr] gap-y-1">
                <dt className="text-muted-foreground">Playlist</dt>
                <dd>
                    {playlistName}
                    {inheritedFromTopic && playlistId && (
                        <span className="text-muted-foreground"> · from topic</span>
                    )}
                    {state === "assigned" && (
                        <span className="text-emerald-600 dark:text-emerald-400"> · added</span>
                    )}
                </dd>

                <dt className="text-muted-foreground">Category</dt>
                <dd>{categoryName}</dd>

                <dt className="text-muted-foreground">Privacy</dt>
                <dd className="capitalize">{metadata?.privacy_status ?? "private"}</dd>
            </dl>

            {playlistError && (
                <div className="mt-2 rounded-md bg-amber-500/10 px-2 py-1.5 text-amber-700 dark:text-amber-400">
                    <p className="font-medium">Playlist assignment failed</p>
                    <p>{playlistError}</p>
                    {state === "failed_can_retry" ? (
                        <Button
                            size="sm"
                            variant="outline"
                            className="mt-1.5"
                            disabled={retrying}
                            onClick={() => void retryPlaylist()}
                        >
                            {retrying ? "Adding…" : "Retry playlist assignment"}
                        </Button>
                    ) : (
                        <p className="mt-1">
                            Reconnect YouTube from Settings → Integrations to grant playlist
                            management, then retry.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
