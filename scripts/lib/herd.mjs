import fs from "node:fs";
import path from "node:path";
import os from "node:os";

export function isMac() {
    return process.platform === "darwin";
}

export function defaultHerdRoot() {
    return path.join(os.homedir(), "Herd");
}

export function ensureSymlink(apiDir, herdRoot, projectName) {
    if (!isMac()) {
        throw new Error("Herd integration is macOS only. Skip Herd or switch to artisan serve mode.");
    }
    fs.mkdirSync(herdRoot, { recursive: true });
    const target = path.join(herdRoot, projectName);
    if (fs.existsSync(target) || fs.lstatSync(target, { throwIfNoEntry: false })) {
        const stat = fs.lstatSync(target);
        if (stat.isSymbolicLink()) {
            const current = fs.readlinkSync(target);
            if (path.resolve(current) === path.resolve(apiDir)) {
                return { target, created: false, already: true };
            }
            throw new Error(
                `Herd slot "${target}" exists and points elsewhere (${current}). Resolve manually.`,
            );
        }
        throw new Error(`Herd slot "${target}" exists and is not a symlink. Resolve manually.`);
    }
    fs.symlinkSync(apiDir, target, "dir");
    return { target, created: true, already: false };
}
