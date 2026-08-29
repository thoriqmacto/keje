"use client";

import { useState } from "react";
import useSWR from "swr";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
    apiErrorMessage,
    createSpeaker,
    createTopic,
    listSpeakers,
    listTopics,
    studioKeys,
} from "@/lib/studio/api";
import type { ContentTopic, Speaker } from "@/lib/types/studio";

/**
 * "Select existing ▼ / + New" pickers for the two reusable entities.
 *
 * Creating inline matters: the whole point of Topics and Speakers is that the
 * user types "Riyadhush Shalihin" once, and being bounced to another page to
 * do it would defeat that.
 */

export function TopicSelector({
    value,
    onChange,
    onTopicLoaded,
}: {
    value: string | null;
    onChange: (topicId: string | null) => void;
    /** Fires when the selected topic's record is available, for sequence suggestions. */
    onTopicLoaded?: (topic: ContentTopic | null) => void;
}) {
    const { data: topics, mutate } = useSWR(studioKeys.topics, listTopics);
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState("");
    const [saving, setSaving] = useState(false);

    async function onCreate() {
        if (!name.trim()) return;
        setSaving(true);
        try {
            const topic = await createTopic({ name: name.trim() });
            await mutate();
            onChange(topic.id);
            onTopicLoaded?.(topic);
            setName("");
            setCreating(false);
            toast.success(`Topic “${topic.name}” created.`);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not create the topic."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor="topic">Topic / Playlist</Label>
            {!creating ? (
                <div className="flex gap-2">
                    <select
                        id="topic"
                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        value={value ?? ""}
                        onChange={(event) => {
                            const next = event.target.value || null;
                            onChange(next);
                            onTopicLoaded?.(topics?.find((t) => t.id === next) ?? null);
                        }}
                    >
                        <option value="">No topic</option>
                        {topics?.map((topic) => (
                            <option key={topic.id} value={topic.id}>
                                {topic.name}
                            </option>
                        ))}
                    </select>
                    <Button type="button" variant="outline" onClick={() => setCreating(true)}>
                        + New
                    </Button>
                </div>
            ) : (
                <div className="flex gap-2">
                    <Input
                        autoFocus
                        placeholder="Riyadhush Shalihin"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === "Enter") {
                                event.preventDefault();
                                void onCreate();
                            }
                        }}
                    />
                    <Button type="button" onClick={() => void onCreate()} disabled={saving}>
                        {saving ? "Saving…" : "Add"}
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                        Cancel
                    </Button>
                </div>
            )}
        </div>
    );
}

export function SpeakerSelector({
    value,
    onChange,
}: {
    value: string | null;
    onChange: (speakerId: string | null) => void;
}) {
    const { data: speakers, mutate } = useSWR(studioKeys.speakers, listSpeakers);
    const [creating, setCreating] = useState(false);
    const [name, setName] = useState("");
    const [saving, setSaving] = useState(false);

    async function onCreate() {
        if (!name.trim()) return;
        setSaving(true);
        try {
            const speaker: Speaker = await createSpeaker({ name: name.trim() });
            await mutate();
            onChange(speaker.id);
            setName("");
            setCreating(false);
            toast.success(`Speaker “${speaker.name}” created.`);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not create the speaker."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor="speaker">Speaker</Label>
            {!creating ? (
                <div className="flex gap-2">
                    <select
                        id="speaker"
                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        value={value ?? ""}
                        onChange={(event) => onChange(event.target.value || null)}
                    >
                        <option value="">No speaker</option>
                        {speakers?.map((speaker) => (
                            <option key={speaker.id} value={speaker.id}>
                                {speaker.name}
                            </option>
                        ))}
                    </select>
                    <Button type="button" variant="outline" onClick={() => setCreating(true)}>
                        + New
                    </Button>
                </div>
            ) : (
                <div className="flex gap-2">
                    <Input
                        autoFocus
                        placeholder="Syafiq Riza Basalamah"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === "Enter") {
                                event.preventDefault();
                                void onCreate();
                            }
                        }}
                    />
                    <Button type="button" onClick={() => void onCreate()} disabled={saving}>
                        {saving ? "Saving…" : "Add"}
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                        Cancel
                    </Button>
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                The Kajian Tematik template adds the <code className="font-mono">USTADZ</code> label
                automatically.
            </p>
        </div>
    );
}
