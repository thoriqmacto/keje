#!/usr/bin/env node
// Console setup for the monorepo starter.
// Usage:
//   node scripts/setup.mjs                 # interactive full setup
//   node scripts/setup.mjs env             # rewrite env files only
//   node scripts/setup.mjs check           # environment preflight + smoke test
//   node scripts/setup.mjs --non-interactive --mode=local [--project-name=MyApp] [--skip-deps]
//
// Stdlib only. No new runtime packages.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { ask, askBool, askChoice, closePrompt } from "./lib/prompt.mjs";
import { info, ok, warn, err, section, color } from "./lib/log.mjs";
import { run, capture } from "./lib/run.mjs";
import { detectTools, nodeVersionIsAtLeast } from "./lib/detect.mjs";
import { readEnvFile, writeEnvFile } from "./lib/env.mjs";
import { defaultHerdRoot, ensureSymlink, isMac } from "./lib/herd.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const API_DIR = path.join(ROOT, "apps", "api");
const WEB_DIR = path.join(ROOT, "apps", "web");
const API_ENV = path.join(API_DIR, ".env");
const API_ENV_EXAMPLE = path.join(API_DIR, ".env.example");
const WEB_ENV = path.join(WEB_DIR, ".env.local");
const WEB_ENV_EXAMPLE = path.join(WEB_DIR, ".env.local.example");

function parseArgs(argv) {
    const args = { flags: {}, command: null };
    for (const raw of argv) {
        if (!raw.startsWith("--")) {
            if (!args.command) args.command = raw;
            continue;
        }
        const [k, v] = raw.slice(2).split("=");
        args.flags[k] = v === undefined ? true : v;
    }
    return args;
}

async function preflight() {
    section("Preflight");
    const tools = await detectTools();
    console.log(`  node      ${tools.node.version} ${color.dim(`(${tools.node.path})`)}`);
    if (!nodeVersionIsAtLeast(20)) {
        err("Node >= 20 is required.");
        throw new Error("node-too-old");
    }
    if (tools.php) {
        console.log(`  php       ${tools.php.version} ${color.dim(`(${tools.php.path})`)}`);
    } else {
        warn("php not found — needed to run the Laravel API.");
    }
    if (tools.composer) {
        console.log(`  composer  present ${color.dim(`(${tools.composer.path})`)}`);
    } else {
        warn("composer not found — needed to install Laravel dependencies.");
    }
    if (tools.npm) {
        console.log(`  npm       ${tools.npm.version} ${color.dim(`(${tools.npm.path})`)}`);
    } else {
        warn("npm not found — needed to install web dependencies.");
    }
    console.log(`  platform  ${tools.platform}`);
    return tools;
}

function projectSlug() {
    return path.basename(ROOT).toLowerCase().replace(/[^a-z0-9-]+/g, "-");
}

