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

/** The hue (0–360) and saturation (0–1) of a CSS colour written as hex, rgb() or hsl(); null for anything else. */
export function hueOf(value: string): { hue: number; sat: number } | null {
  let r: number, g: number, b: number;
  const hex = /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.exec(value.trim());
  const rgb = /^rgba?\(\s*(\d+(?:\.\d+)?)[\s,]+(\d+(?:\.\d+)?)[\s,]+(\d+(?:\.\d+)?)/i.exec(value.trim());
  const hsl = /^hsla?\(\s*(-?\d+(?:\.\d+)?)(?:deg)?[\s,]+(\d+(?:\.\d+)?)%/i.exec(value.trim());
  if (hsl) return { hue: ((Number(hsl[1]) % 360) + 360) % 360, sat: Number(hsl[2]) / 100 };
  if (hex) {
    const h = hex[1].length <= 4 ? [...hex[1].slice(0, 3)].map((c) => c + c).join("") : hex[1].slice(0, 6);
    [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16));
  } else if (rgb) {
    [r, g, b] = [rgb[1], rgb[2], rgb[3]].map(Number);
  } else return null;
  const [rn, gn, bn] = [r / 255, g / 255, b / 255];
  const max = Math.max(rn, gn, bn);
  const min = Math.min(rn, gn, bn);
  const d = max - min;
  if (d === 0) return { hue: 0, sat: 0 };
  const hue = max === rn ? 60 * (((gn - bn) / d) % 6) : max === gn ? 60 * ((bn - rn) / d + 2) : 60 * ((rn - gn) / d + 4);
  const light = (max + min) / 2;
  return { hue: (hue + 360) % 360, sat: d / (1 - Math.abs(2 * light - 1)) };
}

/** Purple, indigo or violet: a hue from 250 to 300 degrees that is not close to grey. */
export function isPurple(value: string): boolean {
  const c = hueOf(value);
  return !!c && c.sat >= 0.15 && c.hue >= 250 && c.hue <= 300;
}

const ROLE_COLOUR = /--el-role-(accent|success|warning|danger|record)/;
const COLOUR_VALUE = /#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})\b|\b(?:rgba?|hsla?)\([^)]*\)/gi;

/** What a file breaks, as "rule: line n". */
export function templateFindings(text: string): string[] {
  const found: string[] = [];
  text.split("\n").forEach((line, i) => {
    const at = `line ${i + 1}`;
    if (/gradient\(/i.test(line)) found.push(`gradient: ${at}`);
    if (/\b(purple|violet|indigo|lavender|magenta)\b/i.test(line) || /--el-[a-z-]*(purple|violet|indigo)/i.test(line)) found.push(`purple: ${at}`);
    if ((line.match(COLOUR_VALUE) ?? []).some(isPurple)) found.push(`purple hue: ${at}`);
    if (/\p{Extended_Pictographic}/u.test(line.replace(/[©®™]/g, ""))) found.push(`emoji: ${at}`);
    // A coloured edge on one side, in any form: the side's border (shorthand, colour, inline pair) or an inset stripe.
    const side = /border-(left|right|inline|inline-start|inline-end)(-color|-style|-width)?\s*:([^;]*)/i.exec(line);
    if (side && (ROLE_COLOUR.test(side[3]) || (side[3].match(COLOUR_VALUE) ?? []).some((c) => (hueOf(c)?.sat ?? 0) >= 0.15))) found.push(`coloured start border: ${at}`);
    const shadow = /box-shadow\s*:([^;]*)/i.exec(line);
    if (shadow && /\binset\b/i.test(shadow[1]) && (ROLE_COLOUR.test(shadow[1]) || (shadow[1].match(COLOUR_VALUE) ?? []).some((c) => (hueOf(c)?.sat ?? 0) >= 0.15))) found.push(`inset stripe: ${at}`);
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
      "border-inline: var(--el-space-4) solid var(--el-role-success-default);",
      "border-right: 3px solid #0F6E6A;",
      "box-shadow: inset 4px 0 0 var(--el-role-accent-default);",
      "box-shadow: inset 3px 0 0 0 #3FB8AF;",
      "color: #7C3AED;",
      "background: rgb(139, 92, 246);",
      "color: hsl(270 60% 50%);",
    ]) expect(templateFindings(planted), planted).not.toEqual([]);
    expect(templateFindings("border-inline-start: var(--el-border-hairline) solid var(--el-role-border-default);")).toEqual([]);
    expect(templateFindings('<span className="el-pill__sep">·</span>')).toEqual([]);
    expect(templateFindings("box-shadow: var(--el-shadow-1), 0 0 0 var(--el-border-hairline) var(--el-role-border-default);")).toEqual([]);
    expect(templateFindings("border-inline-end: var(--el-border-hairline) solid var(--el-role-border-default);")).toEqual([]);
  });

  it("reads the hue of a colour and calls 250 to 300 degrees purple, never a grey or the teal accent", () => {
    expect(hueOf("#7C3AED")!.hue).toBeCloseTo(262, 0);
    expect(isPurple("#7C3AED")).toBe(true);
    expect(isPurple("#3e63dd")).toBe(false);
    expect(isPurple("#8B5CF6")).toBe(true);
    expect(isPurple("rgb(139, 92, 246)")).toBe(true);
    expect(isPurple("hsl(270 60% 50%)")).toBe(true);
    expect(isPurple("#0F6E6A")).toBe(false);
    expect(isPurple("#6E6E73")).toBe(false);
    expect(isPurple("#111113")).toBe(false);
  });

  it("the design values v2 uses have no purple either", () => {
    const tokens = readFileSync(join(here, "..", "tokens.css"), "utf8");
    const purple = (tokens.match(COLOUR_VALUE) ?? []).filter(isPurple);
    expect(purple).toEqual([]);
  });
});
