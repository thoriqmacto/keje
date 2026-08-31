"use client";

import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { YouTubeIntegrationDetail } from "@/components/studio/integration-panels";
import { useGoogleIntegrations } from "@/components/studio/youtube-selectors";

/**
 * The connected channel as a place to browse, not a settings row.
 *
 * Settings → Integrations manages the connection — connect, reconnect,
 * disconnect, permissions. Looking at what is *on* the channel is a different
 * job and belongs on its own page, reachable from the main navigation.
 *
 * Reuses the catalog panel the integrations page already had rather than a
 * second set of fetchers: the SWR cache is shared, so arriving here after
 * visiting Settings costs no extra quota.
 */
export default function YouTubeClient() {
    const { data: integrations, isLoading } = useGoogleIntegrations();

    return (
        <section className="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-10">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">YouTube</h1>
                    <p className="text-sm text-muted-foreground">
                        The channel Keje publishes to.
                    </p>
                </div>
                <Button asChild variant="outline" size="sm">
                    <Link href="/settings/integrations">Manage connection</Link>
                </Button>
            </header>

            {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

            {integrations && !integrations.youtube.connected && (
                <Card>
                    <CardHeader>
                        <CardTitle>YouTube is not connected</CardTitle>
                        <CardDescription>
                            Connect the channel to browse its playlists and recent uploads, and to
                            publish from the studio.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild>
                            <Link href="/settings/integrations">Connect YouTube</Link>
                        </Button>
                    </CardContent>
                </Card>
            )}

            {integrations?.youtube.connected && (
                <Card>
                    <CardContent className="pt-6">
                        <YouTubeIntegrationDetail
                            integrations={integrations}
                            onReconnect={() => {
                                window.location.href = "/settings/integrations";
                            }}
                        />
                    </CardContent>
                </Card>
            )}
        </section>
    );
}
