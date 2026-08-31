import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { formatDocumentTitle } from "./document-title.ts";

/**
 * The same shape the root layout's `template` produces, so a page that sets
 * its title in the browser matches one that sets it in metadata.
 */
describe("formatDocumentTitle", () => {
    it("prefixes the app name", () => {
        assert.equal(formatDocumentTitle("Content Studio", "Keje"), "Keje | Content Studio");
    });

    it("uses a project's working title verbatim", () => {
        assert.equal(
            formatDocumentTitle("Keutamaan Lapar, Hidup Sederhana", "Keje"),
            "Keje | Keutamaan Lapar, Hidup Sederhana",
        );
    });

    it("does not repeat the app name when that is the title", () => {
        assert.equal(formatDocumentTitle("Keje", "Keje"), "Keje");
    });

    it("falls back to the app name alone for an empty title", () => {
        assert.equal(formatDocumentTitle("   ", "Keje"), "Keje");
    });

    it("follows a renamed deployment", () => {
        // Derived from APP_NAME, never a literal.
        assert.equal(formatDocumentTitle("Dashboard", "Kajian"), "Kajian | Dashboard");
    });
});
