"use client";

import { useState } from "react";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";

/**
 * Step 4 — the Kajian Tematik title fields.
 *
 * The user types natural case; the template uppercases at render time. The
 * helper text states each element's hard constraint (exactly one line, at most
 * two) so a rejection later is never a surprise.
 */
export function TemplateTextForm({
    primaryTitle,
    subtitle,
    partNumber,
    layoutError,
    saving,
    onChange,
    onSave,
}: {
    primaryTitle: string;
    subtitle: string;
    partNumber: string;
    layoutError: string | null;
    saving: boolean;
    onChange: (patch: {
        primaryTitle?: string;
        subtitle?: string;
        partNumber?: string;
    }) => void;
    onSave: () => void;
}) {
    const [touched, setTouched] = useState(false);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <Label htmlFor="primary_title">Primary title</Label>
                <Input
                    id="primary_title"
                    placeholder="Keutamaan Lapar, Hidup"
                    value={primaryTitle}
                    onChange={(event) => {
                        setTouched(true);
                        onChange({ primaryTitle: event.target.value });
                    }}
                />
                <p className="text-xs text-muted-foreground">
                    Rendered uppercase on exactly one line. It shrinks to fit, and is rejected if it
                    still cannot.
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <Label htmlFor="subtitle">Supporting subtitle</Label>
                <Input
                    id="subtitle"
                    placeholder="Sederhana dan Merasa Cukup serta Mengekang Hawa Nafsu"
                    value={subtitle}
                    onChange={(event) => {
                        setTouched(true);
                        onChange({ subtitle: event.target.value });
                    }}
                />
                <p className="text-xs text-muted-foreground">
                    Wrapped automatically onto at most two balanced lines.
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <Label htmlFor="part_number">Video part</Label>
                <Input
                    id="part_number"
                    type="number"
                    min={1}
                    placeholder="3"
                    value={partNumber}
                    onChange={(event) => {
                        setTouched(true);
                        onChange({ partNumber: event.target.value });
                    }}
                    className="max-w-32"
                />
                <p className="text-xs text-muted-foreground">
                    {partNumber ? (
                        <>
                            Renders as <span className="font-mono">~ PART-{partNumber} ~</span>.
                        </>
                    ) : (
                        "Optional — leave blank to omit the part line entirely."
                    )}
                </p>
            </div>

            {layoutError && (
                <p className="rounded-md bg-red-500/10 px-3 py-2 text-sm text-red-600 dark:text-red-400">
                    {layoutError}
                </p>
            )}

            <Button onClick={onSave} disabled={saving || !touched} className="self-start">
                {saving ? "Saving…" : "Save title information"}
            </Button>
        </div>
    );
}
