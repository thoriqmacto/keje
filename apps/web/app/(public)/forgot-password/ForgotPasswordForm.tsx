"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { AxiosError } from "axios";
import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
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

const schema = z.object({
    email: z.string().email("Enter a valid email."),
});

type FormValues = z.infer<typeof schema>;

export default function ForgotPasswordForm() {
    const [submitting, setSubmitting] = useState(false);
    const [sent, setSent] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { email: "" },
    });

    async function onSubmit(values: FormValues) {
        setSubmitting(true);
        try {
            await api.post("/forgot-password", values);
            setSent(true);
            toast.success("Check your email for a reset link.");
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
            const msg =
                axiosErr.response?.data?.errors?.email?.[0] ??
                axiosErr.response?.data?.message ??
                "Could not send reset link.";
            toast.error(msg);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-md flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Forgot your password?</CardTitle>
                    <CardDescription>
                        Enter your email and we&apos;ll send a reset link.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {sent ? (
                        <p className="text-sm text-muted-foreground">
                            If an account exists for that email, a reset link is on its way.
                        </p>
                    ) : (
                        <Form {...form}>
                            <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                                <FormField
                                    control={form.control}
                                    name="email"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Email</FormLabel>
                                            <FormControl>
                                                <Input
                                                    type="email"
                                                    autoComplete="email"
                                                    placeholder="you@example.com"
                                                    {...field}
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <Button type="submit" disabled={submitting}>
                                    {submitting ? "Sending…" : "Send reset link"}
                                </Button>
                            </form>
                        </Form>
                    )}
                </CardContent>
                <CardFooter className="justify-between text-sm">
                    <Link href="/login" className="font-medium hover:underline underline-offset-4">
                        Back to sign in
                    </Link>
                </CardFooter>
            </Card>
        </div>
    );
}
