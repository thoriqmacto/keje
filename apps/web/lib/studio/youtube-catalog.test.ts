import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
    catalogOptions,
    filterByTitle,
    playlistState,
    resolvePlaylistDestination,
    resolveTitle,
} from "./youtube-catalog.ts";

const playlist = (id: string, title: string | null, itemCount = 0) => ({
    id,
    title,
    description: null,
    thumbnail_url: null,
    item_count: itemCount,
    privacy_status: "public",
    published_at: null,
});

describe("resolvePlaylistDestination", () => {
    it("uses the topic's playlist when the project sets none", () => {
        assert.deepEqual(resolvePlaylistDestination(null, "PLtopic"), {
            playlistId: "PLtopic",
            inheritedFromTopic: true,
        });
    });

    it("lets a project override the topic without changing the topic", () => {
        assert.deepEqual(resolvePlaylistDestination("PLproject", "PLtopic"), {
            playlistId: "PLproject",
            inheritedFromTopic: false,
        });
    });

    it("resolves to nothing when neither is set", () => {
        assert.deepEqual(resolvePlaylistDestination(undefined, undefined), {
            playlistId: null,
            inheritedFromTopic: false,
        });
    });

    it("treats an empty override as absent rather than as a choice", () => {
        assert.deepEqual(resolvePlaylistDestination("", "PLtopic"), {
            playlistId: "PLtopic",
            inheritedFromTopic: true,
        });
    });
});

describe("filterByTitle", () => {
    const playlists = [playlist("PL1", "Kajian Tematik"), playlist("PL2", "Tafsir"), playlist("PL3", null)];

    it("returns everything for an empty search", () => {
        assert.equal(filterByTitle(playlists, "   ").length, 3);
    });

    it("matches case-insensitively on a substring", () => {
        assert.deepEqual(
            filterByTitle(playlists, "tema").map((p) => p.id),
            ["PL1"],
        );
    });

    it("does not throw on a playlist with no title", () => {
        assert.deepEqual(filterByTitle(playlists, "zzz"), []);
    });
});

describe("catalogOptions", () => {
    const label = (p: { title: string | null; item_count: number }) =>
        `${p.title} · ${p.item_count} videos`;

    it("lists what the catalog returned", () => {
        const options = catalogOptions([playlist("PL1", "Kajian", 4)], label, null);

        assert.deepEqual(options, [{ value: "PL1", label: "Kajian · 4 videos", unknown: false }]);
    });

    it("keeps a stored id the catalog does not list", () => {
        const options = catalogOptions([playlist("PL1", "Kajian")], label, "PLgone", {
            unknownLabel: (id) => `${id} (not in this channel)`,
        });

        assert.equal(options.length, 2);
        assert.deepEqual(options[0], {
            value: "PLgone",
            label: "PLgone (not in this channel)",
            unknown: true,
        });
    });

    it("keeps a stored id when the catalog failed and came back empty", () => {
        const options = catalogOptions([], label, "PLstored");

        assert.deepEqual(options, [{ value: "PLstored", label: "PLstored", unknown: true }]);
    });

    it("does not claim an id is missing while the catalog is still loading", () => {
        assert.deepEqual(catalogOptions([], label, "PLstored", { loading: true }), []);
    });

    it("does not duplicate a stored id the catalog does list", () => {
        const options = catalogOptions([playlist("PL1", "Kajian")], label, "PL1");

        assert.equal(options.length, 1);
        assert.equal(options[0].unknown, false);
    });

    it("adds no synthetic entry when nothing is selected", () => {
        assert.deepEqual(catalogOptions([], label, null), []);
    });
});

describe("resolveTitle", () => {
    const categories = [
        { id: "27", title: "Education" },
        { id: "22", title: "People & Blogs" },
    ];

    it("resolves a stored id to its name", () => {
        assert.equal(resolveTitle(categories, "27", "Not set"), "Education");
    });

    it("falls back to the raw id when the catalog cannot resolve it", () => {
        assert.equal(resolveTitle(categories, "99", "Not set"), "99");
    });

    it("falls back to the raw id when the catalog is unavailable", () => {
        assert.equal(resolveTitle(undefined, "27", "Not set"), "27");
    });

    it("uses the placeholder only when nothing is stored", () => {
        assert.equal(resolveTitle(categories, null, "Not set"), "Not set");
    });
});

describe("playlistState", () => {
    it("reports a successful assignment", () => {
        assert.equal(
            playlistState({ itemId: "PLI123", error: null, canManagePlaylists: true }),
            "assigned",
        );
    });

    it("offers a retry when the grant allows playlist management", () => {
        assert.equal(
            playlistState({ itemId: null, error: "Playlist not found.", canManagePlaylists: true }),
            "failed_can_retry",
        );
    });

    it("asks for a reconnect when the grant predates playlist permission", () => {
        assert.equal(
            playlistState({ itemId: null, error: "Insufficient permission.", canManagePlaylists: false }),
            "failed_needs_scope",
        );
    });

    it("says nothing when no playlist was ever requested", () => {
        assert.equal(
            playlistState({ itemId: null, error: null, canManagePlaylists: true }),
            "none",
        );
    });

    it("prefers a recorded assignment over a stale error", () => {
        assert.equal(
            playlistState({ itemId: "PLI123", error: "old failure", canManagePlaylists: false }),
            "assigned",
        );
    });
});
