"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { SpeakerSelector, TopicSelector } from "@/components/studio/selectors";
import { apiErrorMessage, updateProject } from "@/lib/studio/api";
import type { ContentProject } from "@/lib/types/studio";

/**
 * The grouping a project belongs to, editable for its whole life.
 *
 * These were only settable at creation, which meant a project created without
 * a speaker — the easy mistake, since the field is optional — could never
 * acquire one. That, not a broken lookup, is why the Studio list showed "—".
 *
 * The same selectors as New Content, not copies of them: two implementations
 * of "choose a topic" drift, and the one nobody is looking at drifts first.
 */
export function ProjectPropertiesCard({
    project,
    onSaved,
}: {
    project: ContentProject;
    onSaved: () => void;
}) {
    const [workingTitle, setWorkingTitle] = useState(project.working_title);
    const [topicId, setTopicId] = useState<string | null>(project.topic?.id ?? null);
    const [sequence, setSequence] = useState(
        project.topic_sequence == null ? "" : String(project.topic_sequence),
    );
    const [speakerId, setSpeakerId] = useState<string | null>(project.speaker?.id ?? null);
    const [saving, setSaving] = useState(false);

    const dirty =
        workingTitle !== project.working_title
        || topicId !== (project.topic?.id ?? null)
        || speakerId !== (project.speaker?.id ?? null)
        || sequence !== (project.topic_sequence == null ? "" : String(project.topic_sequence));

    async function onSave() {
        if (!workingTitle.trim()) {
            toast.error("Give the project a working title.");
            return;
        }

        setSaving(true);
        try {
            await updateProject(project.id, {
                working_title: workingTitle.trim(),
                topic_id: topicId,
                topic_sequence: sequence ? Number(sequence) : null,
                speaker_id: speakerId,
            });
            await onSaved();
            toast.success("Project properties saved.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not save the project properties."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
                <Label htmlFor="working_title">Working title</Label>
                <Input
                    id="working_title"
                    value={workingTitle}
                    onChange={(event) => setWorkingTitle(event.target.value)}
                />
                {/* Never drawn on the frame, so changing it cannot invalidate
                    a finished render — worth saying, because everything else
                    on this card can. */}
                <p className="text-xs text-muted-foreground">
                    Your label for this project. It is never drawn on the video.
                </p>
            </div>

            <TopicSelector value={topicId} onChange={setTopicId} />

            <div className="flex flex-col gap-1.5">
                <Label htmlFor="topic_sequence">TEMA</Label>
                <Input
                    id="topic_sequence"
                    type="number"
                    min={1}
                    max={9999}
                    inputMode="numeric"
                    placeholder="11"
                    value={sequence}
                    onChange={(event) => setSequence(event.target.value)}
                />
            </div>

            <SpeakerSelector value={speakerId} onChange={setSpeakerId} />

            <Button className="self-start" disabled={saving || !dirty} onClick={() => void onSave()}>
                {saving ? "Saving…" : "Save project properties"}
            </Button>
        </div>
    );
}
