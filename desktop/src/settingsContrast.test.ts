// Settings' colour pairs (v1.7), from the stylesheet's own tokens in both themes: text at 4.5:1 at least, meter fills
// and the search highlight's ring at 3:1 against what is behind them.
import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "styles.css"), "utf8");

function tokens(theme: "dark" | "light"): Record<string, string> {
  const block = (selector: string) => {
    const at = css.indexOf(`${selector} {`);
    const body = css.slice(at, css.indexOf("\n}", at));
    return Object.fromEntries([...body.matchAll(/(--[\w-]+):\s*(#[0-9a-f]{6})/gi)].map((m) => [m[1], m[2].toLowerCase()]));
  };
  const dark = block(":root");
  return theme === "dark" ? dark : { ...dark, ...block(':root[data-theme="light"]') };
}

function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
function contrast(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

describe.each(["light", "dark"] as const)("Settings colours, %s", (theme) => {
  const t = tokens(theme);
  const text: [string, string][] = [
    ["--text", "--surface"], ["--text-muted", "--surface"], ["--text-muted", "--bg"], ["--text", "--surface-2"],
    ["--warning", "--surface"], ["--danger", "--surface"], ["--success", "--surface"], ["--accent-text", "--accent-soft"], ["--text-muted", "--surface-2"],
  ];
  it.each(text)("%s on %s is 4.5:1 or more", (fg, bg) => {
    expect(contrast(t[fg], t[bg])).toBeGreaterThanOrEqual(4.5);
  });
  const shapes: [string, string][] = [["--text-muted", "--border"], ["--warning", "--border"], ["--danger", "--border"], ["--accent-ring", "--surface"], ["--accent-ring", "--accent-soft"]];
  it.each(shapes)("%s against %s is 3:1 or more", (fg, bg) => {
    expect(contrast(t[fg], t[bg])).toBeGreaterThanOrEqual(3);
  });
});
