import { spawn } from "node:child_process";
import { color } from "./log.mjs";

export function run(cmd, args = [], options = {}) {
    const label = `${cmd} ${args.join(" ")}`.trim();
    console.log(`${color.dim("$")} ${label}`);
    return new Promise((resolve, reject) => {
        const child = spawn(cmd, args, {
            stdio: options.stdio ?? "inherit",
            cwd: options.cwd ?? process.cwd(),
            env: { ...process.env, ...(options.env ?? {}) },
            shell: false,
        });
        child.on("error", reject);
        child.on("close", (code) => {
            if (code === 0) resolve();
            else if (options.allowFail) resolve(code);
            else reject(new Error(`${label} exited with code ${code}`));
        });
    });
}

export function which(cmd) {
    return new Promise((resolve) => {
        const child = spawn(process.platform === "win32" ? "where" : "which", [cmd], {
            stdio: ["ignore", "pipe", "ignore"],
        });
        let out = "";
        child.stdout.on("data", (d) => (out += d.toString()));
        child.on("close", (code) => {
            resolve(code === 0 ? out.trim().split(/\r?\n/)[0] : null);
        });
        child.on("error", () => resolve(null));
    });
}

export function capture(cmd, args = [], options = {}) {
    return new Promise((resolve) => {
        const child = spawn(cmd, args, {
            stdio: ["ignore", "pipe", "pipe"],
            cwd: options.cwd ?? process.cwd(),
            env: { ...process.env, ...(options.env ?? {}) },
            shell: false,
        });
        let stdout = "";
        let stderr = "";
        child.stdout.on("data", (d) => (stdout += d.toString()));
        child.stderr.on("data", (d) => (stderr += d.toString()));
        child.on("error", () => resolve({ code: -1, stdout, stderr }));
        child.on("close", (code) => resolve({ code, stdout, stderr }));
    });
}
