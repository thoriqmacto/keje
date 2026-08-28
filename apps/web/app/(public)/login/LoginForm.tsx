"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { AxiosError } from "axios";
import { useAuth } from "@/components/auth-provider";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from "@/components/ui/form";

const schema = z.object({
    email: z.string().email({ message: "Enter a valid email." }),
    password: z.string().min(1, { message: "Password is required." }),
});

type FormValues = z.infer<typeof schema>;

export default function LoginForm() {
    const { login } = useAuth();
    const [submitting, setSubmitting] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { email: "", password: "" },
    });

    async function onSubmit(values: FormValues) {
        setSubmitting(true);
        try {
            await login(values);
            toast.success("Signed in.");
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            const message =
                axiosErr.response?.data?.message ?? "Invalid credentials. Please try again.";
            toast.error(message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-md flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Sign in</CardTitle>
                    <CardDescription>Enter your credentials to continue.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                            <FormField
                                control={form.control}
                                name="email"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Email</FormLabel>
                                        <FormControl>
                                            <Input type="email" autoComplete="email" placeholder="you@example.com" {...field} />
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
                                        <FormLabel>Password</FormLabel>
                                        <FormControl>
                                            <Input type="password" autoComplete="current-password" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" disabled={submitting}>
                                {submitting ? "Signing in…" : "Sign in"}
                            </Button>
                        </form>
                    </Form>
                </CardContent>
                <CardFooter className="flex flex-col items-stretch gap-3 text-sm">
                    <div className="flex justify-between">
                        <span className="text-muted-foreground">Don&apos;t have an account?</span>
                        <Link href="/register" className="font-medium hover:underline underline-offset-4">
                            Create one
                        </Link>
                    </div>
                    <Link
                        href="/forgot-password"
                        className="text-muted-foreground hover:text-foreground hover:underline underline-offset-4"
                    >
                        Forgot your password?
                    </Link>
                </CardFooter>
            </Card>
        </div>
    );
}
