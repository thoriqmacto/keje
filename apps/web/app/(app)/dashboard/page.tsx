"use client";

import { useAuth } from "@/components/auth-provider";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { API_BASE_URL, AUTH_MODE } from "@/lib/env";

export default function DashboardPage() {
    const { user } = useAuth();

    return (
        <section className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-12">
            <div className="flex flex-col gap-2">
                <span className="text-xs uppercase tracking-widest text-muted-foreground">
                    Authenticated page
                </span>
                <h1 className="text-3xl font-semibold tracking-tight">Dashboard</h1>
                <p className="text-muted-foreground">
                    You are signed in. This proves the Laravel API connection and the auth flow work.
                </p>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>You</CardTitle>
                        <CardDescription>Fetched from <code className="font-mono">/me</code>.</CardDescription>
                    </CardHeader>
                    <CardContent className="text-sm">
                        <div className="flex justify-between border-b py-2">
                            <span className="text-muted-foreground">Name</span>
                            <span>{user?.name}</span>
                        </div>
                        <div className="flex justify-between border-b py-2">
                            <span className="text-muted-foreground">Email</span>
                            <span>{user?.email}</span>
                        </div>
                        <div className="flex justify-between py-2">
                            <span className="text-muted-foreground">User ID</span>
                            <span>{user?.id}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Connection</CardTitle>
                        <CardDescription>Where this app is talking to.</CardDescription>
                    </CardHeader>
                    <CardContent className="text-sm">
                        <div className="flex justify-between border-b py-2">
                            <span className="text-muted-foreground">API base</span>
                            <code className="font-mono">{API_BASE_URL}</code>
                        </div>
                        <div className="flex justify-between py-2">
                            <span className="text-muted-foreground">Auth mode</span>
                            <code className="font-mono">{AUTH_MODE}</code>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </section>
    );
}
