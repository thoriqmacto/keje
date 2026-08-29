"use client";

import Link from "next/link";
import useSWR from "swr";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { ProjectStatusBadge } from "@/components/studio/status-badge";
import { listProjects, studioKeys } from "@/lib/studio/api";
import { formatDateTime, formatDuration } from "@/lib/studio/format";
import type { ContentProjectSummary } from "@/lib/types/studio";

/** Counts the workflow stages worth acting on, not vanity analytics. */
function summarise(projects: ContentProjectSummary[]) {
    return {
        drafts: projects.filter((p) => ["draft", "media_ready"].includes(p.render.status)).length,
        rendering: projects.filter((p) => ["queued", "rendering"].includes(p.render.status)).length,
        readyToUpload: projects.filter(
            (p) => p.render.status === "rendered" && p.youtube.status === "pending",
        ).length,
        scheduled: projects.filter((p) => p.youtube.status === "scheduled").length,
        published: projects.filter((p) =>
            ["published", "uploaded"].includes(p.youtube.status),
        ).length,
    };
}

export default function DashboardPage() {
    const { data: projects, isLoading } = useSWR(studioKeys.projects, listProjects, {
        refreshInterval: 15000,
    });

    const counts = projects ? summarise(projects) : null;
    const recent = projects?.slice(0, 6) ?? [];

    const cards: [string, number, string][] = [
        ["Drafts", counts?.drafts ?? 0, "Not yet rendered"],
        ["Rendering", counts?.rendering ?? 0, "In the queue"],
        ["Ready to upload", counts?.readyToUpload ?? 0, "Rendered, not published"],
        ["Scheduled", counts?.scheduled ?? 0, "Awaiting publish time"],
        ["Published", counts?.published ?? 0, "Live on YouTube"],
    ];

    return (
        <section className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-10">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-3xl font-semibold tracking-tight">Dashboard</h1>
                    <p className="text-muted-foreground">
                        Lecture audio and artwork in, YouTube-ready video out.
                    </p>
                </div>
                <Button asChild>
                    <Link href="/studio/new">New Content</Link>
                </Button>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                {cards.map(([label, value, description]) => (
                    <Card key={label}>
                        <CardHeader className="pb-2">
                            <CardDescription className="text-xs uppercase tracking-wide">
                                {label}
                            </CardDescription>
                            <CardTitle className="text-3xl tabular-nums">
                                {isLoading ? "—" : value}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-xs text-muted-foreground">{description}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <div>
                        <CardTitle>Recent content</CardTitle>
                        <CardDescription>Most recently updated projects.</CardDescription>
                    </div>
                    <Button asChild size="sm" variant="ghost">
                        <Link href="/studio">View all</Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

                    {!isLoading && recent.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Nothing yet.{" "}
                            <Link href="/studio/new" className="underline">
                                Create your first content project
                            </Link>
                            .
                        </p>
                    )}

                    {recent.length > 0 && (
                        <ul className="divide-y">
                            {recent.map((project) => (
                                <li
                                    key={project.id}
                                    className="flex flex-wrap items-center justify-between gap-3 py-3"
                                >
                                    <div className="flex flex-col">
                                        <Link
                                            href={`/studio/${project.id}`}
                                            className="font-medium hover:underline"
                                        >
                                            {project.working_title}
                                        </Link>
                                        <span className="text-xs text-muted-foreground">
                                            {project.topic?.name ?? "No topic"}
                                            {project.topic_sequence
                                                ? ` · TEMA #${project.topic_sequence}`
                                                : ""}
                                            {" · "}
                                            {formatDuration(project.audio_duration)}
                                            {" · "}
                                            {formatDateTime(project.updated_at)}
                                        </span>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <ProjectStatusBadge
                                            pipeline="render"
                                            status={project.render.status}
                                            label={
                                                project.render.status === "rendering"
                                                    ? `${project.render.progress}%`
                                                    : project.render.label
                                            }
                                        />
                                        <ProjectStatusBadge
                                            pipeline="youtube"
                                            status={project.youtube.status}
                                            label={project.youtube.label}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </section>
    );
}
