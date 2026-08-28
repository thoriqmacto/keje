"use client";

import { useState } from "react";
import useSWR from "swr";
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

type Note = {
    id: number;
    title: string;
    body: string | null;
    created_at: string;
};

const schema = z.object({
    title: z.string().min(1, "Title is required.").max(255),
    body: z.string().max(10000),
});

type FormValues = z.infer<typeof schema>;

const listNotes = async (): Promise<Note[]> => {
    const { data } = await api.get<{ data: Note[] }>("/notes");
    return data.data;
};

export default function NotesClient() {
    const { data: notes, isLoading, mutate } = useSWR("notes", listNotes);
    const [submitting, setSubmitting] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { title: "", body: "" },
    });

    async function onCreate(values: FormValues) {
        setSubmitting(true);
        try {
            await api.post("/notes", values);
            form.reset({ title: "", body: "" });
            await mutate();
            toast.success("Note created.");
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
            const first = axiosErr.response?.data?.errors
                ? Object.values(axiosErr.response.data.errors).flat()[0]
                : undefined;
            toast.error(first ?? axiosErr.response?.data?.message ?? "Could not create note.");
        } finally {
            setSubmitting(false);
        }
    }

    async function onDelete(id: number) {
        // Optimistic: remove from list, roll back on error.
        const previous = notes;
        await mutate(previous?.filter((n) => n.id !== id), false);
        try {
            await api.delete(`/notes/${id}`);
            toast.success("Note deleted.");
        } catch {
            await mutate(previous, false);
            toast.error("Could not delete note.");
        }
    }

    return (
        <section className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-12">
            <div className="flex flex-col gap-2">
                <span className="text-xs uppercase tracking-widest text-muted-foreground">
                    Example resource
                </span>
                <h1 className="text-3xl font-semibold tracking-tight">Notes</h1>
                <p className="text-muted-foreground">
                    A tiny demo resource that shows the end-to-end CRUD pattern: list via SWR, create
                    via <code className="font-mono">api.post</code>, delete with optimistic update.
                    Safe to remove.
                </p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>New note</CardTitle>
                    <CardDescription>Stored against your account.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onCreate)} className="flex flex-col gap-4">
                            <FormField
                                control={form.control}
                                name="title"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Title</FormLabel>
                                        <FormControl>
                                            <Input placeholder="Grocery list" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="body"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Body</FormLabel>
                                        <FormControl>
                                            <Input placeholder="Optional details" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <Button type="submit" disabled={submitting} className="self-start">
                                {submitting ? "Saving…" : "Add note"}
                            </Button>
                        </form>
                    </Form>
                </CardContent>
            </Card>

            <div className="flex flex-col gap-3">
                {isLoading && (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                )}
                {!isLoading && notes && notes.length === 0 && (
                    <p className="text-sm text-muted-foreground">No notes yet.</p>
                )}
                {notes?.map((note) => (
                    <Card key={note.id}>
                        <CardHeader className="flex flex-row items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-lg">{note.title}</CardTitle>
                                {note.body && (
                                    <CardDescription className="mt-1 whitespace-pre-wrap">
                                        {note.body}
                                    </CardDescription>
                                )}
                            </div>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => void onDelete(note.id)}
                            >
                                Delete
                            </Button>
                        </CardHeader>
                    </Card>
                ))}
            </div>
        </section>
    );
}
