"use client";

import { useEffect } from "react";
import { APP_NAME } from "@/lib/env";
import { formatDocumentTitle } from "@/lib/document-title";

/**
 * Set the tab title from data only the browser can fetch.
 *
 * Project titles live behind a bearer token, which a server `generateMetadata`
 * cannot read, so those pages render with a static fallback and refine the
 * title once the project arrives. Doing it in an effect rather than during
 * render keeps server and client markup identical — writing to
 * `document.title` while rendering is a hydration mismatch waiting to happen.
 *
 * Pass null while loading and the page keeps whatever its `metadata` set.
 */
export function useDocumentTitle(title: string | null | undefined) {
    useEffect(() => {
        if (!title) return;

        const previous = document.title;
        document.title = formatDocumentTitle(title, APP_NAME);

        // Restore on unmount so a back-navigation does not briefly show the
        // title of the page being left.
        return () => {
            document.title = previous;
        };
    }, [title]);
}
