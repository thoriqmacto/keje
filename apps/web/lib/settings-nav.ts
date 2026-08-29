/**
 * The Settings area's sections.
 *
 * Single source of truth: adding an entry here gives it a tab in the settings
 * sub-navigation *and* a card on the Account page, with no other edits. Keep
 * the broadest route first — it is the fallback when nothing else matches.
 */

export type SettingsSection = {
    href: string;
    label: string;
    /** Shown under the heading and on the section's card. Keep it short. */
    description: string;
};

export const SETTINGS_SECTIONS: SettingsSection[] = [
    {
        href: "/settings",
        label: "Account",
        description: "Your profile, email and password.",
    },
    {
        href: "/settings/integrations",
        label: "Integrations",
        description: "Google Drive and YouTube",
    },
];

/**
 * Which section a pathname belongs to.
 *
 * The longest match wins, because "/settings" is a prefix of every other
 * section — a naive `startsWith` would light up the Account tab on every
 * settings page. Falls back to the first section for an unknown path.
 */
export function activeSettingsHref(pathname: string): string {
    const matches = SETTINGS_SECTIONS.filter(
        ({ href }) => pathname === href || pathname.startsWith(`${href}/`),
    );

    if (matches.length === 0) {
        return SETTINGS_SECTIONS[0].href;
    }

    return matches.reduce((longest, section) =>
        section.href.length > longest.href.length ? section : longest,
    ).href;
}

/** The section a pathname belongs to, for headings and breadcrumbs. */
export function activeSettingsSection(pathname: string): SettingsSection {
    const href = activeSettingsHref(pathname);

    return SETTINGS_SECTIONS.find((section) => section.href === href) ?? SETTINGS_SECTIONS[0];
}
