"use client";

import useSWR from "swr";
import { listYouTubePublications, studioKeys } from "@/lib/studio/api";
import { formatDateTime } from "@/lib/studio/format";
import type { ContentProject } from "@/lib/types/studio";

/**
 * Every video this project has had on YouTube.
 *
 * Worth a section of its own because replacing a video changes its public URL.
 * Someone who shared the old link needs to be able to see that it was replaced
 * and by what — and a superseded row is the only place that old id survives,
 * since the video it names may no longer exist to be looked up.
 *
 * Hidden entirely for a project that has only ever had one video: a list of
 * one is not history, it is the thing above it repeated.
 */
export function YouTubeHistory({ project }: { project: ContentProject }) {
    const { data: publications } = useSWR(
        project.youtube.video_id ? studioKeys.publications(project.id) : null,
        () => listYouTubePublications(project.id),
        { revalidateOnFocus: false },
    );

    if (!publications || publications.length < 2) {
        return null;
    }

    const current = publications.filter((p) => p.is_current);
    const previous = publications.filter((p) => !p.is_current);

    return (
        <div className="flex flex-col gap-3 border-t pt-4">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                YouTube history
            </p>

            {current.map((publication) => (
                <div key={publication.id} className="flex flex-col gap-0.5 text-xs">
                    <span className="font-medium">Current</span>
                    <span className="font-mono">{publication.video_id}</span>
                    {publication.title && <span>{publication.title}</span>}
                    <span className="text-muted-foreground">
                        Published {formatDateTime(publication.became_current_at)}
                    </span>
                    {publication.url && (
                        <a
                            href={publication.url}
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            Open on YouTube
                        </a>
                    )}
                </div>
            ))}

            {previous.map((publication) => (
                <div
                    key={publication.id}
                    className="flex flex-col gap-0.5 text-xs text-muted-foreground"
                >
                    <span className="font-medium">Previous</span>
                    <span className="font-mono">{publication.video_id}</span>
                    {publication.title && <span>{publication.title}</span>}
                    <span>Replaced {formatDateTime(publication.replaced_at)}</span>
                    {/* The distinction the history exists to record: whether
                        the old link still goes anywhere. */}
                    <span>
                        {publication.exists_on_youtube
                            ? "Still on YouTube, set to private"
                            : "Deleted from YouTube"}
                    </span>
                </div>
            ))}
        </div>
    );
}
