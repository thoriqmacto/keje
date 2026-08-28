import readline from "node:readline/promises";
import { stdin as input, stdout as output } from "node:process";

let rl = null;

function getRl() {
    if (!rl) rl = readline.createInterface({ input, output });
    return rl;
}

export function closePrompt() {
    if (rl) {
        rl.close();
        rl = null;
    }
}

export async function ask(question, defaultValue = "") {
    const suffix = defaultValue ? ` ${/* default hint */ `[${defaultValue}]`}` : "";
    const answer = (await getRl().question(`${question}${suffix} `)).trim();
    return answer || defaultValue;
}

export async function askBool(question, defaultYes = false) {
    const hint = defaultYes ? "Y/n" : "y/N";
    const raw = (await getRl().question(`${question} (${hint}) `)).trim().toLowerCase();
    if (!raw) return defaultYes;
    return ["y", "yes", "true", "1"].includes(raw);
}

export async function askChoice(question, choices, defaultIndex = 0) {
    console.log(question);
    choices.forEach((c, i) => {
        const marker = i === defaultIndex ? "*" : " ";
        console.log(`  ${marker} ${i + 1}) ${c.label}`);
    });
    const raw = (await getRl().question(`Choose 1-${choices.length} [${defaultIndex + 1}] `)).trim();
    const idx = raw ? Number(raw) - 1 : defaultIndex;
    if (!Number.isInteger(idx) || idx < 0 || idx >= choices.length) {
        throw new Error(`Invalid choice: ${raw}`);
    }
    return choices[idx].value;
}
