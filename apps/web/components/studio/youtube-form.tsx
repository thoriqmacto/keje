"use client";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { isoToLocalInput } from "@/lib/studio/format";
import {
    YouTubeCategorySelector,
    YouTubeLanguageSelector,
    YouTubePlaylistSelector,
} from "@/components/studio/youtube-selectors";
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
    topicPlaylistTitle,
}: {
    metadata: YouTubeMetadata;
    saving: boolean;
    onChange: (patch: Partial<YouTubeMetadata>) => void;
    onSave: () => void;
    onPrefill: () => void;
    /** Named so the "no override" option can say what it falls back to. */
    topicPlaylistTitle?: string | null;
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
                {/* Was a free-text "Category ID" box. The connected channel
                    knows which categories are assignable in its region, so
                    asking anyone to remember that 27 means Education was
                    always avoidable. The stored value is still the id. */}
                <YouTubeCategorySelector
                    value={metadata.category_id}
                    onChange={(categoryId) => onChange({ category_id: categoryId })}
                />

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

            <div className="grid gap-4 sm:grid-cols-2">
                <YouTubePlaylistSelector
                    value={metadata.playlist_id}
                    inheritedFrom={topicPlaylistTitle}
                    onChange={(playlistId) => onChange({ playlist_id: playlistId })}
                />

                <YouTubeLanguageSelector
                    value={metadata.default_language}
                    onChange={(language) => onChange({ default_language: language })}
                />
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
