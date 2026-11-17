// The template-look rules (docs/v2/DESIGN_SYSTEM.md) that a scan can check, across desktop/src/v2 outside the tests:
// no gradients, no purple, indigo or violet, no emoji, and no card with a coloured start (left) border. Kept at 0.
import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const files = (dir: string): string[] => readdirSync(dir).flatMap((f) => {
  const path = join(dir, f);
  return statSync(path).isDirectory() ? files(path) : [path];
});
const sources = files(here)
  .filter((f) => /\.(css|tsx?|mjs)$/.test(f) && !/\.test\.tsx?$/.test(f))
  .map((f) => [relative(here, f).split("\\").join("/"), readFileSync(f, "utf8")] as const);

/** What a file breaks, as "rule: line n". */
export function templateFindings(text: string): string[] {
  const found: string[] = [];
  text.split("\n").forEach((line, i) => {
    const at = `line ${i + 1}`;
    if (/gradient\(/i.test(line)) found.push(`gradient: ${at}`);
    if (/\b(purple|violet|indigo|lavender|magenta)\b/i.test(line) || /--el-[a-z-]*(purple|violet|indigo)/i.test(line)) found.push(`purple: ${at}`);
    if (/\p{Extended_Pictographic}/u.test(line.replace(/[©®™]/g, ""))) found.push(`emoji: ${at}`);
    if (/border-(left|inline-start)(-color)?\s*:[^;]*--el-role-(accent|success|warning|danger|record)/i.test(line)) found.push(`coloured start border: ${at}`);
  });
  return found;
}

describe("the template look stays out of v2", () => {
  it("scans the v2 sources", () => {
    expect(sources.length).toBeGreaterThan(30);
  });

  it("finds 0 gradients, purple, emoji or coloured start borders", () => {
    const all = sources.flatMap(([name, text]) => templateFindings(text).map((f) => `${name}: ${f}`));
    expect(all).toEqual([]);
  });

  it("catches each planted case", () => {
    for (const planted of [
      "background: linear-gradient(90deg, var(--el-role-accent-default), transparent);",
      "color: purple;",
      "/* an indigo accent */",
      '<Button>Saved 🎉</Button>',
      "label: \"✨ New\"",
      "border-inline-start: var(--el-space-4) solid var(--el-role-accent-default);",
      "border-left-color: var(--el-role-warning-default);",
    ]) expect(templateFindings(planted), planted).not.toEqual([]);
    expect(templateFindings("border-inline-start: var(--el-border-hairline) solid var(--el-role-border-default);")).toEqual([]);
    expect(templateFindings('<span className="el-pill__sep">·</span>')).toEqual([]);
  });
});
