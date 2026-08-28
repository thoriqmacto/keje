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

const schema = z
    .object({
        name: z.string().min(1, "Name is required.").max(255),
        email: z.string().email("Enter a valid email."),
        password: z.string().min(8, "Password must be at least 8 characters."),
        password_confirmation: z.string().min(1, "Please confirm your password."),
    })
    .refine((v) => v.password === v.password_confirmation, {
        path: ["password_confirmation"],
        message: "Passwords do not match.",
    });

type FormValues = z.infer<typeof schema>;

export default function RegisterForm() {
    const { register } = useAuth();
    const [submitting, setSubmitting] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: "", email: "", password: "", password_confirmation: "" },
    });

    async function onSubmit(values: FormValues) {
        setSubmitting(true);
        try {
            await register(values);
            toast.success("Account created. Welcome!");
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
            const first = axiosErr.response?.data?.errors
                ? Object.values(axiosErr.response.data.errors).flat()[0]
                : undefined;
            toast.error(first ?? axiosErr.response?.data?.message ?? "Registration failed.");
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-md flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Create account</CardTitle>
                    <CardDescription>It only takes a moment.</CardDescription>
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
                                            <Input autoComplete="name" placeholder="Jane Doe" {...field} />
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
                                        <FormLabel>Confirm password</FormLabel>
                                        <FormControl>
                                            <Input type="password" autoComplete="new-password" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" disabled={submitting}>
                                {submitting ? "Creating…" : "Create account"}
                            </Button>
                        </form>
                    </Form>
                </CardContent>
                <CardFooter className="justify-between text-sm">
                    <span className="text-muted-foreground">Already have an account?</span>
                    <Link href="/login" className="font-medium hover:underline underline-offset-4">
                        Sign in
                    </Link>
                </CardFooter>
            </Card>
        </div>
    );
}
