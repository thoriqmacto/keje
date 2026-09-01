"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
    apiErrorMessage,
    getPrunePreview,
    getStorageInventory,
    pruneStorage,
    studioKeys,
} from "@/lib/studio/api";
import { formatBytes } from "@/lib/studio/bytes";
import { formatDateTime } from "@/lib/studio/format";
import type { PrunePreview, StorageProject } from "@/lib/types/studio";

/**
 * What Keje is keeping on the server, and the safe ways to reduce it.
 *
 * Local disk is the one resource this app can exhaust on its own — a lecture
 * recording is hundreds of megabytes and a render is bigger — and until now
 * nothing reported on it. The numbers come from the filesystem rather than the
 * database, because the two drift exactly when it matters.
 *
 * Every "not eligible" here carries a reason, and the reasons come from the
 * same code that does the deleting. A page that said only "blocked" would
 * leave somebody staring at gigabytes they cannot free with no idea what to
 * change.
 */
export default function StorageClient() {
    const { data, error, isLoading, mutate } = useSWR(studioKeys.storage, getStorageInventory, {
        // Files do not change on their own; re-reading the whole tree on a
        // timer would cost more than it tells anybody.
        revalidateOnFocus: false,
    });

    const [preview, setPreview] = useState<PrunePreview | null>(null);
    const [busy, setBusy] = useState(false);

    async function onPreview() {
        setBusy(true);
        try {
            setPreview(await getPrunePreview());
        } catch (err) {
            toast.error(apiErrorMessage(err, "Could not work out what could be freed."));
        } finally {
            setBusy(false);
        }
    }

    async function onPrune() {
        setBusy(true);
        try {
            const result = await pruneStorage();
            await mutate();
            setPreview(null);
            toast.success(
                result.pruned === 0
                    ? "Nothing was eligible to prune."
                    : `Freed ${formatBytes(result.bytes_freed)} across ${result.pruned} project(s).`,
            );
        } catch (err) {
            toast.error(apiErrorMessage(err, "Could not free the local media."));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="mx-auto flex w-full max-w-[90rem] flex-col gap-6 px-4 py-10">
            <div className="flex flex-col gap-1">
                <h1 className="text-3xl font-semibold tracking-tight">Storage</h1>
                <p className="text-muted-foreground">
                    Local media Keje is keeping on the server, and what can safely go.
                </p>
            </div>

            {error ? (
                <div className="flex flex-col items-start gap-3 rounded-lg border border-dashed p-10">
                    <p className="text-sm">Could not read the local media inventory.</p>
                    <Button size="sm" variant="outline" onClick={() => void mutate()}>
                        Retry
                    </Button>
                </div>
            ) : isLoading || !data ? (
                <p className="text-sm text-muted-foreground">Measuring local media…</p>
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <SummaryCard label="Total" value={formatBytes(data.totals.total)} hint="All Keje media" />
                        <SummaryCard label="Sources" value={formatBytes(data.totals.sources)} hint="Recordings and artwork" />
                        <SummaryCard label="Rendered" value={formatBytes(data.totals.renders)} hint="Finished MP4s" />
                        <SummaryCard
                            label="Thumbnails"
                            value={formatBytes(data.totals.thumbnails)}
                            hint="Kept through a prune"
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <SummaryCard label="Projects" value={String(data.counts.projects)} hint="Using local storage" />
                        <SummaryCard label="Can be freed" value={String(data.counts.eligible)} hint="Backed up and finished" />
                        <SummaryCard
                            label="Still correctable"
                            value={String(data.counts.correction_window)}
                            hint="Inside the correction window"
                        />
                        <SummaryCard
                            label="Outdated"
                            value={String(data.counts.outdated)}
                            hint="Need a fresh render"
                        />
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => void mutate()}>
                            Refresh inventory
                        </Button>
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => void onPreview()}>
                            Preview what can be freed
                        </Button>
                    </div>

                    {preview && (
                        <PrunePreviewPanel
                            preview={preview}
                            busy={busy}
                            onCancel={() => setPreview(null)}
                            onConfirm={() => void onPrune()}
                        />
                    )}

                    {data.projects.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No project is using local storage. Everything has been backed up and cleaned.
                        </p>
                    ) : (
                        <ProjectTable projects={data.projects} />
                    )}

                    {data.orphans.length > 0 && <Orphans orphans={data.orphans} />}
                </>
            )}
        </section>
    );
}

function SummaryCard({ label, value, hint }: { label: string; value: string; hint: string }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardDescription className="text-xs uppercase tracking-wide">{label}</CardDescription>
                <CardTitle className="text-2xl tabular-nums">{value}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-xs text-muted-foreground">{hint}</p>
            </CardContent>
        </Card>
    );
}

/**
 * What a prune would free, before it frees it.
 *
 * The skipped list is as important as the eligible one: somebody who expected
 * to reclaim ten gigabytes and got two needs to see which projects declined
 * and why, without going looking.
 */
