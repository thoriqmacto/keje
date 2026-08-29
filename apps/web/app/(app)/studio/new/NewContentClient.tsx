"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { SpeakerSelector, TopicSelector } from "@/components/studio/selectors";
import { apiErrorMessage, createProject } from "@/lib/studio/api";
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
            });
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

                        <TopicSelector
                            value={topicId}
                            onChange={setTopicId}
                            onTopicLoaded={onTopicLoaded}
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
