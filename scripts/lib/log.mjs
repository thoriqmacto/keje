const isTTY = process.stdout.isTTY;
const c = (code) => (s) => (isTTY ? `\x1b[${code}m${s}\x1b[0m` : String(s));

export const color = {
    dim: c("2"),
    bold: c("1"),
    red: c("31"),
    green: c("32"),
    yellow: c("33"),
    cyan: c("36"),
};

export function info(msg) {
    console.log(`${color.cyan("›")} ${msg}`);
}
export function ok(msg) {
    console.log(`${color.green("✓")} ${msg}`);
}
export function warn(msg) {
    console.warn(`${color.yellow("!")} ${msg}`);
}
export function err(msg) {
    console.error(`${color.red("✗")} ${msg}`);
}
export function section(msg) {
    console.log(`\n${color.bold(msg)}`);
}
