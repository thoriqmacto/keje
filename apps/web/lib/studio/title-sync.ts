/**
 * One title, serving two purposes, until it needs to serve two.
 *
 * A project has a working title — what it is called in the Studio — and a
 * YouTube title, which is what the world sees. For most videos they are the
 * same sentence, and typing it twice is the kind of small tax that is paid on
 * every single project forever.
 *
 * So they start joined. The checkbox is on by default, one field fills both,
 * and the pair only comes apart when somebody actually wants a different
 * public title from their internal one.
 *
 * ── Why `custom` is remembered while synced ─────────────────────────────
 *
 * The obvious model overwrites the YouTube title the moment the box is
 * ticked. That makes the checkbox destructive: tick it to see what the
 * synced version would look like, and the sentence you wrote is gone with no
 * undo. Keeping the typed value aside and *deriving* what is shown means
 * ticking and unticking is free, which is the only way somebody will try it.
 *
 * ── Why both are capped at YouTube's limit ──────────────────────────────
 *
 * YouTube truncates past 100 characters. A working title longer than that
 * cannot be mirrored without producing a YouTube title the API would reject,
 * so the shared field has to obey the stricter of the two rules — otherwise
 * the checkbox would silently be a lie for long titles.
 */

/** YouTube's own limit. Applies to both fields, because either can become one. */
export const YOUTUBE_TITLE_LIMIT = 100;

export type TitleState = {
    /** What the project is called in the Studio. */
    working: string;
    /** What was typed into the YouTube field. Held, but unused while synced. */
    custom: string;
    /** Whether the YouTube title mirrors the working title. */
    synced: boolean;
};

export const EMPTY_TITLES: TitleState = { working: "", custom: "", synced: true };

/** Trim to the limit rather than refuse: a paste should land, just shorter. */
function clamp(value: string): string {
    return value.slice(0, YOUTUBE_TITLE_LIMIT);
}

export function setWorking(state: TitleState, working: string): TitleState {
    return { ...state, working: clamp(working) };
}

export function setCustom(state: TitleState, custom: string): TitleState {
    return { ...state, custom: clamp(custom) };
}

/**
 * Tick or untick the box.
 *
 * Unticking seeds the field with whatever is on screen, so editing starts
 * from the synced sentence rather than from an empty box — changing one word
 * of a title is the common case, and retyping it is not.
 */
export function setSynced(state: TitleState, synced: boolean): TitleState {
    if (synced || state.custom !== "") {
        return { ...state, synced };
    }

    return { ...state, synced, custom: state.working };
}

/** The title YouTube would receive right now. */
export function youtubeTitle(state: TitleState): string {
    return state.synced ? state.working : state.custom;
}

/**
 * What to store in `youtube_metadata.title`, or nothing at all.
 *
 * An empty string is not a title, and storing one would make the upload send
 * a blank where the builder's fallback — the project's own naming — is what
 * anybody would expect.
 */
export function youtubeTitleForMetadata(state: TitleState): string | null {
    const title = youtubeTitle(state).trim();

    return title === "" ? null : title;
}

/** How much room is left, for a counter that appears before it matters. */
export function remaining(value: string): number {
    return YOUTUBE_TITLE_LIMIT - value.length;
}