function projectNameDefault() {
    // Turn the directory name into a readable title: "my-app" → "My App"
    return path.basename(ROOT)
        .replace(/[-_]+/g, " ")
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

async function promptProjectName(flags) {
    if (flags["project-name"]) return flags["project-name"];
    if (flags["non-interactive"]) return projectNameDefault();
    return ask("Project name (used for APP_NAME and browser title)", projectNameDefault());
}

async function promptMode(flags) {
    if (flags.mode === "local" || flags.mode === "remote") return flags.mode;
    if (flags["non-interactive"]) return flags.mode ?? "local";
    return askChoice(
        "Where will the API run?",
        [
            { label: "Local machine", value: "local" },
            { label: "Remote backend (provide URL)", value: "remote" },
        ],
        0,
    );
}

async function promptLocal(flags) {
    const existing = readEnvFile(API_ENV);
    const defaultSlug = projectSlug();

    let herd = false;
    if (flags["non-interactive"]) {
        herd = flags.herd === "true" || flags.herd === true;
    } else if (isMac()) {
        herd = await askBool("Serve the API via Laravel Herd?", false);
    }

    if (herd) {
        const herdRoot = flags["herd-root"] ?? (await ask("Herd parked root", defaultHerdRoot()));
        const slug =
            flags["project-slug"] ??
            (await ask("Project slug (used for the .test hostname)", defaultSlug));
        const result = ensureSymlink(API_DIR, herdRoot, slug);
        if (result.created) ok(`Linked ${API_DIR} → ${result.target}`);
        else info(`Herd slot already linked at ${result.target}`);
        const appUrl = `http://${slug}.test`;
        return {
            appUrl,
            proxyTarget: appUrl,
            apiBaseUrl: `${appUrl}/api/v1`,
            corsOrigins: "http://localhost:3000",
            herd: { slug, root: herdRoot, path: result.target },
        };
    }

    const defaultPort = existing.APP_URL?.match(/:(\d+)$/)?.[1] ?? "8000";
    const port = flags.port ?? (await ask("API port for `php artisan serve`", defaultPort));
    const appUrl = `http://localhost:${port}`;
    return {
        appUrl,
        proxyTarget: appUrl,
        apiBaseUrl: `${appUrl}/api/v1`,
        corsOrigins: "http://localhost:3000",
        herd: null,
    };
}

async function promptRemote(flags) {
    let base = flags["api-url"];
    if (!base && !flags["non-interactive"]) {
        base = await ask("Backend API origin (scheme + host, no path)", "https://api.example.com");
    }
    if (!base) throw new Error("Remote mode requires --api-url=<origin>");
    const url = new URL(base);
    const appUrl = `${url.protocol}//${url.host}`;
    const frontendOrigin =
        flags["frontend-origin"] ??
        (flags["non-interactive"]
            ? "http://localhost:3000"
            : await ask("Frontend origin (for CORS)", "http://localhost:3000"));
    return {
        appUrl,
        proxyTarget: appUrl,
        apiBaseUrl: `${appUrl}/api/v1`,
        corsOrigins: frontendOrigin,
        herd: null,
    };
}

async function promptAuthMode(flags) {
    const allowed = new Set(["bearer", "cookie", "mock"]);
    if (allowed.has(flags["auth-mode"])) return flags["auth-mode"];
    if (flags["non-interactive"]) return "bearer";
    return askChoice(
        "Authentication mode",
        [
            { label: "Bearer token (recommended default)", value: "bearer" },
            { label: "SPA cookie (Sanctum stateful)", value: "cookie" },
            { label: "Mock (frontend-only development, no backend calls)", value: "mock" },
        ],
        0,
    );
}

async function writeEnvs({ mode, target, authMode, projectName }) {
    const apiExisting = readEnvFile(API_ENV);
    const apiMerged = {
        ...apiExisting,
        APP_NAME: projectName,
        APP_URL: target.appUrl,
        CORS_ALLOWED_ORIGINS: target.corsOrigins,
        CORS_SUPPORTS_CREDENTIALS: authMode === "cookie" ? "true" : "false",
        FRONTEND_URL: target.corsOrigins.split(",")[0],
    };
    if (authMode === "cookie") {
        const url = new URL(target.corsOrigins.split(",")[0]);
        apiMerged.SANCTUM_STATEFUL_DOMAINS = `${url.host}`;
    }
    writeEnvFile(API_ENV, API_ENV_EXAMPLE, apiMerged);
    ok(`Wrote ${path.relative(ROOT, API_ENV)}`);

    const webExisting = readEnvFile(WEB_ENV);
    const webMerged = {
        ...webExisting,
        NEXT_PUBLIC_APP_NAME: projectName,
        NEXT_PUBLIC_API_BASE_URL: target.apiBaseUrl,
        NEXT_PUBLIC_AUTH_MODE: authMode,
        API_PROXY_TARGET: target.proxyTarget,
    };
    writeEnvFile(WEB_ENV, WEB_ENV_EXAMPLE, webMerged);
    ok(`Wrote ${path.relative(ROOT, WEB_ENV)}`);

    return { mode, target, authMode, projectName };
}

async function installDeps({ skipDeps }) {
    if (skipDeps) return;
    section("Install dependencies");
    await run("npm", ["install"], { cwd: ROOT });
    if (fs.existsSync(path.join(API_DIR, "composer.json"))) {
        await run("composer", ["install", "--no-interaction", "--prefer-dist"], { cwd: API_DIR });
    }
}

async function bootstrapLaravel({ skipMigrate, mode, seed }) {
    section("Bootstrap Laravel");
    const envFile = API_ENV;
    const envText = fs.readFileSync(envFile, "utf8");
    if (!/^APP_KEY=.+/m.test(envText)) {
        await run("php", ["artisan", "key:generate", "--force"], { cwd: API_DIR });
    } else {
        info("APP_KEY already set — skipping key:generate");
    }
    if (mode === "local") {
        const sqlite = path.join(API_DIR, "database", "database.sqlite");
        if (!fs.existsSync(sqlite)) {
            fs.mkdirSync(path.dirname(sqlite), { recursive: true });
            fs.writeFileSync(sqlite, "");
            ok(`Created ${path.relative(ROOT, sqlite)}`);
        }
    }
    if (!skipMigrate) {
        await run("php", ["artisan", "migrate", "--graceful", "--force"], { cwd: API_DIR });
    }
    if (seed) {
        await run("php", ["artisan", "db:seed", "--force"], { cwd: API_DIR });
        ok("Demo user seeded — login with demo@example.com / password");
    }
    await run("php", ["artisan", "storage:link"], { cwd: API_DIR, allowFail: true });
}

async function smoke({ target }) {
    section("Smoke test");
    try {
        const res = await fetch(`${target.appUrl}/api/ping`);
        if (res.ok) {
            ok(`${target.appUrl}/api/ping responded ${res.status}`);
            return true;
        }
        warn(`${target.appUrl}/api/ping responded ${res.status}`);
        return false;
    } catch (e) {
        warn(`Could not reach ${target.appUrl}/api/ping: ${e.message}`);
        return false;
    }
}

async function commandSetup(args) {
    const tools = await preflight();
    if (!tools.php || !tools.composer) {
        warn("php/composer missing — continuing will fail at the Laravel bootstrap step.");
    }

    const projectName = await promptProjectName(args.flags);
    const mode = await promptMode(args.flags);
    section(`Mode: ${mode}`);
    const target =
        mode === "local" ? await promptLocal(args.flags) : await promptRemote(args.flags);
    const authMode = await promptAuthMode(args.flags);

    await writeEnvs({ mode, target, authMode, projectName });
    await installDeps({ skipDeps: args.flags["skip-deps"] });

    let seed = false;
    if (mode === "local") {
        if (args.flags["seed"] === true || args.flags["seed"] === "true") seed = true;
        else if (args.flags["no-seed"]) seed = false;
        else if (!args.flags["non-interactive"]) {
            seed = await askBool("Seed a demo user (demo@example.com / password)?", true);
        }
    }

    if (tools.php && fs.existsSync(path.join(API_DIR, "vendor", "autoload.php"))) {
        await bootstrapLaravel({ skipMigrate: args.flags["skip-migrate"], mode, seed });
    } else if (!args.flags["skip-deps"]) {
        warn("Skipping Laravel bootstrap: vendor/ missing. Re-run setup after composer install.");
    }

    if (mode === "local" && !args.flags["skip-smoke"]) {
        await smoke({ target });
    }

    section("Next steps");
    if (target.herd) {
        console.log(`  • Open Herd and make sure ${color.cyan(target.appUrl)} resolves.`);
    } else if (mode === "local") {
        console.log(`  • Start the API:   ${color.cyan("npm run dev:api")}`);
    }
    console.log(`  • Start the web:   ${color.cyan("npm run dev:web")}`);
    console.log(`  • Or both at once: ${color.cyan("npm run dev")}`);
    console.log(`  • Re-run setup:    ${color.cyan("npm run setup")}`);
}

async function commandEnv(args) {
    const tools = await preflight();
    void tools;
    const projectName = await promptProjectName(args.flags);
    const mode = await promptMode(args.flags);
    const target =
        mode === "local" ? await promptLocal(args.flags) : await promptRemote(args.flags);
    const authMode = await promptAuthMode(args.flags);
    await writeEnvs({ mode, target, authMode, projectName });
}

async function commandCheck() {
    const tools = await preflight();
    section("Env files");
    for (const [label, file] of [
        ["apps/api/.env", API_ENV],
        ["apps/web/.env.local", WEB_ENV],
    ]) {
        if (fs.existsSync(file)) ok(`${label} present`);
        else warn(`${label} missing — run \`npm run setup\``);
    }
    const appUrl = readEnvFile(API_ENV).APP_URL;
    if (appUrl) {
        try {
            const res = await fetch(`${appUrl}/api/ping`);
            if (res.ok) ok(`${appUrl}/api/ping OK`);
            else warn(`${appUrl}/api/ping responded ${res.status}`);
        } catch (e) {
            warn(`${appUrl}/api/ping unreachable: ${e.message}`);
        }
    }
    if (!tools.node || !nodeVersionIsAtLeast(20)) process.exit(1);
}

async function main() {
    const args = parseArgs(process.argv.slice(2));
    try {
        if (!args.command || args.command === "setup") await commandSetup(args);
        else if (args.command === "env") await commandEnv(args);
        else if (args.command === "check") await commandCheck();
        else {
            err(`Unknown command: ${args.command}`);
            process.exitCode = 2;
        }
    } catch (e) {
        err(e.message ?? String(e));
        process.exitCode = process.exitCode || 1;
    } finally {
        closePrompt();
    }
}

main();
