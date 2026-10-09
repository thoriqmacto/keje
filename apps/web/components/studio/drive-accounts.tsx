"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
    apiErrorMessage,
    disconnectDriveAccount,
    getDrivePool,
    googleKeys,
    listDriveBackupsByAccount,
    refreshDrivePool,
    renameDriveBackup,
    trashDriveBackup,
    updateDriveAccount,
} from "@/lib/studio/api";
import { formatBytes, formatDateTime } from "@/lib/studio/format";
import type { DriveAccount, DriveStoragePool } from "@/lib/types/studio";

/**
 * Drive as a pool of accounts, rather than one Drive.
 *
 * A free Google account holds 15 GB shared with Gmail and Photos, and a
 * rendered lecture is a few hundred megabytes — so one account is a ceiling
 * on how much of a course Keje can keep. This page is where that ceiling is
 * visible and where another account gets added beside it.
 *
 * ── Two numbers that do not reconcile, labelled so ──────────────────────
 *
 * An account's quota covers the whole Google account: Drive, Gmail and
 * Photos. The file list below it is only Keje's own backups, because the
 * grant is `drive.file` and Keje cannot see anything else. Those two will
 * never add up, and the labelling says which is which instead of letting
 * somebody conclude the maths is broken.
 */
export function DriveAccountsPanel() {
    const pool = useSWR(googleKeys.drivePool, getDrivePool, { revalidateOnFocus: true });
    const [busy, setBusy] = useState(false);

    async function onRefresh() {
        setBusy(true);
        try {
            const { data, message } = await refreshDrivePool();
            await pool.mutate(data, { revalidate: false });
            toast.success(message);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not re-read storage from Google."));
        } finally {
            setBusy(false);
        }
    }

    if (pool.isLoading) {
        return <p className="text-sm text-muted-foreground">Loading storage…</p>;
    }

    if (!pool.data) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Could not load the Drive accounts</CardTitle>
                    <CardDescription>
                        The API did not answer. Nothing has changed about your backups.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button variant="outline" size="sm" onClick={() => void pool.mutate()}>
                        Try again
                    </Button>
                </CardContent>
            </Card>
        );
    }

    const { accounts, totals } = pool.data;

    return (
        <div className="flex flex-col gap-5">
            <PoolSummary pool={pool.data} busy={busy} onRefresh={() => void onRefresh()} />

            {accounts.length === 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>No Drive account connected</CardTitle>
                        <CardDescription>
                            Connect one to back up rendered videos and free space on the server.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild>
                            <Link href="/settings/integrations">Connect Google Drive</Link>
                        </Button>
                    </CardContent>
                </Card>
            )}

            {accounts.map((account) => (
                <AccountCard
                    key={account.id}
                    account={account}
                    canDisconnect={accounts.length > 0}
                    onChanged={(next) => void pool.mutate(next, { revalidate: false })}
                />
            ))}

            {accounts.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Add another Google Drive account</CardTitle>
                        <CardDescription>
                            Keje fills accounts in order and moves to the next when one no longer
                            has room. A backup stays in the account it was made in — accounts own
                            what they store, so nothing is moved between them afterwards.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        {/* The same consent flow as the first account. Google's
                            screen offers whichever accounts the browser is
                            signed in to, so there is nothing to type here. */}
                        <Button asChild className="self-start">
                            <Link href="/settings/integrations">Add an account</Link>
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            Pick a different Google account on the consent screen. Connecting the
                            same one again just refreshes it — it will not be added twice.
                            {totals.includes_unlimited &&
                                " One connected account reports unlimited storage, so the totals above cover only the metered ones."}
                        </p>
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

/** The pooled total, and whether the remaining recordings fit in it. */
function PoolSummary({
    pool,
    busy,
    onRefresh,
}: {
    pool: DriveStoragePool;
    busy: boolean;
    onRefresh: () => void;
}) {
    const { totals, pending } = pool;
    const unmeasured = totals.accounts - totals.measured_accounts;

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>Backup storage</CardTitle>
                        <CardDescription>
                            Across {totals.accounts}{" "}
                            {totals.accounts === 1 ? "account" : "accounts"}.
                        </CardDescription>
                    </div>
                    <Button variant="outline" size="sm" disabled={busy} onClick={onRefresh}>
                        {busy ? "Re-reading…" : "Re-read from Google"}
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-muted-foreground">Usable for backups</dt>
                        <dd className="font-medium">
                            {totals.usable === null ? "—" : formatBytes(totals.usable)}
                        </dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-muted-foreground">Still to back up</dt>
                        <dd className="font-medium">
                            {formatBytes(pending.bytes)}
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                ({pending.projects}{" "}
                                {pending.projects === 1 ? "project" : "projects"})
                            </span>
                        </dd>
                    </div>
                    {/*
                        The biggest single file the pool can take. Not a detail:
                        space spread over several accounts cannot hold one large
                        file, and a total alone would imply it could.
                    */}
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-muted-foreground">Largest single file</dt>
                        <dd>
                            {totals.largest_single_file === null
                                ? "—"
                                : formatBytes(totals.largest_single_file)}
                        </dd>
                    </div>
                    <div className="flex items-baseline justify-between gap-3">
                        <dt className="text-muted-foreground">Used across accounts</dt>
                        <dd>
                            {formatBytes(totals.usage)}
                            {totals.limit !== null && ` of ${formatBytes(totals.limit)}`}
                        </dd>
                    </div>
                </dl>

                {pending.fits === false && (
                    <div className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-400">
                        <p className="font-medium">
                            Not enough room for everything still to back up
                        </p>
                        <p>
                            {formatBytes(pending.shortfall)} short. Add another Google Drive
                            account, or free space in one that is already connected. Backups
                            already made are unaffected.
                        </p>
                    </div>
                )}

                {/* The failure a total hides: everything fits in the pool, and
                    the biggest file fits in no single account. That upload
                    fails however healthy the total looks. */}
                {pending.fits !== false && pending.largest_project_fits === false && (
                    <div className="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-400">
                        <p className="font-medium">The largest recording will not fit</p>
                        <p>
                            There is {formatBytes(totals.usable)} free in total, but no single
                            account has room for the biggest file (
                            {formatBytes(pending.largest_project_bytes)}). A backup cannot be
                            split across accounts, so that one needs an account with space of its
                            own.
                        </p>
                    </div>
                )}

                {unmeasured > 0 && (
                    <p className="text-xs text-muted-foreground">
                        {unmeasured} {unmeasured === 1 ? "account has" : "accounts have"} not been
                        measured yet, so the totals above leave{" "}
                        {unmeasured === 1 ? "it" : "them"} out. Press Re-read from Google.
                    </p>
                )}

                {/*
                    Said plainly rather than left for somebody to work out from
                    two numbers that do not add up.
                */}
                <p className="text-xs text-muted-foreground">
                    A Google account&apos;s storage is shared with Gmail and Photos, so free space
                    can change without Keje uploading anything — these figures are a snapshot, not
                    a reservation. Keje also leaves some headroom in every account rather than
                    filling it to the last byte.
                </p>
            </CardContent>
        </Card>
    );
}

