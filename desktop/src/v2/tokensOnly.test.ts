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
    expect([...BREAKPOINTS].sort()).toEqual(["1024px", "1100px", "1280px", "1440px", "720px", "780px"]);
    expect(violations("@media (width < 720px) {")).toEqual([]);
    expect(violations("@media (width >= 1024px) and (width < 1440px) {")).toEqual([]);
    expect(violations("@media (width < 700px) {")).not.toEqual([]);
    expect(violations("  .x { inline-size: 720px; }")).not.toEqual([]);
    const media = sources.flatMap(([, text]) => [...text.matchAll(/@media[^{]*/g)].flatMap((m) => m[0].match(/\d+px/g) ?? []));
    expect(media.length).toBeGreaterThan(0);
    for (const px of media) expect(BREAKPOINTS).toContain(px);
  });
});

describe("rows that wrap", () => {
  it("Settings, Live, Home and Help let a compact row's name and line wrap and its control go under the name; the Library's rows don't", () => {
    const css = readFileSync(join(here, "styles", "v2.css"), "utf8");
    const scope = ":is([data-v2=settings], [data-v2=settings-page], [data-v2=live], [data-v2=home], [data-v2=help]) .el-row--compact";
    expect(css).toContain(`${scope} { flex-wrap: wrap;`);
    expect(css).toContain(`${scope} :is(.el-row__title, .el-row__meta-text) { white-space: normal;`);
    expect(css).toContain(".el-setting__select { min-inline-size: min(calc(var(--el-space-64) * 3.5), 100%); }");
    expect(css).not.toMatch(/\[data-v2=library\][^{]*\{[^}]*white-space: normal/);
  });
});

describe("scaled fills", () => {
  it("a meter's fill (scaled by lib/motion fillWidth) grows from the start edge, in both directions", () => {
    const css = readFileSync(join(here, "styles", "v2.css"), "utf8");
    expect(css).toMatch(/\.el-meter__fill \{[^}]*transform-origin: left center;/);
    expect(css).toContain(".el-meter__fill:dir(rtl) { transform-origin: right center; }");
  });

  it("text that finds its own direction lines up with its column, in the form Chromium and WebKit read too", () => {
    const css = readFileSync(join(here, "styles", "v2.css"), "utf8");
    expect(css).toMatch(/\.el-row__title, [^{]*\{\s*text-align: match-parent;\s*text-align: -webkit-match-parent;\s*\}/);
    expect(css).toMatch(/\.el-screens-strip__title, \.el-nav-item__label \{\s*text-align: match-parent;/);
    // The Library sidebar's names ("All recordings", a label's own name) sit beside their icon right to left too.
    expect(readFileSync(join(here, "library", "LibraryNav.tsx"), "utf8")).toMatch(/className="el-nav-item__label" dir="auto"/);
  });

  it("the app's details (Learn more, Text on the screen) fold with its chevron, not the browser's triangle, turning the right way in RTL", () => {
    const css = readFileSync(join(here, "styles", "v2.css"), "utf8");
    expect(css).toContain(".el-more > summary::-webkit-details-marker { display: none; }");
    expect(css).toMatch(/\.el-more > summary \{[^}]*list-style: none;/);
    expect(css).toContain(".el-more[open] > summary > .el-icon { transform: rotate(90deg); }");
    expect(css).toContain("[dir=\"rtl\"] .el-more[open] > summary > .el-icon { transform: rotate(-90deg); }");
    expect(css).toContain(".el-more > p { margin: var(--el-space-8) 0 0; }");
    expect(readFileSync(join(here, "settings", "pages", "RecordingInputV2.tsx"), "utf8"))
      .toContain('<summary><Icon icon={Icons.ChevronEndIcon} directional />{t("Learn more")}</summary>');
    expect(readFileSync(join(here, "library", "screens.tsx"), "utf8"))
      .toContain('<summary><Icon icon={Icons.ChevronEndIcon} directional />{t("Text on the screen")}</summary>');
  });
});

// D-262: in Urdu a row's title and meta give Nastaliq room on every side inside their clip box, given back by margins
// (scripts/urduD261.mjs measures the ink); the Back to top button is named in the window's language.
describe("Urdu in the shared row and Back to top (D-262)", () => {
  it("the row's clipped text has Nastaliq's room in Urdu, and its layout is given back", () => {
    const css = readFileSync(join(here, "styles", "v2.css"), "utf8");
    const rule = css.match(/:lang\(ur\) :is\(\.el-row__title, \.el-row__meta-text\) \{[^}]*\}/)?.[0] ?? "";
    expect(rule).toContain("padding: var(--el-space-12) var(--el-space-8) var(--el-space-4);");
    expect(rule).toContain("margin: calc(-1 * var(--el-space-12)) calc(-1 * var(--el-space-8)) calc(-1 * var(--el-space-4));");
  });
  it("Back to top is named through t()", () => {
    const src = readFileSync(join(here, "components", "BackToTop.tsx"), "utf8");
    expect(src).toContain('aria-label={t("Back to top")} title={t("Back to top")}');
  });
});
