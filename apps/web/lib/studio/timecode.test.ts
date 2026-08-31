import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { formatTimecode, parseTimecode } from "./timecode.ts";

describe("formatTimecode", () => {
    it("renders minutes and seconds with hundredths", () => {
        assert.equal(formatTimecode(18), "00:18.00");
        assert.equal(formatTimecode(1122.5), "18:42.50");
    });

    it("adds hours only once there are hours", () => {
        assert.equal(formatTimecode(3600), "1:00:00.00");
        assert.equal(formatTimecode(5232.25), "1:27:12.25");
    });

    it("is safe for missing or nonsense values", () => {
        assert.equal(formatTimecode(null), "00:00.00");
        assert.equal(formatTimecode(undefined), "00:00.00");
        assert.equal(formatTimecode(-5), "00:00.00");
        assert.equal(formatTimecode(Number.NaN), "00:00.00");
    });
});

describe("parseTimecode", () => {
    it("reads plain seconds", () => {
        assert.equal(parseTimecode("18"), 18);
        assert.equal(parseTimecode("18.5"), 18.5);
    });

    it("reads mm:ss and h:mm:ss", () => {
        assert.equal(parseTimecode("00:18"), 18);
        assert.equal(parseTimecode("18:42"), 1122);
        assert.equal(parseTimecode("1:27:12"), 5232);
    });

    it("keeps a fraction on the last segment", () => {
        assert.equal(parseTimecode("18:42.5"), 1122.5);
    });

    it("rejects a segment that is not a time", () => {
        // "1:75" is a typo, not 2:15 — reading it generously would cut
        // somewhere the person never chose.
        assert.equal(parseTimecode("1:75"), null);
        assert.equal(parseTimecode("abc"), null);
        assert.equal(parseTimecode(""), null);
        assert.equal(parseTimecode("  "), null);
        assert.equal(parseTimecode("1:2:3:4"), null);
        assert.equal(parseTimecode("-5"), null);
        assert.equal(parseTimecode("."), null);
    });

    it("round-trips what the formatter produced", () => {
        for (const seconds of [0, 18, 1122.5, 5232.25]) {
            assert.equal(parseTimecode(formatTimecode(seconds)), seconds);
        }
    });
});
