/**
 * Measurements two separate sticky layers have to agree about.
 *
 * The app header sticks to the viewport; the Studio table's own header sticks
 * inside its scroll region. Both need to know how tall the nav is — one to
 * reserve it, the other to sit clear of it — and a number written twice is a
 * number that will eventually be written differently.
 *
 * Exported as a CSS custom property rather than a JavaScript constant used in
 * inline styles, so the value is available to `calc()` in class names and
 * never has to cross into React just to be added to something.
 */

/** The sticky app header's height. Matches `h-14` on the header itself. */
export const NAV_HEIGHT_REM = 3.5;

export const NAV_HEIGHT_VAR = "--app-nav-height";

/**
 * Applied to the layout root, so anything inside can position itself against
 * the nav without importing a number.
 */
export const layoutMetricsStyle = {
    [NAV_HEIGHT_VAR]: `${NAV_HEIGHT_REM}rem`,
} as React.CSSProperties;

/*
 * There was a scrollRegionHeight(extraRem) helper here, and it is gone on
 * purpose. It asked each caller to total up its own chrome in rem and pass
 * the answer — arithmetic that was correct on the day it was written and
 * silently wrong the next time a row was added above the table. The pages
 * that need a full-height scroll region now cap themselves at the nav-aware
 * viewport height and let a `flex-1 min-h-0` child take what is left, which
 * needs no number at all.
 */
