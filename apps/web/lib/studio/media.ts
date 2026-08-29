import { useEffect, useState } from "react";
import { api } from "@/lib/api";

/**
 * Fetch a private media file with the bearer token and expose it as an object
 * URL, so an <img> can display it without the token ever being in a URL.
 *
 * Only for small files — the whole body is held in memory. The rendered video
 * uses signed streaming URLs instead.
 */
export function useAuthedObjectUrl(path: string | null): string | null {
    const [url, setUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!path) {
            setUrl(null);
            return;
        }

        let revoked = false;
        let objectUrl: string | null = null;

        api.get(path, { responseType: "blob" })
            .then((response) => {
                if (revoked) return;
                objectUrl = URL.createObjectURL(response.data as Blob);
                setUrl(objectUrl);
            })
            .catch(() => setUrl(null));

        return () => {
            revoked = true;
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [path]);

    return url;
}

export type MediaLinks = {
    video_url: string | null;
    download_url: string | null;
    expires_at: string | null;
};

export async function getMediaLinks(projectId: string): Promise<MediaLinks> {
    const { data } = await api.get<{ data: MediaLinks }>(
        `/content-projects/${projectId}/media-links`,
    );
    return data.data;
}
