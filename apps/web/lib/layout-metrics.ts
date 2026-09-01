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

/**
 * How tall a full-height scroll region can be beneath the nav.
 *
 * `extraRem` is whatever else sits above it on the page — a heading, a
 * toolbar, the pagination below. Callers pass their own so the arithmetic
 * stays visible at the call site rather than hidden behind a name that would
 * have to be guessed at.
 */
export function scrollRegionHeight(extraRem: number): string {
    return `calc(100vh - var(${NAV_HEIGHT_VAR}) - ${extraRem}rem)`;
}
