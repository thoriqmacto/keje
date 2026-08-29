"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import {
    SETTINGS_SECTIONS,
    activeSettingsHref,
    activeSettingsSection,
} from "@/lib/settings-nav";

/**
 * Tabs across the Settings sections.
 *
 * Horizontally scrollable rather than wrapping, so the row stays one line on a
 * narrow screen and still works once Settings grows past two sections.
 */
export function SettingsNav() {
    const pathname = usePathname();
    const active = activeSettingsHref(pathname);

    return (
        <nav aria-label="Settings sections" className="-mb-px overflow-x-auto">
            <ul className="flex min-w-max items-center gap-1 border-b">
                {SETTINGS_SECTIONS.map((section) => {
                    const isActive = section.href === active;

                    return (
                        <li key={section.href}>
                            <Link
                                href={section.href}
                                aria-current={isActive ? "page" : undefined}
                                className={`inline-block whitespace-nowrap border-b-2 px-3 py-2 text-sm transition-colors ${
                                    isActive
                                        ? "border-foreground font-medium text-foreground"
                                        : "border-transparent text-muted-foreground hover:border-muted-foreground/40 hover:text-foreground"
                                }`}
                            >
                                {section.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/**
 * Shared heading for every Settings page: breadcrumb, title, section tabs.
 *
 * The breadcrumb's "Settings" crumb links back to Account, which doubles as
 * the way out of a sub-section.
 */
export function SettingsHeader({ description }: { description?: string }) {
    const pathname = usePathname();
    const section = activeSettingsSection(pathname);
    const isRoot = section.href === SETTINGS_SECTIONS[0].href;

    return (
        <div className="flex flex-col gap-4">
            <nav aria-label="Breadcrumb">
                <ol className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <li>
                        {isRoot ? (
                            <span>Settings</span>
                        ) : (
                            <Link href={SETTINGS_SECTIONS[0].href} className="hover:text-foreground">
                                Settings
                            </Link>
                        )}
                    </li>
                    <li aria-hidden="true">/</li>
                    <li className="font-medium text-foreground">{section.label}</li>
                </ol>
            </nav>

            <div className="flex flex-col gap-1">
                <h1 className="text-3xl font-semibold tracking-tight">Settings</h1>
                <p className="text-muted-foreground">{description ?? section.description}</p>
            </div>

            <SettingsNav />
        </div>
    );
}

/**
 * Cards linking to the *other* Settings sections.
 *
 * Generated from the same list as the tabs, so a new section shows up here
 * automatically. This is what makes Integrations discoverable for someone who
 * lands on /settings and never notices the tab row.
 */
export function SettingsSectionLinks() {
    const pathname = usePathname();
    const active = activeSettingsHref(pathname);
    const others = SETTINGS_SECTIONS.filter((section) => section.href !== active);

    if (others.length === 0) return null;

    return (
        <div className="flex flex-col gap-4">
            {others.map((section) => (
                <Card key={section.href}>
                    <CardHeader>
                        <CardTitle>{section.label}</CardTitle>
                        <CardDescription>{section.description}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild variant="outline">
                            <Link href={section.href}>
                                Manage {section.label.toLowerCase()}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