function PrunePreviewPanel({
    preview,
    busy,
    onCancel,
    onConfirm,
}: {
    preview: PrunePreview;
    busy: boolean;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    return (
        <div className="flex flex-col gap-3 rounded-lg border p-4">
            <p className="text-sm font-medium">Free local media?</p>

            <dl className="grid grid-cols-[10rem_1fr] gap-y-1 text-sm">
                <dt className="text-muted-foreground">Projects eligible</dt>
                <dd>{preview.eligible.length}</dd>

                <dt className="text-muted-foreground">Sources</dt>
                <dd className="tabular-nums">{formatBytes(preview.bytes.sources)}</dd>

                <dt className="text-muted-foreground">Renders</dt>
                <dd className="tabular-nums">{formatBytes(preview.bytes.renders)}</dd>

                <dt className="font-medium">Total reclaimable</dt>
                <dd className="font-medium tabular-nums">{formatBytes(preview.bytes.total)}</dd>
            </dl>

            {preview.skipped.length > 0 && (
                <div className="flex flex-col gap-1 border-t pt-3 text-xs">
                    <p className="font-medium">{preview.skipped.length} project(s) will be kept</p>
                    {preview.skipped.slice(0, 5).map((entry) => (
                        <p key={entry.id} className="text-muted-foreground">
                            {entry.working_title} — {entry.reasons[0]?.message}
                        </p>
                    ))}
                </div>
            )}

            {/* Thumbnails survive, so nobody wonders why the total is smaller
                than the inventory suggested. */}
            <p className="text-xs text-muted-foreground">
                Thumbnails are kept: they are tiny and still wanted for a retry.
            </p>

            <div className="flex gap-2">
                <Button size="sm" variant="outline" disabled={busy} onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    size="sm"
                    disabled={busy || preview.eligible.length === 0}
                    onClick={onConfirm}
                >
                    {busy
                        ? "Freeing…"
                        : `Free ${formatBytes(preview.bytes.total)} from ${preview.eligible.length} project(s)`}
                </Button>
            </div>
        </div>
    );
}

function ProjectTable({ projects }: { projects: StorageProject[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full min-w-[60rem] border-collapse text-sm">
                <thead>
                    <tr className="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th className="px-4 py-3 font-medium">Project</th>
                        <th className="px-4 py-3 text-right font-medium">Sources</th>
                        <th className="px-4 py-3 text-right font-medium">Render</th>
                        <th className="px-4 py-3 text-right font-medium">Total</th>
                        <th className="px-4 py-3 font-medium">State</th>
                        <th className="px-4 py-3 font-medium">Updated</th>
                        <th className="px-4 py-3 font-medium" />
                    </tr>
                </thead>
                <tbody>
                    {projects.map((project) => (
                        <tr key={project.id} className="border-b last:border-0 align-top">
                            <td className="px-4 py-3">
                                <Link href={`/studio/${project.id}`} className="font-medium hover:underline">
                                    {project.working_title}
                                </Link>
                                {project.topic && (
                                    <p className="text-xs text-muted-foreground">{project.topic}</p>
                                )}
                            </td>
                            <td className="px-4 py-3 text-right tabular-nums">
                                {formatBytes(project.bytes.sources)}
                            </td>
                            <td className="px-4 py-3 text-right tabular-nums">
                                {formatBytes(project.bytes.renders)}
                            </td>
                            <td className="px-4 py-3 text-right font-medium tabular-nums">
                                {formatBytes(project.bytes.total)}
                            </td>
                            <td className="px-4 py-3">
                                <PruneState project={project} />
                            </td>
                            <td className="px-4 py-3 text-xs text-muted-foreground">
                                {formatDateTime(project.last_modified)}
                            </td>
                            <td className="px-4 py-3">
                                {/* An outdated project's sources are being kept
                                    so it can be re-rendered, so the useful
                                    offer here is the render, not a prune. */}
                                {project.render_is_stale ? (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={`/studio/${project.id}`}>Finish render</Link>
                                    </Button>
                                ) : (
                                    <Button asChild size="sm" variant="ghost">
                                        <Link href={`/studio/${project.id}`}>Open</Link>
                                    </Button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Eligible, or the reasons it is not. Never a bare "no". */
function PruneState({ project }: { project: StorageProject }) {
    if (project.prunable) {
        return <span className="text-xs text-emerald-600 dark:text-emerald-400">Ready to free</span>;
    }

    return (
        <ul className="flex flex-col gap-0.5 text-xs text-muted-foreground">
            {project.blocked_reasons.map((reason) => (
                <li key={reason.code}>{reason.message}</li>
            ))}
        </ul>
    );
}

/**
 * Managed directories no project claims.
 *
 * Shown and never deleted for you: an unreferenced directory is as likely to
 * be a database problem as a disk one, and removing media to tidy a listing is
 * the wrong way round.
 */
function Orphans({ orphans }: { orphans: { id: string; bytes: number; files: number }[] }) {
    const total = orphans.reduce((sum, orphan) => sum + orphan.bytes, 0);

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-dashed p-4">
            <p className="text-sm font-medium">
                Unreferenced media — {orphans.length} folder(s), {formatBytes(total)}
            </p>
            <p className="text-xs text-muted-foreground">
                Media Keje is storing that no current project claims, usually left by a
                deleted project. Nothing here is removed automatically: an unreferenced
                folder is as likely to mean a missing database row as a stray file.
            </p>
            <ul className="flex flex-col gap-0.5 font-mono text-xs text-muted-foreground">
                {orphans.slice(0, 10).map((orphan) => (
                    <li key={orphan.id}>
                        {orphan.id} — {formatBytes(orphan.bytes)} in {orphan.files} file(s)
                    </li>
                ))}
            </ul>
        </div>
    );
}
