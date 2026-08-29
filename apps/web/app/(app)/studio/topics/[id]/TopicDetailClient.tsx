"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { ProjectStatusBadge } from "@/components/studio/status-badge";
import { apiErrorMessage, getTopic, studioKeys, updateTopic } from "@/lib/studio/api";
import { formatDuration } from "@/lib/studio/format";

export default function TopicDetailClient({ topicId }: { topicId: string }) {
    const { data: topic, isLoading, mutate } = useSWR(studioKeys.topic(topicId), () =>
        getTopic(topicId),
    );

    const [playlistId, setPlaylistId] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setPlaylistId(topic?.youtube_playlist_id ?? "");
    }, [topic]);

    async function onSavePlaylist() {
        setSaving(true);
        try {
            await updateTopic(topicId, { youtube_playlist_id: playlistId.trim() || null });
            await mutate();
            toast.success("Playlist link saved.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not save the playlist link."));
        } finally {
            setSaving(false);
        }
    }

    if (isLoading || !topic) {
        return (
            <div className="mx-auto w-full max-w-4xl px-4 py-10 text-sm text-muted-foreground">
                Loading…
            </div>
        );
    }

    return (
        <section className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-10">
            <div className="flex flex-col gap-1">
                <Link
                    href="/studio/topics"
                    className="text-xs text-muted-foreground hover:underline"
                >
                    ← Topics
                </Link>
                <h1 className="text-3xl font-semibold tracking-tight">{topic.name}</h1>
                <p className="text-muted-foreground">
                    Next suggested sequence:{" "}
                    <span className="font-mono">TEMA #{topic.next_sequence ?? 1}</span>
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>YouTube playlist</CardTitle>
                    <CardDescription>
                        Uploads from this topic can be added to the linked playlist. Optional —
                        rendering never depends on it.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-3">
                    <Label htmlFor="playlist">Playlist ID</Label>
                    <div className="flex gap-2">
                        <Input
                            id="playlist"
                            placeholder="PLxxxxxxxxxxxx"
                            value={playlistId}
                            onChange={(event) => setPlaylistId(event.target.value)}
                        />
                        <Button onClick={() => void onSavePlaylist()} disabled={saving}>
                            {saving ? "Saving…" : "Save"}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Videos in this topic</CardTitle>
                    <CardDescription>Ordered by sequence.</CardDescription>
                </CardHeader>
                <CardContent>
                    {topic.projects && topic.projects.length > 0 ? (
                        <ul className="divide-y">
                            {topic.projects.map((project) => (
                                <li
                                    key={project.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="flex items-center gap-3">
                                        <span className="w-20 font-mono text-xs text-muted-foreground">
                                            {project.topic_sequence != null
                                                ? `TEMA #${project.topic_sequence}`
                                                : "—"}
                                        </span>
                                        <Link
                                            href={`/studio/${project.id}`}
                                            className="font-medium hover:underline"
                                        >
                                            {project.working_title}
                                        </Link>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {formatDuration(project.audio_duration)}
                                        </span>
                                        <ProjectStatusBadge
                                            pipeline="render"
                                            status={project.render.status}
                                            label={project.render.label}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No videos in this topic yet.
                        </p>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}
