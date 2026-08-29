"use client";

import { useState } from "react";
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
import { apiErrorMessage, createTopic, listTopics, studioKeys } from "@/lib/studio/api";

export default function TopicsClient() {
    const { data: topics, isLoading, mutate } = useSWR(studioKeys.topics, listTopics);
    const [name, setName] = useState("");
    const [playlistId, setPlaylistId] = useState("");
    const [saving, setSaving] = useState(false);

    async function onCreate(event: React.FormEvent) {
        event.preventDefault();
        if (!name.trim()) return;

        setSaving(true);
        try {
            await createTopic({
                name: name.trim(),
                youtube_playlist_id: playlistId.trim() || null,
            });
            setName("");
            setPlaylistId("");
            await mutate();
            toast.success("Topic created.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not create the topic."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <section className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-10">
            <div className="flex flex-col gap-1">
                <h1 className="text-3xl font-semibold tracking-tight">Topics</h1>
                <p className="text-muted-foreground">
                    Lecture series. Each topic is the top-left line of the video, and maps to a
                    YouTube playlist.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>New topic</CardTitle>
                    <CardDescription>
                        The playlist link is optional and never required to render.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={onCreate} className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                placeholder="Riyadhush Shalihin"
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                            />
                        </div>
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="playlist">YouTube playlist ID</Label>
                            <Input
                                id="playlist"
                                placeholder="PLxxxxxxxxxxxx"
                                value={playlistId}
                                onChange={(event) => setPlaylistId(event.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={saving} className="self-start">
                            {saving ? "Saving…" : "Add topic"}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

            <div className="flex flex-col gap-3">
                {topics?.map((topic) => (
                    <Card key={topic.id}>
                        <CardHeader className="flex flex-row items-start justify-between gap-4">
                            <div className="flex flex-col gap-1">
                                <CardTitle className="text-lg">
                                    <Link
                                        href={`/studio/topics/${topic.id}`}
                                        className="hover:underline"
                                    >
                                        {topic.name}
                                    </Link>
                                </CardTitle>
                                <CardDescription>
                                    {topic.projects_count ?? 0} video
                                    {topic.projects_count === 1 ? "" : "s"}
                                    {topic.youtube_playlist_id
                                        ? ` · playlist ${topic.youtube_playlist_id}`
                                        : " · no playlist linked"}
                                </CardDescription>
                            </div>
                            <Button asChild size="sm" variant="ghost">
                                <Link href={`/studio/topics/${topic.id}`}>Open</Link>
                            </Button>
                        </CardHeader>
                    </Card>
                ))}
                {!isLoading && topics?.length === 0 && (
                    <p className="text-sm text-muted-foreground">No topics yet.</p>
                )}
            </div>
        </section>
    );
}
