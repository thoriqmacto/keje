"use client";

import useSWR from "swr";
import { toast } from "sonner";
import { Label } from "@/components/ui/label";
import { useGoogleIntegrations } from "@/components/studio/youtube-selectors";
import {
    apiErrorMessage,
    googleKeys,
    listYouTubePlaylists,
    resolveTopicFromPlaylist,
} from "@/lib/studio/api";
import type { ContentTopic } from "@/lib/types/studio";

/**
 * The topic, chosen from the channel's own playlists.
 *
 * A local topic and a YouTube playlist were always the same grouping described
 * twice. Picking a playlist here resolves (or creates) its local shadow on the
 * server, so the project ends up attached to a real ContentTopic — which is
 * what the renderer draws and what historical projects already point at —
 * without anyone maintaining two lists.
 *
 * Falls back to the plain topic list when YouTube is disconnected: a project
 * must remain editable whether or not Google is reachable.
 */
export function PlaylistTopicSelector({
    value,
    onChange,
    topics,
    disabled = false,
}: {
    /** The currently attached topic, if any. */
    value: ContentTopic | null;
    /** Called with the resolved topic's UUID. */
    onChange: (topicId: string | null, topic: ContentTopic | null) => void;
    /** Local topics, for the disconnected fallback and legacy entries. */
    topics: ContentTopic[] | undefined;
    disabled?: boolean;
}) {
    const { data: integrations } = useGoogleIntegrations();
    const connected = integrations?.youtube.connected ?? false;

    const { data: page, isLoading } = useSWR(
        connected ? googleKeys.playlists : null,
        () => listYouTubePlaylists(),
        { revalidateOnFocus: false, revalidateIfStale: false, shouldRetryOnError: false },
    );

    const playlists = page?.data ?? [];

    // Topics that predate playlists, or whose playlist is gone. Kept visible
    // so a historical project can still be re-attached to its own topic.
    const legacy = (topics ?? []).filter(
        (topic) =>
            !topic.youtube_playlist_id
            || !playlists.some((p) => p.id === topic.youtube_playlist_id),
    );

    async function onPick(playlistId: string, title: string | null) {
        try {
            const topic = await resolveTopicFromPlaylist(playlistId, title);
            onChange(topic.id, topic);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not use that playlist as the topic."));
        }
    }

    const selectedValue = value?.youtube_playlist_id
        ? `playlist:${value.youtube_playlist_id}`
        : value
          ? `topic:${value.id}`
          : "";

    return (
        <div className="flex flex-col gap-1.5">
            <Label htmlFor="topic">Topic</Label>

            <select
                id="topic"
                className="h-9 rounded-md border bg-background px-3 text-sm"
                value={selectedValue}
                disabled={disabled || isLoading}
                onChange={(event) => {
                    const raw = event.target.value;

                    if (raw === "") {
                        onChange(null, null);
                        return;
                    }

                    if (raw.startsWith("playlist:")) {
                        const id = raw.slice("playlist:".length);
                        const playlist = playlists.find((p) => p.id === id);
                        void onPick(id, playlist?.title ?? null);
                        return;
                    }

                    const topic = (topics ?? []).find((t) => t.id === raw.slice("topic:".length));
                    onChange(topic?.id ?? null, topic ?? null);
                }}
            >
                <option value="">No topic</option>

                {connected && playlists.length > 0 && (
                    <optgroup label="YouTube playlists">
                        {playlists.map((playlist) => (
                            <option key={playlist.id} value={`playlist:${playlist.id}`}>
                                {playlist.title} · {playlist.item_count} videos
                            </option>
                        ))}
                    </optgroup>
                )}

                {legacy.length > 0 && (
                    <optgroup label={connected ? "Not linked to YouTube" : "Topics"}>
                        {legacy.map((topic) => (
                            <option key={topic.id} value={`topic:${topic.id}`}>
                                {topic.name}
                            </option>
                        ))}
                    </optgroup>
                )}
            </select>

            {connected ? (
                <p className="text-xs text-muted-foreground">
                    Choosing a playlist makes it the topic and the upload destination.
                </p>
            ) : (
                <p className="text-xs text-muted-foreground">
                    Connect YouTube to pick a playlist as the topic.
                </p>
            )}
        </div>
    );
}
