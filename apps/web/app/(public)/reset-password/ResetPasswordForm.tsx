"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
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

const schema = z
    .object({
        email: z.string().email("Enter a valid email."),
        token: z.string().min(1, "Missing token — follow the link from your email."),
        password: z.string().min(8, "Password must be at least 8 characters."),
        password_confirmation: z.string().min(1, "Please confirm your password."),
    })
    .refine((v) => v.password === v.password_confirmation, {
        path: ["password_confirmation"],
        message: "Passwords do not match.",
    });

type FormValues = z.infer<typeof schema>;

export default function ResetPasswordForm() {
    const router = useRouter();
    const params = useSearchParams();
    const [submitting, setSubmitting] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            email: params.get("email") ?? "",
            token: params.get("token") ?? "",
            password: "",
            password_confirmation: "",
        },
    });

    async function onSubmit(values: FormValues) {
        setSubmitting(true);
        try {
            await api.post("/reset-password", values);
            toast.success("Password reset. Please sign in.");
            router.push("/login");
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
            const first = axiosErr.response?.data?.errors
                ? Object.values(axiosErr.response.data.errors).flat()[0]
                : undefined;
            toast.error(first ?? axiosErr.response?.data?.message ?? "Could not reset password.");
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto flex w-full max-w-md flex-col gap-6 px-4 py-12">
            <Card>
                <CardHeader>
                    <CardTitle>Reset password</CardTitle>
                    <CardDescription>Choose a new password.</CardDescription>
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
                                            <Input type="email" autoComplete="email" readOnly {...field} />
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
                                        <FormLabel>Confirm password</FormLabel>
                                        <FormControl>
                                            <Input type="password" autoComplete="new-password" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <input type="hidden" {...form.register("token")} />
                            <Button type="submit" disabled={submitting}>
                                {submitting ? "Resetting…" : "Reset password"}
                            </Button>
                        </form>
                    </Form>
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
