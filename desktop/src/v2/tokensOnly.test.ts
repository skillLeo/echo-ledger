// Echo DS rule: v2 components and screens use only the --el-* variables from tokens.css. No hex or rgb/hsl colour,
// no raw px value, no gradient, anywhere in desktop/src/v2 outside the tests.
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
];

/** The rules a source file breaks, as "rule (line n)". Comments count too: a value in a comment gets copied. */
export function violations(text: string): string[] {
  const found: string[] = [];
  text.split("\n").forEach((line, i) => {
    for (const [name, re] of RULES) if (re.test(line)) found.push(`${name} (line ${i + 1})`);
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
    ]) {
      expect(violations(planted), planted).not.toEqual([]);
    }
    expect(violations("padding: var(--el-space-12); color: var(--el-role-text-primary);")).toEqual([]);
    expect(violations("background: color-mix(in srgb, var(--el-role-text-primary) 40%, transparent);")).toEqual([]);
    expect(violations('<div id="root" className="el-row" />')).toEqual([]);
  });
});
