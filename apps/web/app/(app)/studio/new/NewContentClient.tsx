"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { SpeakerSelector } from "@/components/studio/selectors";
import { PlaylistTopicSelector } from "@/components/studio/playlist-topic-selector";
import {
    apiErrorMessage,
    createProject,
    listTopics,
    studioKeys,
    updateTopic,
} from "@/lib/studio/api";
import useSWR from "swr";
import {
    YouTubeCategorySelector,
    YouTubeChannelSummary,
    YouTubePlaylistSelector,
    useGoogleIntegrations,
} from "@/components/studio/youtube-selectors";
import type { PrivacyStatus } from "@/lib/types/studio";
import type { ContentTopic } from "@/lib/types/studio";

/**
 * Step 1 and 2 of the workflow: grouping and speaker.
 *
 * Creating the project early — before any media — is deliberate: uploads are
 * attached to a project, and a half-filled form should not hold a 500 MB file
 * hostage. The remaining steps happen on the project page.
 */
export default function NewContentClient() {
    const router = useRouter();

    const [workingTitle, setWorkingTitle] = useState("");
    const [topicId, setTopicId] = useState<string | null>(null);
    const [sequence, setSequence] = useState<string>("");
    const [speakerId, setSpeakerId] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // YouTube publishing defaults, chosen here so the destination is decided
    // with the rest of the grouping rather than remembered later.
    const [playlistId, setPlaylistId] = useState<string | null>(null);
    const [categoryId, setCategoryId] = useState<string | null>(null);
    const [privacy, setPrivacy] = useState<PrivacyStatus>("private");
    const [linkPlaylistToTopic, setLinkPlaylistToTopic] = useState(false);

    const { data: integrations } = useGoogleIntegrations();
    const youtubeConnected = integrations?.youtube.connected ?? false;

    const { data: topics } = useSWR(studioKeys.topics, listTopics, { revalidateOnFocus: false });
    // A topic just resolved from a playlist is not in the cached list yet,
    // so it wins over the lookup until the list revalidates.
    const [resolvedTopic, setResolvedTopic] = useState<ContentTopic | null>(null);
    const selectedTopic =
        resolvedTopic?.id === topicId
            ? resolvedTopic
            : (topics?.find((topic) => topic.id === topicId) ?? null);
    // A topic that already points at a playlist supplies the default, so the
    // usual case needs no choice at all.
    const topicPlaylistId = selectedTopic?.youtube_playlist_id ?? null;

    /** Suggest the next free number, but let the user override it. */
    function onTopicLoaded(topic: ContentTopic | null) {
        if (topic?.next_sequence != null) {
            setSequence(String(topic.next_sequence));
        }
    }

    async function onSubmit(event: React.FormEvent) {
        event.preventDefault();
        if (!workingTitle.trim()) {
            toast.error("Give the project a working title.");
            return;
        }

        setSaving(true);
        try {
            const project = await createProject({
                working_title: workingTitle.trim(),
                topic_id: topicId,
                topic_sequence: sequence ? Number(sequence) : null,
                speaker_id: speakerId,
                // Only send what was actually chosen: an empty metadata block
                // would overwrite nothing, but sending nulls reads as intent.
                youtube_metadata: youtubeConnected
                    ? {
                          ...(playlistId ? { playlist_id: playlistId } : {}),
                          ...(categoryId ? { category_id: categoryId } : {}),
                          privacy_status: privacy,
                      }
                    : undefined,
            });

            // Opt-in, and only ever widening: choosing a playlist for one video
            // is not by itself a decision about the whole topic.
            if (linkPlaylistToTopic && topicId && playlistId) {
                try {
                    await updateTopic(topicId, { youtube_playlist_id: playlistId });
                } catch {
                    toast.error("Project created, but the topic's playlist could not be updated.");
                }
            }
            toast.success("Project created. Now add the media and title information.");
            router.push(`/studio/${project.id}`);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not create the project."));
            setSaving(false);
        }
    }

    return (
        <section className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-10">
            <div className="flex flex-col gap-1">
                <span className="text-xs uppercase tracking-widest text-muted-foreground">
                    Step 1 of 6
                </span>
                <h1 className="text-3xl font-semibold tracking-tight">New content</h1>
                <p className="text-muted-foreground">
                    Group the video, then upload media and enter the title information.
                </p>
            </div>

            <form onSubmit={onSubmit} className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Content grouping</CardTitle>
                        <CardDescription>
                            The topic becomes the top-left line of the video, and later maps to a
                            YouTube playlist.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="working_title">Working title</Label>
                            <Input
                                id="working_title"
                                placeholder="Kajian Tematik #11 — Part 3"
                                value={workingTitle}
                                onChange={(event) => setWorkingTitle(event.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                For your own reference in the studio. Not shown in the video.
                            </p>
                        </div>

                        <PlaylistTopicSelector
                            value={selectedTopic ?? null}
                            topics={topics}
                            onChange={(id, topic) => {
                                setTopicId(id);
                                setResolvedTopic(topic);
                                // A playlist that is already a topic knows
                                // where its numbering got to.
                                if (topic) onTopicLoaded(topic);
                            }}
                        />

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="sequence">Topic sequence</Label>
                            <Input
                                id="sequence"
                                type="number"
                                min={1}
                                placeholder="11"
                                value={sequence}
                                onChange={(event) => setSequence(event.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                Renders as{" "}
                                <span className="font-mono">TEMA #{sequence || "N"}</span>.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Speaker</CardTitle>
                        <CardDescription>Reused across projects — type it once.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SpeakerSelector value={speakerId} onChange={setSpeakerId} />
                    </CardContent>
                </Card>

                {/* Optional throughout: rendering never depends on YouTube,
                    so a project can be created and rendered before the account
                    is connected at all. */}
                <Card>
                    <CardHeader>
                        <CardTitle>YouTube publishing defaults</CardTitle>
                        <CardDescription>
                            Optional. These can be changed any time before uploading.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <YouTubeChannelSummary />

                        {youtubeConnected && (
                            <>
                                <YouTubePlaylistSelector
                                    value={playlistId}
                                    inheritedFrom={topicPlaylistId}
                                    onChange={(id) => {
                                        setPlaylistId(id);
                                        if (!id) setLinkPlaylistToTopic(false);
                                    }}
                                />

                                {topicId && playlistId && playlistId !== topicPlaylistId && (
                                    <label className="flex items-start gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            className="mt-1"
                                            checked={linkPlaylistToTopic}
                                            onChange={(event) =>
                                                setLinkPlaylistToTopic(event.target.checked)
                                            }
                                        />
                                        <span className="text-muted-foreground">
                                            Also make this the default playlist for{" "}
                                            <span className="font-medium text-foreground">
                                                {selectedTopic?.name}
                                            </span>
                                            . Leave unchecked to apply it to this video only.
                                        </span>
                                    </label>
                                )}

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <YouTubeCategorySelector
                                        value={categoryId}
                                        onChange={setCategoryId}
                                    />

                                    <div className="flex flex-col gap-1.5">
                                        <Label htmlFor="yt_privacy_default">Privacy</Label>
                                        <select
                                            id="yt_privacy_default"
                                            className="h-9 rounded-md border bg-background px-3 text-sm"
                                            value={privacy}
                                            onChange={(event) =>
                                                setPrivacy(event.target.value as PrivacyStatus)
                                            }
                                        >
                                            <option value="private">Private</option>
                                            <option value="unlisted">Unlisted</option>
                                            <option value="public">Public</option>
                                        </select>
                                    </div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>

                <div className="flex gap-3">
                    <Button type="submit" disabled={saving}>
                        {saving ? "Creating…" : "Create and continue"}
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => router.push("/studio")}>
                        Cancel
                    </Button>
                </div>
            </form>
        </section>
    );
}
