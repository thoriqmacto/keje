"use client";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { isoToLocalInput } from "@/lib/studio/format";
import type { PrivacyStatus, YouTubeMetadata } from "@/lib/types/studio";

/**
 * Step 5 — YouTube metadata.
 *
 * Separate from the visual title on purpose: the video shows a short two-part
 * title, while YouTube wants one long descriptive line. This form can prefill
 * from the visual fields, but the two never share state.
 */
export function YouTubeMetadataForm({
    metadata,
    saving,
    onChange,
    onSave,
    onPrefill,
}: {
    metadata: YouTubeMetadata;
    saving: boolean;
    onChange: (patch: Partial<YouTubeMetadata>) => void;
    onSave: () => void;
    onPrefill: () => void;
}) {
    const privacy = metadata.privacy_status ?? "private";

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between gap-2">
                    <Label htmlFor="yt_title">YouTube title</Label>
                    <Button type="button" size="sm" variant="ghost" onClick={onPrefill}>
                        Prefill from video title
                    </Button>
                </div>
                <Input
                    id="yt_title"
                    maxLength={100}
                    placeholder="Keutamaan Lapar, Hidup Sederhana | Riyadhush Shalihin #11 | Part 3"
                    value={metadata.title ?? ""}
                    onChange={(event) => onChange({ title: event.target.value })}
                />
                <p className="text-xs text-muted-foreground">
                    {(metadata.title ?? "").length}/100 characters. Independent of the on-screen
                    title.
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <Label htmlFor="yt_description">Description</Label>
                <textarea
                    id="yt_description"
                    rows={5}
                    maxLength={5000}
                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    value={metadata.description ?? ""}
                    onChange={(event) => onChange({ description: event.target.value })}
                />
            </div>

            <div className="flex flex-col gap-2">
                <Label htmlFor="yt_tags">Tags</Label>
                <Input
                    id="yt_tags"
                    placeholder="kajian, tematik, riyadhush shalihin"
                    value={(metadata.tags ?? []).join(", ")}
                    onChange={(event) =>
                        onChange({
                            tags: event.target.value
                                .split(",")
                                .map((tag) => tag.trim())
                                .filter(Boolean),
                        })
                    }
                />
                <p className="text-xs text-muted-foreground">Comma separated.</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="yt_category">Category ID</Label>
                    <Input
                        id="yt_category"
                        placeholder="27"
                        value={metadata.category_id ?? ""}
                        onChange={(event) => onChange({ category_id: event.target.value })}
                    />
                    <p className="text-xs text-muted-foreground">27 is Education.</p>
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="yt_privacy">Privacy</Label>
                    <select
                        id="yt_privacy"
                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        value={privacy}
                        onChange={(event) =>
                            onChange({ privacy_status: event.target.value as PrivacyStatus })
                        }
                    >
                        <option value="private">Private</option>
                        <option value="unlisted">Unlisted</option>
                        <option value="public">Public</option>
                    </select>
                </div>
            </div>

            <div className="flex flex-col gap-2">
                <Label htmlFor="yt_publish_at">Schedule publication</Label>
                <Input
                    id="yt_publish_at"
                    type="datetime-local"
                    value={isoToLocalInput(metadata.publish_at)}
                    onChange={(event) =>
                        onChange({
                            // Stored as an absolute instant; the input is local time.
                            publish_at: event.target.value
                                ? new Date(event.target.value).toISOString()
                                : null,
                        })
                    }
                    className="max-w-64"
                />
                <p className="text-xs text-muted-foreground">
                    Uploads as private with a publish time, and YouTube makes it public itself.
                    Times are shown in your local timezone.
                </p>
            </div>

            <div className="flex flex-col gap-2">
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={metadata.made_for_kids ?? false}
                        onChange={(event) => onChange({ made_for_kids: event.target.checked })}
                    />
                    Made for kids
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={metadata.notify_subscribers ?? false}
                        onChange={(event) => onChange({ notify_subscribers: event.target.checked })}
                    />
                    Notify subscribers
                </label>
            </div>

            <Button onClick={onSave} disabled={saving} className="self-start">
                {saving ? "Saving…" : "Save YouTube metadata"}
            </Button>
        </div>
    );
}
