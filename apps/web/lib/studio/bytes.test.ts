import assert from "node:assert/strict";
import { test } from "node:test";
import { formatBytes } from "./bytes.ts";

test("nothing reads as zero rather than NaN", () => {
    assert.equal(formatBytes(0), "0 B");
    assert.equal(formatBytes(-1), "0 B");
    assert.equal(formatBytes(Number.NaN), "0 B");
});

test("whole bytes carry no decimal", () => {
    assert.equal(formatBytes(512), "512 B");
});

test("sizes use base 1024, matching what the server reports", () => {
    // A decimal megabyte here would disagree with du -h on the same file,
    // which is the tool somebody reaches for to check this page.
    assert.equal(formatBytes(1024), "1.0 KB");
    assert.equal(formatBytes(1024 * 1024), "1.0 MB");
    assert.equal(formatBytes(1024 * 1024 * 1024), "1.0 GB");
});

test("large values drop the decimal that implied false precision", () => {
    assert.equal(formatBytes(1024 * 1024 * 512), "512 MB");
});
