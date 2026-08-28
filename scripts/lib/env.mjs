import fs from "node:fs";
import path from "node:path";

export function parseEnv(text) {
    const out = {};
    const lines = text.split(/\r?\n/);
    for (const line of lines) {
        if (!line || line.trimStart().startsWith("#")) continue;
        const eq = line.indexOf("=");
        if (eq === -1) continue;
        const key = line.slice(0, eq).trim();
        let val = line.slice(eq + 1).trim();
        if (
            (val.startsWith('"') && val.endsWith('"')) ||
            (val.startsWith("'") && val.endsWith("'"))
        ) {
            val = val.slice(1, -1);
        }
        out[key] = val;
    }
    return out;
}

export function readEnvFile(filepath) {
    if (!fs.existsSync(filepath)) return {};
    return parseEnv(fs.readFileSync(filepath, "utf8"));
}

export function readEnvTemplate(filepath) {
    if (!fs.existsSync(filepath)) return { keys: [], values: {} };
    const text = fs.readFileSync(filepath, "utf8");
    const values = parseEnv(text);
    const keys = [];
    for (const line of text.split(/\r?\n/)) {
        if (!line || line.trimStart().startsWith("#")) continue;
        const eq = line.indexOf("=");
        if (eq === -1) continue;
        keys.push(line.slice(0, eq).trim());
    }
    return { keys, values };
}

function needsQuoting(val) {
    return /[\s#"'$]/.test(val);
}

export function serializeEnv(templateText, values) {
    const out = [];
    const seen = new Set();
    for (const rawLine of templateText.split(/\r?\n/)) {
        if (!rawLine || rawLine.trimStart().startsWith("#")) {
            out.push(rawLine);
            continue;
        }
        const eq = rawLine.indexOf("=");
        if (eq === -1) {
            out.push(rawLine);
            continue;
        }
        const key = rawLine.slice(0, eq).trim();
        seen.add(key);
        const val = values[key] ?? rawLine.slice(eq + 1).trim();
        out.push(`${key}=${needsQuoting(val) ? `"${val.replace(/"/g, '\\"')}"` : val}`);
    }
    // Append any new keys not present in the template
    for (const [key, val] of Object.entries(values)) {
        if (seen.has(key)) continue;
        out.push(`${key}=${needsQuoting(val) ? `"${val.replace(/"/g, '\\"')}"` : val}`);
    }
    return out.join("\n");
}

export function writeEnvFile(filepath, templatePath, values) {
    const templateText = fs.existsSync(templatePath)
        ? fs.readFileSync(templatePath, "utf8")
        : Object.keys(values).map((k) => `${k}=`).join("\n");
    fs.mkdirSync(path.dirname(filepath), { recursive: true });

    if (fs.existsSync(filepath)) {
        const backup = `${filepath}.bak`;
        fs.copyFileSync(filepath, backup);
    }
    fs.writeFileSync(filepath, serializeEnv(templateText, values) + "\n", "utf8");
}
