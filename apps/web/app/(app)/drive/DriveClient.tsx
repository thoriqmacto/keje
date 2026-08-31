"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { DriveIntegrationDetail } from "@/components/studio/integration-panels";
import { useGoogleIntegrations } from "@/components/studio/youtube-selectors";

/**
 * The files Keje put in Drive — not the user's whole Drive.
 *
 * The OAuth grant is drive.file and stays that way: Keje sees what it created
 * and nothing else. Widening the scope to browse everything would trade the
 * entire point of the narrow grant for a file picker nobody asked for, so the
 * page says what it is showing rather than implying more.
 */
export default function DriveClient() {
    const { data: integrations, isLoading } = useGoogleIntegrations();

    return (
        <section className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-10">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Google Drive</h1>
                    <p className="text-sm text-muted-foreground">
                        Rendered videos Keje has backed up.
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

            {integrations?.drive.connected && (
                <Card>
                    <CardContent className="pt-6">
                        <DriveIntegrationDetail integrations={integrations} />
                    </CardContent>
                </Card>
            )}
        </section>
    );
}
