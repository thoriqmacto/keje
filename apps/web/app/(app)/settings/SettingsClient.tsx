"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { AxiosError } from "axios";
import { api } from "@/lib/api";
import { useAuth } from "@/components/auth-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from "@/components/ui/form";

const profileSchema = z.object({
    name: z.string().min(1, "Name is required.").max(255),
    email: z.string().email("Enter a valid email."),
});
type ProfileValues = z.infer<typeof profileSchema>;

const passwordSchema = z
    .object({
        current_password: z.string().min(1, "Current password is required."),
        password: z.string().min(8, "Password must be at least 8 characters."),
        password_confirmation: z.string().min(1, "Please confirm your password."),
    })
    .refine((v) => v.password === v.password_confirmation, {
        path: ["password_confirmation"],
        message: "Passwords do not match.",
    });
type PasswordValues = z.infer<typeof passwordSchema>;

function readError(err: unknown, fallback: string): string {
    const ax = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
    const first = ax?.response?.data?.errors
        ? Object.values(ax.response.data.errors).flat()[0]
        : undefined;
    return first ?? ax?.response?.data?.message ?? fallback;
}

function ProfileSection() {
    const { user, refresh } = useAuth();
    const [submitting, setSubmitting] = useState(false);

    const form = useForm<ProfileValues>({
        resolver: zodResolver(profileSchema),
        defaultValues: { name: user?.name ?? "", email: user?.email ?? "" },
    });

    // Keep the form in sync if the underlying user changes (refresh, etc.).
    useEffect(() => {
        if (user) form.reset({ name: user.name, email: user.email });
    }, [user, form]);

    async function onSubmit(values: ProfileValues) {
        setSubmitting(true);
        try {
            await api.patch("/me", values);
            await refresh();
            toast.success("Profile updated.");
        } catch (err) {
            toast.error(readError(err, "Could not update profile."));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Profile</CardTitle>
                <CardDescription>Update your name and email.</CardDescription>
            </CardHeader>
            <CardContent>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Name</FormLabel>
                                    <FormControl>
                                        <Input autoComplete="name" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="email"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Email</FormLabel>
                                    <FormControl>
                                        <Input type="email" autoComplete="email" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <Button type="submit" disabled={submitting} className="self-start">
                            {submitting ? "Saving…" : "Save changes"}
                        </Button>
                    </form>
                </Form>
            </CardContent>
        </Card>
    );
}

function VerifyEmailSection() {
    const { user } = useAuth();
    const [sending, setSending] = useState(false);

    if (!user || user.email_verified_at) return null;

    async function onResend() {
        setSending(true);
        try {
            await api.post("/email/verification-notification");
            toast.success("Verification email sent. Check your inbox.");
        } catch (err) {
            toast.error(readError(err, "Could not send verification email."));
        } finally {
            setSending(false);
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Verify your email</CardTitle>
                <CardDescription>
                    We haven&apos;t confirmed <code className="font-mono">{user.email}</code> yet.
                    Resend the verification link to finish setup.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button onClick={() => void onResend()} disabled={sending}>
                    {sending ? "Sending…" : "Resend verification email"}
                </Button>
            </CardContent>
        </Card>
    );
}

function PasswordSection() {
    const [submitting, setSubmitting] = useState(false);

    const form = useForm<PasswordValues>({
        resolver: zodResolver(passwordSchema),
        defaultValues: { current_password: "", password: "", password_confirmation: "" },
    });

    async function onSubmit(values: PasswordValues) {
        setSubmitting(true);
        try {
            await api.patch("/me/password", values);
            form.reset({ current_password: "", password: "", password_confirmation: "" });
            toast.success("Password updated. Other sessions have been signed out.");
        } catch (err) {
            toast.error(readError(err, "Could not update password."));
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Password</CardTitle>
                <CardDescription>
                    Updating your password signs out other devices but keeps you signed in here.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                        <FormField
                            control={form.control}
                            name="current_password"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Current password</FormLabel>
                                    <FormControl>
                                        <Input type="password" autoComplete="current-password" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="password"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>New password</FormLabel>
                                    <FormControl>
                                        <Input type="password" autoComplete="new-password" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="password_confirmation"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Confirm new password</FormLabel>
                                    <FormControl>
                                        <Input type="password" autoComplete="new-password" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <Button type="submit" disabled={submitting} className="self-start">
                            {submitting ? "Updating…" : "Update password"}
                        </Button>
                    </form>
                </Form>
            </CardContent>
        </Card>
    );
}

export default function SettingsClient() {
    return (
        <section className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <div className="flex flex-col gap-2">
                <span className="text-xs uppercase tracking-widest text-muted-foreground">
                    Account
                </span>
                <h1 className="text-3xl font-semibold tracking-tight">Settings</h1>
                <p className="text-muted-foreground">
                    Update your profile and password.
                </p>
            </div>
            <VerifyEmailSection />
            <ProfileSection />
            <PasswordSection />
        </section>
    );
}
