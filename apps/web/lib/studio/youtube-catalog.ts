/**
 * The decisions the YouTube choosers make, kept out of the components.
 *
 * These are the rules that actually matter for correctness — which playlist a
 * project publishes to, and whether a stored id survives a catalog that could
 * not be loaded — so they live here where they can be tested without a DOM.
 *
 * The single rule underneath all of it: an id Keje already stored is never
 * dropped because Google could not be reached. Losing a destination silently is
 * worse than showing a raw id.
 */

/** One entry in a chooser. `unknown` marks an id the catalog did not list. */
export type CatalogOption = {
    value: string;
    label: string;
    unknown: boolean;
};

type Identified = { id: string };
type Titled = Identified & { title?: string | null };

/**
 * Where a project's video will actually be added.
 *
 * Project override wins, then the topic's standing playlist — the same
 * precedence YouTubePlaylistAssigner applies server-side at upload time. Both
 * sides must agree or the destination shown before upload is a lie.
 */
export function resolvePlaylistDestination(
    projectPlaylistId: string | null | undefined,
    topicPlaylistId: string | null | undefined,
): { playlistId: string | null; inheritedFromTopic: boolean } {
    if (projectPlaylistId) {
        return { playlistId: projectPlaylistId, inheritedFromTopic: false };
    }

    if (topicPlaylistId) {
        return { playlistId: topicPlaylistId, inheritedFromTopic: true };
    }

    return { playlistId: null, inheritedFromTopic: false };
}

/**
 * Client-side title filter.
 *
 * The list is already loaded and capped at 50 by the API, so refetching per
 * keystroke would spend YouTube quota for nothing.
 */
export function filterByTitle<T extends Titled>(items: T[], search: string): T[] {
    const needle = search.trim().toLowerCase();

    if (!needle) return items;

    return items.filter((item) => (item.title ?? "").toLowerCase().includes(needle));
}

/**
 * Options for a chooser, preserving a selected id the catalog does not list.
 *
 * A playlist deleted since it was chosen, a category not assignable in this
 * region, or simply a catalog that failed to load: in each case the stored id
 * is still the truth, so it stays selectable and `unknown` explains why it
 * looks different. While `loading` is true nothing is claimed to be missing —
 * the list has not arrived yet.
 */
export function catalogOptions<T extends Identified>(
    items: T[],
    label: (item: T) => string,
    selected: string | null | undefined,
    options: { loading?: boolean; unknownLabel?: (id: string) => string } = {},
): CatalogOption[] {
    const { loading = false, unknownLabel = (id: string) => id } = options;

    const listed = items.map((item) => ({
        value: item.id,
        label: label(item),
        unknown: false,
    }));

    if (!selected || loading || listed.some((option) => option.value === selected)) {
        return listed;
    }

    return [{ value: selected, label: unknownLabel(selected), unknown: true }, ...listed];
}

/** The human name for a stored id, falling back to the id, then to a placeholder. */
export function resolveTitle<T extends Titled>(
    items: T[] | undefined,
    id: string | null | undefined,
    fallback: string,
): string {
    if (!id) return fallback;

    return items?.find((item) => item.id === id)?.title || id;
}

/**
 * What the project detail page should offer about playlist membership.
 *
 * A failed assignment must never be answered by uploading again — the video
 * already exists on YouTube. So the only offer here is a retry of
 * playlistItems.insert, and only when the grant actually allows it.
 */
export type PlaylistState =
    | "none"
    | "assigned"
    | "failed_can_retry"
    | "failed_needs_scope";

export function playlistState(input: {
    itemId: string | null | undefined;
    error: string | null | undefined;
    canManagePlaylists: boolean;
}): PlaylistState {
    if (input.itemId) return "assigned";
    if (!input.error) return "none";

    return input.canManagePlaylists ? "failed_can_retry" : "failed_needs_scope";
}
