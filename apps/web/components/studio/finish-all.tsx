"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { apiErrorMessage, finishOutdated, getFinishPlan } from "@/lib/studio/api";
import { hasActiveFilters, type StudioProjectQuery } from "@/lib/studio/table-query";
import type { FinishPlan } from "@/lib/types/studio";

/**
 * Re-render every outdated project in the current view.
 *
 * "Outdated" means the video was made from inputs the project no longer has —
 * a corrected subtitle, a renamed speaker. One at a time that is a button on a
 * project page; across forty it is this.
 *
 * The plan comes first, always. A bulk action whose effect cannot be inspected
 * before it runs is one people learn not to press, and this one queues real
 * encoder time. The plan also carries the sentence that matters most: nothing
 * here touches YouTube.
 */
export function FinishAll({
    query,
    onFinished,
}: {
    query: StudioProjectQuery;
    onFinished: () => void;
}) {
    const [plan, setPlan] = useState<FinishPlan | null>(null);
    const [busy, setBusy] = useState(false);

    async function onOpen() {
        setBusy(true);
        try {
            const next = await getFinishPlan(query);

            if (next.outdated === 0) {
                toast.info("No projects in this view need a fresh render.");

                return;
            }

            setPlan(next);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not work out what needs rendering."));
        } finally {
            setBusy(false);
        }
    }

    async function onConfirm() {
        setBusy(true);
        try {
            const result = await finishOutdated(query);
            await onFinished();
            setPlan(null);

            toast.success(
                result.blocked === 0
                    ? `Queued ${result.queued} project(s) for rendering.`
                    : `Queued ${result.queued}. ${result.blocked} could not be queued.`,
            );
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not queue the renders."));
        } finally {
            setBusy(false);
        }
    }

    if (plan === null) {
        return (
            <Button size="sm" variant="outline" disabled={busy} onClick={() => void onOpen()}>
                {busy ? "Checking…" : "Finish all"}
            </Button>
        );
    }

    return (
        <div
            role="dialog"
            aria-label="Finish outdated projects"
            className="flex w-full flex-col gap-3 rounded-lg border p-4"
        >
            <p className="text-sm font-medium">Finish outdated projects</p>

            {/* Which projects, said plainly. "All" over a filtered view means
                something different from "all" over an unfiltered one, and
                guessing wrong is the difference between four renders and
                four hundred. */}
            <p className="text-xs text-muted-foreground">
                {hasActiveFilters(query)
                    ? `${plan.outdated} outdated project(s) match your current filters — every page, not just this one.`
                    : `${plan.outdated} outdated project(s) across all your content.`}
            </p>

            <dl className="grid grid-cols-[12rem_1fr] gap-y-1 text-sm">
                <dt className="text-muted-foreground">Ready to render</dt>
                <dd className="tabular-nums">{plan.eligible}</dd>

                {plan.already_in_progress > 0 && (
                    <>
                        <dt className="text-muted-foreground">Already rendering</dt>
                        <dd className="tabular-nums">{plan.already_in_progress}</dd>
                    </>
                )}

                {plan.blocked > 0 && (
                    <>
                        <dt className="text-muted-foreground">Blocked</dt>
                        <dd className="tabular-nums">{plan.blocked}</dd>
                    </>
                )}
            </dl>

            {plan.blocked > 0 && (
                <ul className="flex flex-col gap-0.5 border-t pt-2 text-xs text-muted-foreground">
                    {Object.entries(plan.blocked_reasons).map(([code, count]) => (
                        <li key={code}>
                            {count} — {BLOCK_REASONS[code] ?? code}
                        </li>
                    ))}
                </ul>
            )}

            {plan.limited && (
                <p className="text-xs text-amber-700 dark:text-amber-400">
                    Only the first {plan.batch_limit} will be queued this time, so one click
                    cannot fill the render queue for days. Run it again afterwards.
                </p>
            )}

            {/*
                The reassurance, stated rather than implied. Some of these
                projects have published videos, and the obvious fear on
                pressing a bulk button is that it will touch them.
            */}
            <p className="rounded bg-muted p-2 text-xs">
                This queues renders only. Videos already on YouTube are{" "}
                <strong>not replaced, deleted or re-uploaded</strong>, and Drive backups
                are left alone. Once a render finishes you can replace its YouTube video
                from the project page, one at a time.
            </p>

            <div className="flex gap-2">
                <Button size="sm" variant="outline" disabled={busy} onClick={() => setPlan(null)}>
                    Cancel
                </Button>
                <Button size="sm" disabled={busy || plan.eligible === 0} onClick={() => void onConfirm()}>
                    {busy ? "Queueing…" : `Queue ${plan.eligible} render(s)`}
                </Button>
            </div>
        </div>
    );
}

/** Block codes, in words. A code alone tells nobody what to fix. */
const BLOCK_REASONS: Record<string, string> = {
    missing_media: "no audio or artwork uploaded",
    missing_text: "no primary title",
    missing_source_file: "source media is no longer on the server",
    text_does_not_fit: "the title does not fit the template",
    dispatch_failed: "could not be queued",
};
