"use client";

import Link from "next/link";
import useSWR from "swr";
import { Button } from "@/components/ui/button";
import { ProjectStatusBadge } from "@/components/studio/status-badge";
import { listProjects, studioKeys } from "@/lib/studio/api";
import { formatDateTime, formatDuration } from "@/lib/studio/format";

export default function StudioListClient() {
    const { data: projects, isLoading } = useSWR(studioKeys.projects, listProjects, {
        // Keep statuses fresh while renders are running elsewhere.
        refreshInterval: 15000,
    });

    return (
        <section className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-10">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-3xl font-semibold tracking-tight">Content Studio</h1>
                    <p className="text-muted-foreground">
                        Lecture recordings and artwork in, YouTube-ready video out.
                    </p>
                </div>
                <Button asChild>
                    <Link href="/studio/new">New Content</Link>
                </Button>
            </div>

            {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

            {!isLoading && projects && projects.length === 0 && (
                <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-10">
                    <h2 className="text-lg font-medium">No content yet</h2>
                    <p className="text-sm text-muted-foreground">
                        Create your first project to upload a lecture recording, add the Kajian
                        Tematik title information and render a video.
                    </p>
                    <Button asChild size="sm">
                        <Link href="/studio/new">New Content</Link>
                    </Button>
                </div>
            )}

            {projects && projects.length > 0 && (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[64rem] text-sm">
                        <thead className="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">Working title</th>
                                <th className="px-4 py-3 font-medium">Topic</th>
                                <th className="px-4 py-3 font-medium">Tema</th>
                                <th className="px-4 py-3 font-medium">Speaker</th>
                                <th className="px-4 py-3 font-medium">Audio</th>
                                <th className="px-4 py-3 font-medium">Render</th>
                                <th className="px-4 py-3 font-medium">Drive</th>
                                <th className="px-4 py-3 font-medium">YouTube</th>
                                <th className="px-4 py-3 font-medium">Updated</th>
                                <th className="px-4 py-3 font-medium sr-only">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {projects.map((project) => (
                                <tr key={project.id} className="hover:bg-muted/30">
                                    <td className="px-4 py-3 font-medium">
                                        <Link
                                            href={`/studio/${project.id}`}
                                            className="hover:underline"
                                        >
                                            {project.working_title}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {project.topic?.name ?? "—"}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {project.topic_sequence != null
                                            ? `#${project.topic_sequence}`
                                            : "—"}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {project.speaker?.name ?? "—"}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                                        {formatDuration(project.audio_duration)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <ProjectStatusBadge
                                            pipeline="render"
                                            status={project.render.status}
                                            label={
                                                project.render.status === "rendering"
                                                    ? `${project.render.progress}%`
                                                    // The file exists and is a
                                                    // real render — of an
                                                    // earlier revision. Saying
                                                    // "Rendered" would claim it
                                                    // still matches.
                                                    : project.render.stale
                                                      ? "Outdated"
                                                      : project.render.label
                                            }
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <ProjectStatusBadge
                                            pipeline="drive"
                                            status={project.drive.status}
                                            label={project.drive.label}
                                        />
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-col gap-1">
                                            <ProjectStatusBadge
                                                pipeline="youtube"
                                                status={project.youtube.status}
                                                // What YouTube says now wins:
                                                // a scheduled video publishes
                                                // itself, and the pipeline
                                                // value was frozen at upload.
                                                label={
                                                    project.youtube.remote_label
                                                    ?? project.youtube.label
                                                }
                                            />
                                            {project.youtube.scheduled_at && (
                                                <span className="text-[11px] text-muted-foreground">
                                                    {formatDateTime(project.youtube.scheduled_at)}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-xs text-muted-foreground">
                                        {formatDateTime(project.updated_at)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Button asChild size="sm" variant="ghost">
                                            <Link href={`/studio/${project.id}`}>Open</Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