/** One account: its quota, its label, its place in the order, and its files. */
function AccountCard({
    account,
    canDisconnect,
    onChanged,
}: {
    account: DriveAccount;
    canDisconnect: boolean;
    onChanged: (pool: DriveStoragePool) => void;
}) {
    const [label, setLabel] = useState(account.label ?? "");
    const [saving, setSaving] = useState(false);
    const [showFiles, setShowFiles] = useState(false);

    const percent = account.percent_used;
    const nearlyFull = percent !== null && percent >= 90;

    async function onSaveLabel() {
        setSaving(true);
        try {
            onChanged(await updateDriveAccount(account.id, { label: label.trim() || null }));
            toast.success("Account renamed.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not rename the account."));
        } finally {
            setSaving(false);
        }
    }

    async function onMove(direction: -1 | 1) {
        setSaving(true);
        try {
            onChanged(
                await updateDriveAccount(account.id, {
                    priority: Math.max(1, account.priority + direction),
                }),
            );
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not change the fill order."));
        } finally {
            setSaving(false);
        }
    }

    async function onDisconnect() {
        setSaving(true);
        try {
            const { data, message } = await disconnectDriveAccount(account.id);
            onChanged(data);
            toast.success(message);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not disconnect the account."));
        } finally {
            setSaving(false);
        }
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-base">{account.display_name}</CardTitle>
                        <CardDescription className="truncate">
                            {account.email ?? "Account not identified yet"}
                            {account.label && account.email ? "" : ""}
                        </CardDescription>
                    </div>
                    <span
                        className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                            !account.healthy
                                ? "bg-red-500/10 text-red-600 dark:text-red-400"
                                : nearlyFull
                                  ? "bg-amber-500/10 text-amber-700 dark:text-amber-400"
                                  : "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                        }`}
                    >
                        {!account.healthy
                            ? "Needs attention"
                            : account.unlimited
                              ? "Unlimited"
                              : nearlyFull
                                ? "Nearly full"
                                : `Fill order ${account.priority}`}
                    </span>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {!account.measured ? (
                    <p className="text-sm text-muted-foreground">
                        Storage has not been read for this account yet.
                    </p>
                ) : account.unlimited ? (
                    <p className="text-sm">Google reports no storage limit for this account.</p>
                ) : (
                    <div className="flex flex-col gap-1">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span>
                                {formatBytes(account.usage)} of {formatBytes(account.limit)} used
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {formatBytes(account.usable)} usable for backups
                            </span>
                        </div>
                        <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full rounded-full ${
                                    nearlyFull ? "bg-amber-500" : "bg-emerald-500"
                                }`}
                                style={{ width: `${Math.min(100, Math.max(1, percent ?? 0))}%` }}
                            />
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {percent}% of the whole Google account — Drive, Gmail and Photos
                            together, not only Keje&apos;s backups.
                            {account.checked_at && ` Read ${formatDateTime(account.checked_at)}.`}
                        </p>
                    </div>
                )}

                {!account.healthy && (
                    <div className="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
                        <p className="font-medium">This account&apos;s connection needs attention</p>
                        <p>
                            Backups to it will fail until it is reconnected.{" "}
                            <Link href="/settings/integrations" className="underline">
                                Open Integrations
                            </Link>
                            .
                        </p>
                    </div>
                )}

                <div className="flex flex-wrap items-end gap-2">
                    <div className="flex min-w-[12rem] flex-col gap-1">
                        <label
                            htmlFor={`label-${account.id}`}
                            className="text-xs text-muted-foreground"
                        >
                            Name for this account
                        </label>
                        <Input
                            id={`label-${account.id}`}
                            value={label}
                            placeholder={account.email ?? "Drive account"}
                            maxLength={60}
                            onChange={(event) => setLabel(event.target.value)}
                        />
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={saving || label === (account.label ?? "")}
                        onClick={() => void onSaveLabel()}
                    >
                        Save name
                    </Button>
                    {/* Fill order, not a preference: the lowest number takes
                        the next backup, so moving an account up changes where
                        the next lecture lands. */}
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={saving || account.priority <= 1}
                        onClick={() => void onMove(-1)}
                        aria-label="Fill this account earlier"
                    >
                        Fill earlier
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={saving}
                        onClick={() => void onMove(1)}
                        aria-label="Fill this account later"
                    >
                        Fill later
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setShowFiles((open) => !open)}
                    >
                        {showFiles ? "Hide backups" : "Show backups"}
                    </Button>
                    {canDisconnect && (
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={saving}
                            className="text-red-600 dark:text-red-400"
                            onClick={() => void onDisconnect()}
                        >
                            Disconnect
                        </Button>
                    )}
                </div>

                {showFiles && <AccountFiles accountId={account.id} />}
            </CardContent>
        </Card>
    );
}

/**
 * Keje's backups in one account, with the actions on them.
 *
 * Loaded only when opened: each account is a Drive round trip, and a page
 * with four accounts should not make four of them to show lists nobody has
 * asked to see.
 */
function AccountFiles({ accountId }: { accountId: string }) {
    const groups = useSWR(googleKeys.driveBackups, () => listDriveBackupsByAccount(20));
    const [renaming, setRenaming] = useState<string | null>(null);
    const [draft, setDraft] = useState("");
    const [busy, setBusy] = useState(false);

    const group = groups.data?.find((candidate) => candidate.account.id === accountId);

    async function onRename(fileId: string) {
        setBusy(true);
        try {
            await renameDriveBackup(accountId, fileId, draft.trim());
            await groups.mutate();
            setRenaming(null);
            toast.success("Backup renamed.");
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not rename the backup."));
        } finally {
            setBusy(false);
        }
    }

    async function onTrash(fileId: string, name: string | null) {
        // Confirmed, unlike most actions here. The local render is pruned once
        // a backup succeeds, so this can be the last copy of a lecture — and
        // the Drive trash being recoverable for thirty days is exactly the
        // kind of reassurance worth stating at the moment of asking.
        if (
            !window.confirm(
                `Move “${name ?? "this backup"}” to the Google Drive trash?\n\n` +
                    "It can be restored from Drive for 30 days. If this is the only copy of the " +
                    "lecture, that window is all there is.",
            )
        ) {
            return;
        }

        setBusy(true);
        try {
            const { message } = await trashDriveBackup(accountId, fileId);
            await groups.mutate();
            toast.success(message);
        } catch (error) {
            toast.error(apiErrorMessage(error, "Could not remove the backup."));
        } finally {
            setBusy(false);
        }
    }

    if (groups.isLoading) {
        return <p className="border-t pt-3 text-xs text-muted-foreground">Loading backups…</p>;
    }

    if (group?.error) {
        return (
            <p className="border-t pt-3 text-xs text-amber-700 dark:text-amber-400">
                {group.error}
            </p>
        );
    }

    const files = group?.files ?? [];

    return (
        <div className="flex flex-col gap-2 border-t pt-3">
            <p className="text-xs text-muted-foreground">
                Files Keje created in this account. It never asks for access to the rest of the
                Drive, so this is not a view of the account&apos;s contents.
            </p>

            {files.length === 0 && (
                <p className="text-xs text-muted-foreground">No backups in this account yet.</p>
            )}

            {files.map((file) => (
                <div key={file.id} className="flex flex-wrap items-center justify-between gap-2">
                    {renaming === file.id ? (
                        <>
                            <Input
                                value={draft}
                                maxLength={255}
                                className="min-w-[14rem] flex-1"
                                onChange={(event) => setDraft(event.target.value)}
                                aria-label="New name"
                            />
                            <span className="flex gap-2">
                                <Button
                                    size="sm"
                                    disabled={busy || draft.trim() === ""}
                                    onClick={() => void onRename(file.id)}
                                >
                                    Save
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setRenaming(null)}
                                >
                                    Cancel
                                </Button>
                            </span>
                        </>
                    ) : (
                        <>
                            <span className="min-w-0 flex-1 truncate text-sm">
                                {file.web_view_link ? (
                                    <a
                                        href={file.web_view_link}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="hover:underline"
                                    >
                                        {file.name}
                                    </a>
                                ) : (
                                    file.name
                                )}
                            </span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {formatBytes(file.size)} · {formatDateTime(file.created_at)}
                            </span>
                            <span className="flex shrink-0 gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        setDraft(file.name ?? "");
                                        setRenaming(file.id);
                                    }}
                                >
                                    Rename
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={busy}
                                    className="text-red-600 dark:text-red-400"
                                    onClick={() => void onTrash(file.id, file.name)}
                                >
                                    Remove
                                </Button>
                            </span>
                        </>
                    )}
                </div>
            ))}

            {group?.next_page_token && (
                <p className="text-xs text-muted-foreground">
                    Showing the most recent 20 backups in this account.
                </p>
            )}
        </div>
    );
}
