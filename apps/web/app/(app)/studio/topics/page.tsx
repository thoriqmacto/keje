import { redirect } from "next/navigation";

/**
 * Topics moved to the YouTube page.
 *
 * A playlist and a local topic were always the same grouping described twice,
 * so the canonical list is now the channel's own playlists. Redirecting rather
 * than 404ing keeps existing bookmarks working — the route was in the main
 * navigation until this sprint.
 */
export default function TopicsPage() {
    redirect("/youtube");
}
