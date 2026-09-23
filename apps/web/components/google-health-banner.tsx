"use client";

import Link from "next/link";
import useSWR from "swr";
import { getGoogleHealth, studioKeys } from "@/lib/studio/api";
import {
    attentionSummary,
    needsAttention,
    worstSeverity,
} from "@/lib/studio/google-health";

/**
 * The app-wide warning that a Google connection will not work.
 *
 * This is the answer to the actual complaint: a dead token used to announce
 * itself as a failed upload, after a render had been spent on it. The hourly
 * `google:health` check finds it within the hour instead, and this is where
 * that finding is allowed to interrupt.
 *
 * ── A read, on every page ───────────────────────────────────────────────
 *
 * It calls the read endpoint, never the probe. Probing here would put two
 * Google round trips in front of every navigation in the app. The scheduled
 * check is what keeps the answer current; "Check now" on the Integrations
 * page is the manual override.
 *
 * ── Silent unless it matters ────────────────────────────────────────────
 *
 * Nothing renders for a healthy connection, a connection nobody set up, or a
 * network blip — see needsAttention(). A banner that is usually there is a
 * banner nobody reads, which would waste the one channel that reliably
 * reaches somebody before they click upload.
 */
export function GoogleHealthBanner() {
    /*
     * revalidateOnFocus is the whole point of the refresh interval being
     * generous: coming back to the tab is when somebody is about to act, and
     * that is worth a fresh read far more than a timer is. Five minutes is
     * the floor under a tab left open on the Studio list all afternoon.
     */
    const { data } = useSWR(studioKeys.googleHealth, getGoogleHealth, {
        refreshInterval: 300_000,
        revalidateOnFocus: true,
        // A failed read must not produce a scary banner of its own. Whatever
        // is wrong with the API, guessing at Google's state from here would
        // be making it up.
        shouldRetryOnError: false,
    });

    if (!data) return null;

    const services = [data.youtube, data.drive].filter(Boolean);
    const summary = attentionSummary(services);

    if (summary === null) return null;

    const critical = worstSeverity(services.filter(needsAttention)) === "critical";

    return (
        <div
            /*
             * role="status" rather than "alert". This is not the result of
             * something the user just did — it is standing state, and an
             * assertive live region would interrupt a screen reader mid-word
             * on every page load.
             */
            role="status"
            className={`border-b px-4 py-2 text-sm ${
                critical
                    ? "border-red-500/20 bg-red-500/10 text-red-700 dark:text-red-400"
                    : "border-amber-500/20 bg-amber-500/10 text-amber-800 dark:text-amber-400"
            }`}
        >
            <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <p className="min-w-0">
                    <span className="font-medium">
                        {critical ? "Publishing will fail" : "Publishing will stop working soon"}
                    </span>{" "}
                    — {summary}
                </p>
                {/* The fix is always on the same page, so the banner always
                    points at it rather than describing the journey. */}
                <Link
                    href="/settings/integrations"
                    className="shrink-0 font-medium underline underline-offset-4"
                >
                    Fix it
                </Link>
            </div>
        </div>
    );
}
