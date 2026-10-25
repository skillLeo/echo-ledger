// Contract tests for the main-window stylesheet. jsdom cannot lay out a page, so the rules behind the layout and
// contrast fixes (A6.DU5, A6.DU7, A6.DU8) are read from the stylesheet as the browser parses it; the visual check of
// the real window is a manual QA step.
import { beforeAll, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
// Vitest turns imported CSS into an empty module, so the file is read from disk.
const css = readFileSync(join(here, "styles.css"), "utf8");
const tauriConf = JSON.parse(readFileSync(join(here, "../src-tauri/tauri.conf.json"), "utf8")) as {
  app: { windows: { label: string; minWidth: number }[] };
};

let rules: CSSRule[] = [];
beforeAll(() => {
  const style = document.createElement("style");
  style.textContent = css;
  document.head.appendChild(style);
  rules = Array.from((style.sheet as CSSStyleSheet).cssRules);
});

const styleRules = (list: CSSRule[]) => list.filter((r): r is CSSStyleRule => r instanceof CSSStyleRule);

/** The value the last rule naming exactly this selector gives a property (later rules win, as in the cascade). */
function prop(selector: string, name: string, list: CSSRule[] = rules): string {
  const values = styleRules(list)
    .filter((r) => r.selectorText.split(",").map((s) => s.trim()).includes(selector))
    .map((r) => r.style.getPropertyValue(name).trim())
    .filter(Boolean);
  return values[values.length - 1] ?? "";
}

function media(condition: string): CSSRule[] {
  const rule = rules.find((r): r is CSSMediaRule => r instanceof CSSMediaRule && r.media.mediaText === condition);
  return rule ? Array.from(rule.cssRules) : [];
}

type Theme = "dark" | "light";
type Rgb = [number, number, number];
type Colour = { rgb: Rgb; alpha: number };

/** Custom properties of a theme: dark is `:root`, light overrides it with `:root[data-theme="light"]`. */
function tokens(theme: Theme): Record<string, string> {
  const read = (selector: string) => {
    const rule = styleRules(rules).find((r) => r.selectorText === selector);
    const out: Record<string, string> = {};
    for (let i = 0; i < (rule?.style.length ?? 0); i++) {
      const name = rule!.style.item(i);
      if (name.startsWith("--")) out[name] = rule!.style.getPropertyValue(name).trim();
    }
    return out;
  };
  return theme === "dark" ? read(":root") : { ...read(":root"), ...read(':root[data-theme="light"]') };
}

const resolve = (value: string, theme: Theme) => value.replace(/var\((--[\w-]+)\)/g, (_, name: string) => tokens(theme)[name] ?? name);

function parseColour(value: string): Colour {
  const hex = value.match(/^#([0-9a-f]{6})$/i);
  if (hex) return { rgb: [0, 2, 4].map((i) => parseInt(hex[1].slice(i, i + 2), 16)) as Rgb, alpha: 1 };
  const fn = value.match(/^rgba?\(([^)]+)\)$/);
  if (fn) {
    const [r, g, b, a] = fn[1].split(",").map((s) => Number.parseFloat(s));
    return { rgb: [r, g, b], alpha: a ?? 1 };
  }
  throw new Error(`unsupported colour "${value}"`);
}

/** A theme colour: a token name (`--muted`) or a selector's property, honouring the dark-only override rules. */
function colour(theme: Theme, selectorOrToken: string, property = "color"): Colour {
  if (selectorOrToken.startsWith("--")) return parseColour(tokens(theme)[selectorOrToken]);
  const darkOnly = theme === "dark" ? prop(`:root:not([data-theme="light"]) ${selectorOrToken}`, property) : "";
  const lightOnly = theme === "light" ? prop(`:root[data-theme="light"] ${selectorOrToken}`, property) : "";
  return parseColour(resolve(darkOnly || lightOnly || prop(selectorOrToken, property), theme));
}

const linear = (c: number) => {
  const s = c / 255;
  return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
};
const luminance = ([r, g, b]: Rgb) => 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
/** WCAG 2.x contrast ratio. */
function contrast(a: Rgb, b: Rgb): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}
/** A translucent colour composited onto an opaque backdrop. */
const over = (top: Colour, under: Rgb): Rgb => top.rgb.map((c, i) => c * top.alpha + under[i] * (1 - top.alpha)) as Rgb;
const AA = 4.5;
const THEMES: Theme[] = ["dark", "light"];

