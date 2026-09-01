import { Suspense } from "react";
import StudioListClient from "./StudioListClient";

export const metadata = { title: "Content Studio" };

export default function StudioPage() {
    /*
     * The list reads its filters, sort and page from the URL, and
     * `useSearchParams` suspends during prerender because those values are not
     * known until a real request arrives. Without a boundary here Next.js
     * bails out of prerendering the whole route; with one, the shell is static
     * and only the table waits.
     */
    return (
        <Suspense fallback={null}>
            <StudioListClient />
        </Suspense>
    );
}
