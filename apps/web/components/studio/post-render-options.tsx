"use client";

import Link from "next/link";
import { useGoogleIntegrations } from "@/components/studio/youtube-selectors";

export type PostRenderActions = {
    drive_backup: boolean;
    youtube_upload: boolean;
};

/**
 * What happens once the render finishes.
 *
 * Offered before the render rather than after, because that is the moment
 * someone is deciding what this video is for — and because a long encode is
 * exactly the thing you want to walk away from.
 *
 * A destination that is not connected is shown disabled with the way to fix
 * it, not hidden: a missing checkbox reads as a missing feature.
 */
export function PostRenderOptions({
    value,
    onChange,
    disabled = false,
}: {
    value: PostRenderActions;
    onChange: (next: PostRenderActions) => void;
    disabled?: boolean;
}) {
    const { data: integrations } = useGoogleIntegrations();

    const driveConnected = integrations?.drive.connected ?? false;
    const youtubeConnected = integrations?.youtube.connected ?? false;
    // A wrong channel blocks uploads server-side, so offering the checkbox
    // would only queue a job that cannot succeed.
    const channelMismatch = integrations?.youtube.channel_matches_expected === false;
    const canUpload = youtubeConnected && !channelMismatch;

    return (
        <fieldset className="flex flex-col gap-2 rounded-md border px-3 py-2">
            <legend className="px-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                After render
            </legend>

            <Option
                label="Back up to Google Drive"
                checked={value.drive_backup && driveConnected}
                disabled={disabled || !driveConnected}
                onChange={(checked) => onChange({ ...value, drive_backup: checked })}
                hint={
                    driveConnected ? null : (
                        <>
                            <Link href="/settings/integrations" className="underline">
                                Connect Google Drive
                            </Link>{" "}
                            to back up automatically.
                        </>
                    )
                }
            />

            <Option
                label="Upload to YouTube"
                checked={value.youtube_upload && canUpload}
                disabled={disabled || !canUpload}
                onChange={(checked) => onChange({ ...value, youtube_upload: checked })}
                hint={
                    canUpload ? null : channelMismatch ? (
                        "This account controls a different channel, so uploads are blocked."
                    ) : (
                        <>
                            <Link href="/settings/integrations" className="underline">
                                Connect YouTube
                            </Link>{" "}
                            to publish automatically.
                        </>
                    )
                }
            />

            {/* The three pipelines stay independent: neither of these can turn
                a good render into a failed one. */}
            <p className="text-xs text-muted-foreground">
                Each runs on its own after the render succeeds. A Drive or YouTube problem never
                fails the render itself.
            </p>
        </fieldset>
    );
}

function Option({
    label,
    checked,
    disabled,
    onChange,
    hint,
}: {
    label: string;
    checked: boolean;
    disabled: boolean;
    onChange: (checked: boolean) => void;
    hint: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    className="size-4 rounded border-input"
                    checked={checked}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.checked)}
                />
                <span className={disabled ? "text-muted-foreground" : undefined}>{label}</span>
            </label>
            {hint && <p className="pl-6 text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}
