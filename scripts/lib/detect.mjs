import { capture, which } from "./run.mjs";

export async function detectTools() {
    const nodePath = process.execPath;
    const [phpPath, composerPath, npmPath] = await Promise.all([
        which("php"),
        which("composer"),
        which("npm"),
    ]);

    const [phpVer, composerVer, npmVer] = await Promise.all([
        phpPath ? capture(phpPath, ["-r", "echo PHP_VERSION;"]) : null,
        composerPath ? capture(composerPath, ["--version"]) : null,
        npmPath ? capture(npmPath, ["--version"]) : null,
    ]);

    return {
        node: { path: nodePath, version: process.versions.node },
        php: phpPath ? { path: phpPath, version: phpVer?.stdout?.trim() ?? "unknown" } : null,
        composer: composerPath
            ? { path: composerPath, version: composerVer?.stdout?.trim() ?? "unknown" }
            : null,
        npm: npmPath ? { path: npmPath, version: npmVer?.stdout?.trim() ?? "unknown" } : null,
        platform: process.platform,
    };
}

export function nodeVersionIsAtLeast(major) {
    const [maj] = process.versions.node.split(".").map(Number);
    return maj >= major;
}
