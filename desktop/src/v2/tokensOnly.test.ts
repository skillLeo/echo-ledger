// Echo DS rule: v2 components and screens use only the --el-* variables from tokens.css. No hex or rgb/hsl colour,
// no raw px value, no gradient, anywhere in desktop/src/v2 outside the tests. One exception: a media query cannot read
// a variable, so an @media line may name a window breakpoint's width, and only one that tokens.css defines.
import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const files = (dir: string): string[] => readdirSync(dir).flatMap((f) => {
  const path = join(dir, f);
  return statSync(path).isDirectory() ? files(path) : [path];
});

const RULES: [string, RegExp][] = [
  ["hex colour", /#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b/],
  ["rgb/hsl colour", /\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/i],
  ["raw px value", /(?:^|[^\w-])-?\d*\.?\d+px\b/],
  ["gradient", /gradient\(/i],
  ["raw relative length", /(?:^|[^\w.-])\d*\.?\d+(?:rem|em|vh|vw|vmin|vmax|dvh|svh|lvh|ch|ex|pt|pc|cm|mm|Q)\b/],
  ["unitless line height or layer", /\b(?:line-height|z-index|lineHeight|zIndex)\s*:\s*["'`]?-?\d/],
  ["numeric style value", /style=\{\{[^}]*\b[A-Za-z]+\s*:\s*-?\d/],
];

/** The window breakpoints tokens.css defines (--el-breakpoint-*), as written there ("720px"). */
export const BREAKPOINTS: ReadonlySet<string> = new Set(
  [...readFileSync(join(here, "..", "tokens.css"), "utf8").matchAll(/--el-breakpoint-[a-z]+:\s*(\d+px)\s*;/g)].map((m) => m[1]),
);

/** The rules a source file breaks, as "rule (line n)". Comments count too: a value in a comment gets copied. */
export function violations(text: string, breakpoints: ReadonlySet<string> = BREAKPOINTS): string[] {
  const found: string[] = [];
  text.split("\n").forEach((line, i) => {
    const checked = /^\s*@media\b/.test(line) ? line.replace(/\d+px\b/g, (px) => (breakpoints.has(px) ? "" : px)) : line;
    for (const [name, re] of RULES) if (re.test(checked)) found.push(`${name} (line ${i + 1})`);
  });
  return found;
}

const sources = files(here)
  .filter((f) => /\.(css|tsx?|mjs)$/.test(f) && !/\.test\.tsx?$/.test(f))
  .map((f) => [relative(here, f).split("\\").join("/"), readFileSync(f, "utf8")] as const);

describe("v2 uses tokens only", () => {
  it("finds the component and screen sources", () => {
    expect(sources.some(([name]) => name.startsWith("components/"))).toBe(true);
    expect(sources.some(([name]) => name.endsWith(".css"))).toBe(true);
  });

  it.each(sources)("%s has no hex, rgb, raw px or gradient", (_name, text) => {
    expect(violations(text)).toEqual([]);
  });

  it("catches each planted value and passes token-only CSS", () => {
    for (const planted of [
      "color: #0F6E6A;",
      "background: #fff;",
      "border-color: #11223344;",
      "color: rgb(15, 110, 106);",
      "background: rgba(0,0,0,.5);",
      "color: hsl(200 50% 40%);",
      "padding: 12px;",
      "margin-inline: -4px;",
      "inline-size: 0.5px;",
      'style={{ width: "240px" }}',
      "background: linear-gradient(90deg, var(--el-role-accent-default), transparent);",
      "padding: 1.5rem;",
      "margin-block: 2em;",
      "block-size: 100vh;",
      "max-inline-size: 60ch;",
      "line-height: 1.9;",
      "line-height: 2;",
      "z-index: 20;",
      "style={{ zIndex: 30 }}",
      "style={{ width: 240 }}",
      "style={{ lineHeight: \"1.5\" }}",
    ]) {
      expect(violations(planted), planted).not.toEqual([]);
    }
    expect(violations("padding: var(--el-space-12); color: var(--el-role-text-primary);")).toEqual([]);
    expect(violations("background: color-mix(in srgb, var(--el-role-text-primary) 40%, transparent);")).toEqual([]);
    expect(violations('<div id="root" className="el-row" />')).toEqual([]);
    expect(violations("z-index: var(--el-layer-dialog); line-height: var(--el-text-body-line);")).toEqual([]);
    expect(violations('meta: "Rate for 12 mm rebar, delivery to Gujranwala"')).toEqual([]);
  });

  it("lets a media query name a breakpoint from tokens.css, and no other width", () => {
    expect([...BREAKPOINTS].sort()).toEqual(["1024px", "1440px", "720px"]);
    expect(violations("@media (width < 720px) {")).toEqual([]);
    expect(violations("@media (width >= 1024px) and (width < 1440px) {")).toEqual([]);
    expect(violations("@media (width < 700px) {")).not.toEqual([]);
    expect(violations("  .x { inline-size: 720px; }")).not.toEqual([]);
    const media = sources.flatMap(([, text]) => [...text.matchAll(/@media[^{]*/g)].flatMap((m) => m[0].match(/\d+px/g) ?? []));
    expect(media.length).toBeGreaterThan(0);
    for (const px of media) expect(BREAKPOINTS).toContain(px);
  });
});

describe("scaled fills", () => {
  it("a meter's fill (scaled by lib/motion fillWidth) grows from the start edge, in both directions", () => {
    const css = readFileSync(join(here, "styles", "v2.css"), "utf8");
    expect(css).toMatch(/\.el-meter__fill \{[^}]*transform-origin: left center;/);
    expect(css).toContain('[dir="rtl"] .el-meter__fill { transform-origin: right center; }');
  });
});
