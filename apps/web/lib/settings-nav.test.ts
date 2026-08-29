/**
 * Tests for the Settings section resolver.
 *
 * Node's built-in runner, no test framework. Run with:
 *
 *     npm run -w apps/web test
 */

import { test, describe } from "node:test";
import assert from "node:assert/strict";
import {
    SETTINGS_SECTIONS,
    activeSettingsHref,
    activeSettingsSection,
} from "./settings-nav.ts";

describe("activeSettingsHref", () => {
    test("resolves the Account section at the settings root", () => {
        assert.equal(activeSettingsHref("/settings"), "/settings");
    });

    test("resolves the Integrations section", () => {
        assert.equal(activeSettingsHref("/settings/integrations"), "/settings/integrations");
    });

    test("does not also match Account on a sub-section", () => {
        // "/settings" prefixes every section, so a naive startsWith would mark
        // Account active on the Integrations page and light up two tabs.
        assert.notEqual(activeSettingsHref("/settings/integrations"), "/settings");
    });

    test("keeps the section active on a deeper nested route", () => {
        assert.equal(
            activeSettingsHref("/settings/integrations/google"),
            "/settings/integrations",
        );
    });

    test("does not match a sibling route that merely shares a prefix", () => {
        // "/settings-other" starts with "/settings" as a string but is a
        // different route, hence the boundary-aware check.
        assert.equal(activeSettingsHref("/settings-other"), SETTINGS_SECTIONS[0].href);
    });

    test("falls back to the first section for an unknown path", () => {
        assert.equal(activeSettingsHref("/studio"), SETTINGS_SECTIONS[0].href);
        assert.equal(activeSettingsHref(""), SETTINGS_SECTIONS[0].href);
    });

    test("exactly one section is active for every section's own route", () => {
        for (const section of SETTINGS_SECTIONS) {
            const active = activeSettingsHref(section.href);
            assert.equal(active, section.href, section.href);
            assert.equal(
                SETTINGS_SECTIONS.filter((s) => s.href === active).length,
                1,
                section.href,
            );
        }
    });
});

describe("activeSettingsSection", () => {
    test("returns the section record, for headings and breadcrumbs", () => {
        assert.equal(activeSettingsSection("/settings").label, "Account");
        assert.equal(activeSettingsSection("/settings/integrations").label, "Integrations");
    });

    test("Integrations is described the way the README points at it", () => {
        // README says: Settings → Integrations → Connect Google.
        const integrations = activeSettingsSection("/settings/integrations");
        assert.equal(integrations.label, "Integrations");
        assert.equal(integrations.description, "Google Drive and YouTube");
    });
});

describe("SETTINGS_SECTIONS", () => {
    test("starts with the broadest route, which is the fallback", () => {
        assert.equal(SETTINGS_SECTIONS[0].href, "/settings");
    });

    test("every section is under /settings and uniquely addressed", () => {
        const hrefs = SETTINGS_SECTIONS.map((s) => s.href);
        assert.equal(new Set(hrefs).size, hrefs.length);

        for (const href of hrefs) {
            assert.ok(href === "/settings" || href.startsWith("/settings/"), href);
        }
    });

    test("every section has a label and a description to render", () => {
        for (const section of SETTINGS_SECTIONS) {
            assert.ok(section.label.length > 0, section.href);
            assert.ok(section.description.length > 0, section.href);
        }
    });
});
