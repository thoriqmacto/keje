"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DriveAccountsPanel } from "@/components/studio/drive-accounts";
import { useGoogleIntegrations } from "@/components/studio/youtube-selectors";

/**
 * The Drive accounts Keje backs up to, and the files it put in them.
 *
 * Not the user's Drive. The OAuth grant is drive.file and stays that way:
 * Keje sees what it created and nothing else. Widening the scope to browse
 * everything would trade the entire point of the narrow grant for a file
 * picker nobody asked for, so the page says what it is showing rather than
 * implying more.
 *
 * Several accounts, because one is a ceiling. A free Google account holds
 * 15 GB shared with Gmail and Photos, and a rendered lecture is a few hundred
 * megabytes — so "how much of this course can Keje keep" is answered by how
 * many accounts are attached, which is what this page is for.
 */
export default function DriveClient() {
    const { data: integrations, isLoading } = useGoogleIntegrations();

    return (
        <section className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-10">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Google Drive</h1>
                    <p className="text-sm text-muted-foreground">
                        Rendered videos Keje has backed up, and the accounts holding them.
                    </p>
                </div>
                <Button asChild variant="outline" size="sm">
                    <Link href="/settings/integrations">Manage connection</Link>
                </Button>
            </header>

            {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

            {integrations && !integrations.drive.connected && (
                <Card>
                    <CardHeader>
                        <CardTitle>Google Drive is not connected</CardTitle>
                        <CardDescription>
                            Connect Drive to back up rendered videos and free space on the server.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild>
                            <Link href="/settings/integrations">Connect Google Drive</Link>
                        </Button>
                    </CardContent>
                </Card>
            )}

            {/* The panel reads the account pool itself rather than taking it
                from the connection status: `drive.connected` answers whether
                any account is attached, and everything here is about which
                ones and how much room they have left. */}
            {integrations?.drive.connected && <DriveAccountsPanel />}
        </section>
    );
}