describe("library layout down to the minimum window (A6.DU5)", () => {
  it("stacks the list and the transcript below 1030 px", () => {
    const narrow = media("(max-width: 1029px)");
    expect(narrow.length).toBeGreaterThan(0);
    expect(prop(".library", "grid-template-columns", narrow)).toBe("minmax(0, 1fr)");
    expect(prop(".library", "height", narrow)).toBe("auto");
    expect(prop(".list", "max-height", narrow)).not.toBe("");
  });

  it("covers the smallest window the app allows", () => {
    const main = tauriConf.app.windows.find((w) => w.label === "main")!;
    expect(main.minWidth).toBe(860);
    expect(main.minWidth).toBeLessThanOrEqual(1029);
  });

  it("fits the two columns from 1030 px, a scrollbar included", () => {
    const sidebar = Number.parseFloat(prop(".app", "grid-template-columns").split(" ")[0]);
    const padding = prop(".content", "padding").split(" ").map(Number.parseFloat);
    const horizontal = 2 * (padding[1] ?? padding[0]);
    const columns = [...prop(".library", "grid-template-columns").matchAll(/minmax\((\d+)px/g)].map((m) => Number(m[1]));
    const gap = Number.parseFloat(prop(".library", "gap"));
    expect(columns).toHaveLength(2);
    expect(sidebar + horizontal + columns[0] + gap + columns[1] + 17).toBeLessThanOrEqual(1030);
  });
});

describe("long text never breaks the layout (A6.DU8)", () => {
  it("keeps the status pill in the row: the title shrinks and ellipsises instead", () => {
    expect(prop(".item", "grid-template-columns")).toBe("minmax(0, 1fr)");
    expect(prop(".item > .row", "min-width")).toBe("0px");
    expect(prop(".item-main", "min-width")).toBe("0px");
    expect(prop(".item-title", "text-overflow")).toBe("ellipsis");
    expect(prop(".item-title", "white-space")).toBe("nowrap");
    expect(prop(".pill", "flex")).toMatch(/^(none|0 0 auto)$/);
  });

  it("wraps unbroken URLs in titles, previews, transcripts, notices and the dictation box", () => {
    for (const selector of [".item-preview", ".detail-title", ".seg-text", ".notice", ".toast", ".dictation-text"]) {
      expect(prop(selector, "overflow-wrap"), selector).toBe("anywhere");
    }
    expect(prop(".segment", "grid-template-columns")).toBe("52px 64px minmax(0, 1fr)");
    expect(prop(".list", "min-width")).toBe("0px");
    expect(prop(".detail", "min-width")).toBe("0px");
  });

  it("applies overflow-wrap to rendered elements", () => {
    const el = document.createElement("span");
    el.className = "seg-text";
    document.body.appendChild(el);
    expect(getComputedStyle(el).overflowWrap).toBe("anywhere");
    el.remove();
  });

  it("aligns the Record cards: no extra top margin on the second card, actions pushed to the bottom", () => {
    expect(prop(".grid-2 > .card + .card", "margin-top")).toBe("0px");
    expect(prop(".grid-2 > .card", "flex-direction")).toBe("column");
    expect(prop(".card-actions", "margin-top")).toBe("auto");
  });

  it("lays out a seekable transcript row button like the plain rows", () => {
    expect(prop("button.segment", "text-align")).toBe("left");
    expect(prop("button.segment", "width")).toBe("100%");
  });

  it("keeps waiting recordings and failed uploads inside the list column", () => {
    expect(prop(".queue-item", "grid-template-columns")).toBe("minmax(0, 1fr)");
    expect(prop(".queue-item", "min-width")).toBe("0px");
    expect(prop(".queue-title", "text-overflow")).toBe("ellipsis");
    expect(prop(".queue-actions", "flex-wrap")).toBe("wrap");
    expect(prop(".queue-error", "overflow-wrap")).toBe("anywhere");
    expect(prop(".mic-user-why", "overflow-wrap")).toBe("anywhere");
    expect(prop(".site-pattern", "overflow-wrap")).toBe("anywhere");
  });

  it("gives the library less height while the budget banner is up, only in the two-column layout", () => {
    const wide = media("(min-width: 1030px)");
    expect(prop(".content.has-banner .library", "height", wide)).toBe("calc(100% - 224px)");
    expect(prop(".content.has-banner .library", "height")).toBe("");
  });
});

describe("colour contrast meets WCAG AA in both themes (A6.DU7)", () => {
  const surfaces = ["--bg", "--sidebar", "--surface-solid", "--surface-2", "--input-bg"];

  it.each(THEMES)("text colours on every %s surface", (theme) => {
    for (const fg of ["--text", "--muted", "--faint", "--danger", "--success", "--warning"]) {
      for (const bg of surfaces) {
        const ratio = contrast(colour(theme, fg).rgb, colour(theme, bg).rgb);
        expect(ratio, `${theme}: ${fg} on ${bg} is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
      }
    }
  });

  it.each(THEMES)("status pills on their tint (%s)", (theme) => {
    const card = colour(theme, "--surface-solid").rgb;
    for (const state of ["ready", "processing", "failed"]) {
      const selector = `.pill.${state}`;
      const background = over(colour(theme, selector, "background"), card);
      const ratio = contrast(colour(theme, selector).rgb, background);
      expect(ratio, `${theme}: ${selector} is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
    }
  });

  it.each(THEMES)("speaker labels and timestamps, also on the playing row (%s)", (theme) => {
    const card = colour(theme, "--surface-solid").rgb;
    const playing = over(colour(theme, ".segment.active", "background"), card);
    for (const fg of [colour(theme, ".spk-1"), colour(theme, ".spk-2"), colour(theme, ".seg-time")]) {
      expect(contrast(fg.rgb, card)).toBeGreaterThanOrEqual(AA);
      expect(contrast(fg.rgb, playing), `${theme}: on the playing row`).toBeGreaterThanOrEqual(AA);
    }
  });

  it.each(THEMES)("source badges (%s)", (theme) => {
    const badge = over(colour(theme, ".badge", "background"), colour(theme, "--surface-solid").rgb);
    for (const selector of [".badge.phone", ".badge.desktop", ".badge.web"]) {
      const ratio = contrast(colour(theme, selector).rgb, badge);
      expect(ratio, `${theme}: ${selector} is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
    }
  });

  it("white text on primary buttons and on the record button", () => {
    const white: Rgb = [255, 255, 255];
    const stops = (gradient: string) => [...gradient.matchAll(/#[0-9a-f]{6}|rgba?\([^)]+\)/gi)].map((m) => parseColour(m[0]).rgb);
    for (const background of [resolve(prop(".btn.primary", "background"), "dark"), resolve(prop(".record-btn.idle", "background"), "dark"), prop(".record-btn", "background")]) {
      const colours = stops(background);
      expect(colours.length, background).toBeGreaterThanOrEqual(2);
      for (const stop of colours) expect(contrast(white, stop), background).toBeGreaterThanOrEqual(AA);
    }
  });

  it.each(THEMES)("the decision and upload counts on their tint (%s)", (theme) => {
    const background = over(colour(theme, ".queue-count", "background"), colour(theme, "--surface-solid").rgb);
    const ratio = contrast(colour(theme, ".queue-count").rgb, background);
    expect(ratio, `${theme}: .queue-count is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
  });

  it.each(THEMES)("switch tracks stand out from the card (non-text 3:1, %s)", (theme) => {
    const card = colour(theme, "--surface-solid").rgb;
    expect(contrast(colour(theme, ".switch", "background").rgb, card)).toBeGreaterThanOrEqual(3);
    expect(contrast(colour(theme, ".switch.on", "background").rgb, card)).toBeGreaterThanOrEqual(3);
  });
});
