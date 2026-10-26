// The desktop app, the web console and the phone app share one set of design tokens (C1): the same names and the
// same values in desktop/src/styles.css, web/resources/css/filament/admin/theme.css and mobile/.../ui/Tokens.kt.
// Run: node --test tools/design-tokens.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const desktopCss = read("desktop/src/styles.css");
const webCss = read("web/resources/css/filament/admin/theme.css");
const kotlin = read("mobile/app/src/main/java/com/echoledger/mobile/ui/Tokens.kt");

const COLOURS = ["bg", "surface", "surface-2", "border", "border-strong", "text", "text-muted", "text-faint", "accent", "accent-text", "accent-strong", "on-accent", "danger", "success", "warning", "me", "client"];

/** Custom properties of the first rule whose selector is exactly `selector`. */
function cssBlock(css, selector) {
  const start = css.indexOf(`${selector} {`);
  assert.ok(start >= 0, `no "${selector}" rule`);
  const body = css.slice(css.indexOf("{", start) + 1, css.indexOf("}", start));
  return Object.fromEntries([...body.matchAll(/--([\w-]+):\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]));
}

/** `#rrggbb` / `rgba(r, g, b, a)` / Kotlin `0xAARRGGBB` / `Color.White` as [r, g, b, a]. */
function rgba(value) {
  const hex = value.match(/^#([0-9a-f]{6})$/i);
  if (hex) return [...[0, 2, 4].map((i) => parseInt(hex[1].slice(i, i + 2), 16)), 1];
  const fn = value.match(/^rgba?\(([^)]+)\)$/);
  if (fn) {
    const [r, g, b, a = "1"] = fn[1].split(",").map((s) => s.trim());
    return [Number(r), Number(g), Number(b), Number(a)];
  }
  const argb = value.match(/^Color\(0x([0-9a-f]{8})\)$/i);
  if (argb) {
    const [a, r, g, b] = [0, 2, 4, 6].map((i) => parseInt(argb[1].slice(i, i + 2), 16));
    return [r, g, b, Math.round((a / 255) * 100) / 100];
  }
  if (value === "Color.White") return [255, 255, 255, 1];
  throw new Error(`unsupported colour ${value}`);
}

const kebab = (name) => name.replace(/([a-z])([A-Z0-9])/g, "$1-$2").toLowerCase();

function kotlinColours(name) {
  const start = kotlin.indexOf(`val ${name} = EchoColors(`);
  assert.ok(start >= 0, `no ${name} in Tokens.kt`);
  const body = kotlin.slice(start, kotlin.indexOf("\n)", start));
  return Object.fromEntries([...body.matchAll(/^\s+(\w+) = (Color[^,\n]*?),?$/gm)].map((m) => [kebab(m[1]), m[2]]));
}

function assertSame(theme, a, b, labels) {
  for (const name of COLOURS) {
    assert.ok(a[name] && b[name], `${theme}: --${name} missing in ${!a[name] ? labels[0] : labels[1]}`);
    const [x, y] = [rgba(a[name]), rgba(b[name])];
    assert.deepEqual(x.slice(0, 3), y.slice(0, 3), `${theme}: --${name} differs (${labels[0]} ${a[name]}, ${labels[1]} ${b[name]})`);
    assert.ok(Math.abs(x[3] - y[3]) <= 0.01, `${theme}: --${name} alpha differs (${a[name]} / ${b[name]})`);
  }
}

const themes = {
  dark: { desktop: cssBlock(desktopCss, ":root"), web: cssBlock(webCss, ".dark"), phone: kotlinColours("DarkColors") },
  light: {
    desktop: { ...cssBlock(desktopCss, ":root"), ...cssBlock(desktopCss, ':root[data-theme="light"]') },
    web: cssBlock(webCss, ":root"),
    phone: kotlinColours("LightColors"),
  },
};

for (const [theme, apps] of Object.entries(themes)) {
  test(`${theme} colours: desktop and web match`, () => assertSame(theme, apps.desktop, apps.web, ["desktop", "web"]));
  test(`${theme} colours: desktop and phone match`, () => assertSame(theme, apps.desktop, apps.phone, ["desktop", "phone"]));
}

test("spacing (8-pt), radius (8 / 12 / 16) and motion (150-220 ms) match in all three apps", () => {
  const desktop = cssBlock(desktopCss, ":root");
  const web = cssBlock(webCss, ":root");
  const expected = { "space-1": 4, "space-2": 8, "space-3": 12, "space-4": 16, "space-5": 24, "space-6": 32, "space-7": 48, "radius-sm": 8, "radius-md": 12, "radius-lg": 16 };
  for (const [name, px] of Object.entries(expected)) {
    assert.equal(desktop[name], `${px}px`, `desktop --${name}`);
    assert.equal(web[name], `${px}px`, `web --${name}`);
  }
  for (const n of [1, 2, 3, 4, 5, 6, 7]) assert.match(kotlin, new RegExp(`val s${n} = ${expected[`space-${n}`]}\\.dp`), `phone Space.s${n}`);
  for (const [k, px] of [["sm", 8], ["md", 12], ["lg", 16]]) assert.match(kotlin, new RegExp(`val ${k} = ${px}\\.dp`), `phone Radius.${k}`);
  for (const [k, ms] of [["fast", 150], ["base", 180], ["slow", 220]]) {
    assert.equal(desktop[`motion-${k}`], `${ms}ms`);
    assert.equal(web[`motion-${k}`], `${ms}ms`);
    assert.match(kotlin, new RegExp(`const val ${k.toUpperCase()} = ${ms}\\b`));
  }
  assert.equal(desktop["ease-out"], "cubic-bezier(0, 0, 0, 1)");
  assert.equal(web["ease-out"], "cubic-bezier(0, 0, 0, 1)");
  assert.match(kotlin, /CubicBezierEasing\(0f, 0f, 0f, 1f\)/);
});
