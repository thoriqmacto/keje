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
    YouTubeLanguageSelector,
    YouTubePlaylistSelector,
    useGoogleIntegrations,
} from "@/components/studio/youtube-selectors";
import {
    EMPTY_TITLES,
    YOUTUBE_TITLE_LIMIT,
    remaining,
    setCustom,
    setSynced,
    setWorking,
    youtubeTitle,
    youtubeTitleForMetadata,
    type TitleState,
} from "@/lib/studio/title-sync";
import type { PrivacyStatus } from "@/lib/types/studio";
import type { ContentTopic } from "@/lib/types/studio";

/**
 * Everything a project can be told before it has any media.
 *
 * This used to be step one of six, and it collected the grouping and little
 * else — so the title had to be typed again on the project page, the language
 * was nowhere, and the speaker sat alone in a card of its own. Every one of
 * those was a second visit to a decision already made.
 *
 * It is one form now. What still cannot live here is the media: an upload is
 * attached to a project, so the project has to exist before a 500 MB file can
 * be handed to it — which is also why creating early is right rather than a
 * compromise. Uploading is the only thing left on the project page for a new
 * project, and it is the one thing that could not have been decided in advance.
 */
export default function NewContentClient() {
    const router = useRouter();

    const [titles, setTitles] = useState(EMPTY_TITLES);
    const [topicId, setTopicId] = useState<string | null>(null);
    const [sequence, setSequence] = useState<string>("");
    const [speakerId, setSpeakerId] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // YouTube publishing defaults, chosen here so the destination is decided
    // with the rest of the grouping rather than remembered later.
    const [playlistId, setPlaylistId] = useState<string | null>(null);
    const [categoryId, setCategoryId] = useState<string | null>(null);
    const [language, setLanguage] = useState<string | null>(null);
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
        if (!titles.working.trim()) {
            toast.error("Give the project a title.");
            return;
        }

        setSaving(true);
        try {
            const publicTitle = youtubeTitleForMetadata(titles);

            const metadata = {
                // Stored whether or not Google is connected: the title is a
                // decision about the video rather than about the integration,
                // and connecting an account later should not mean typing it
                // again.
                ...(publicTitle ? { title: publicTitle } : {}),
                ...(youtubeConnected
                    ? {
                          ...(playlistId ? { playlist_id: playlistId } : {}),
                          ...(categoryId ? { category_id: categoryId } : {}),
                          ...(language ? { default_language: language } : {}),
                          privacy_status: privacy,
                      }
                    : {}),
            };

            const project = await createProject({
                working_title: titles.working.trim(),
                topic_id: topicId,
                topic_sequence: sequence ? Number(sequence) : null,
                speaker_id: speakerId,
                // Nothing chosen means nothing sent, so the column stays
                // null rather than holding an empty object that reads as
                // "someone considered this and left it blank".
                youtube_metadata: Object.keys(metadata).length > 0 ? metadata : undefined,
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
            toast.success("Project created. Add the recording and artwork to render it.");
            router.push(`/studio/${project.id}`);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not create the project."));
            setSaving(false);
        }
    }

    return (
        <section className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-8">
            <div className="flex flex-col gap-1">
                <h1 className="text-2xl font-semibold tracking-tight">New content</h1>
                <p className="text-sm text-muted-foreground">
                    Everything the project can be told up front. Only the recording and artwork
                    are left for the project page.
                </p>
            </div>

            <form onSubmit={onSubmit} className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Content</CardTitle>
                        <CardDescription>
                            The topic becomes the top-left line of the video, and later maps to a
                            YouTube playlist. The speaker is reused across projects — type it once.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <TitleFields state={titles} onChange={setTitles} />

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

                        <div className="grid gap-4 sm:grid-cols-2">
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

                            {/* Merged in from a card of its own. A single
                                selector never warranted its own heading, and
                                the speaker is part of what groups a video
                                exactly as much as the topic is. */}
                            <SpeakerSelector value={speakerId} onChange={setSpeakerId} />
                        </div>
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

                                {/* Language sits with category and privacy
                                    because it is the same kind of decision:
                                    a per-video setting YouTube wants at upload
                                    time, and one nobody wants to come back for. */}
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <YouTubeCategorySelector
                                        value={categoryId}
                                        onChange={setCategoryId}
                                    />

                                    <YouTubeLanguageSelector
                                        value={language}
                                        onChange={setLanguage}
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
                        {saving ? "Creating…" : "Create project"}
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => router.push("/studio")}>
                        Cancel
                    </Button>
                </div>
            </form>
        </section>
    );
}

/**
 * One title, or two when they need to differ.
 *
 * The checkbox is on by default because for most videos the internal name and
 * the public one are the same sentence, and typing it twice is a tax paid on
 * every project. Unticking reveals the second field already filled in, so
 * changing one word of a title does not mean retyping it.
 *
 * Both boxes stop at YouTube's 100 characters — the working title obeys the
 * stricter rule too, because while the box is ticked it *is* the YouTube
 * title, and letting it run longer would make the checkbox quietly untrue.
 */
function TitleFields({
    state,
    onChange,
}: {
    state: TitleState;
    onChange: (next: TitleState) => void;
}) {
    const publicTitle = youtubeTitle(state);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-col gap-2">
                <div className="flex items-baseline justify-between gap-2">
                    <Label htmlFor="working_title">Title</Label>
                    <CharacterCount value={state.working} />
                </div>
                <Input
                    id="working_title"
                    placeholder="Kajian Tematik #11 — Part 3"
                    value={state.working}
                    maxLength={YOUTUBE_TITLE_LIMIT}
                    onChange={(event) => onChange(setWorking(state, event.target.value))}
                />
                <p className="text-xs text-muted-foreground">
                    {state.synced
                        ? "Used in the Studio and as the YouTube title."
                        : "Used in the Studio only. Not shown in the video."}
                </p>
            </div>

            <label className="flex items-start gap-2 text-sm">
                <input
                    type="checkbox"
                    className="mt-1"
                    checked={state.synced}
                    onChange={(event) => onChange(setSynced(state, event.target.checked))}
                />
                <span className="text-muted-foreground">
                    Use this as the YouTube title too. Untick to publish under a different name.
                </span>
            </label>

            {!state.synced && (
                <div className="flex flex-col gap-2">
                    <div className="flex items-baseline justify-between gap-2">
                        <Label htmlFor="youtube_title">YouTube title</Label>
                        <CharacterCount value={state.custom} />
                    </div>
                    <Input
                        id="youtube_title"
                        placeholder={publicTitle || "Keutamaan Lapar | Kajian Tematik"}
                        value={state.custom}
                        maxLength={YOUTUBE_TITLE_LIMIT}
                        onChange={(event) => onChange(setCustom(state, event.target.value))}
                    />
                    <p className="text-xs text-muted-foreground">
                        What the video is called on YouTube.
                    </p>
                </div>
            )}
        </div>
    );
}

/**
 * How much room is left.
 *
 * Quiet until it matters: a counter reading 87 on every field is noise, but
 * one that has already turned amber when you reach the end of the sentence
 * is the difference between editing now and editing after a rejection.
 */
function CharacterCount({ value }: { value: string }) {
    const left = remaining(value);

    if (left > 20) {
        return null;
    }

    return (
        <span
            className={`text-xs tabular-nums ${
                left === 0 ? "text-amber-700 dark:text-amber-400" : "text-muted-foreground"
            }`}
        >
            {left} left
        </span>
    );
}
