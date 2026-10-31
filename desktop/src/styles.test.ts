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
    const padding = resolve(prop(".content", "padding"), "dark").split(" ").map(Number.parseFloat);
    const horizontal = 2 * (padding[1] ?? padding[0]);
    const columns = [...prop(".library", "grid-template-columns").matchAll(/minmax\((\d+)px/g)].map((m) => Number(m[1]));
    const gap = Number.parseFloat(resolve(prop(".library", "gap"), "dark"));
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
    // Speaker blocks: avatar, name and time on the first line; the words take the rest and may shrink to nothing.
    expect(prop(".segment", "grid-template-columns")).toBe("36px max-content minmax(0, 1fr)");
    expect(prop(".seg-text", "grid-area")).toMatch(/^text/);
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

  it("gives the library what is left of the page, so a budget banner takes its own height, only in the two-column layout", () => {
    const wide = media("(min-width: 1030px)");
    expect(prop(".content.page-library", "display", wide)).toBe("flex");
    expect(prop(".content.page-library", "flex-direction", wide)).toBe("column");
    expect(prop(".content.page-library .library", "flex", wide)).toMatch(/^1 1 0/);
    expect(prop(".content.page-library .library", "min-height", wide)).toBe("0px");
    expect(prop(".banner", "flex")).toMatch(/^(none|0 0 auto)$/);
    expect(prop(".content.page-library", "display")).toBe("");
  });
});

describe("colour contrast meets WCAG AA in both themes (A6.DU7)", () => {
  const surfaces = ["--bg", "--surface", "--surface-2"];

  it.each(THEMES)("text colours on every %s surface", (theme) => {
    for (const fg of ["--text", "--text-muted", "--text-faint", "--danger", "--success", "--warning"]) {
      for (const bg of surfaces) {
        const ratio = contrast(colour(theme, fg).rgb, colour(theme, bg).rgb);
        expect(ratio, `${theme}: ${fg} on ${bg} is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
      }
    }
  });

  it.each(THEMES)("status pills on their tint (%s)", (theme) => {
    const card = colour(theme, "--surface").rgb;
    for (const state of ["ready", "processing", "failed"]) {
      const selector = `.pill.${state}`;
      const background = over(colour(theme, selector, "background"), card);
      const ratio = contrast(colour(theme, selector).rgb, background);
      expect(ratio, `${theme}: ${selector} is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
    }
  });

  it.each(THEMES)("speaker labels and timestamps, also on the playing row (%s)", (theme) => {
    const card = colour(theme, "--surface").rgb;
    const playing = over(colour(theme, ".segment.active", "background"), card);
    for (const fg of [colour(theme, ".spk-1"), colour(theme, ".spk-2"), colour(theme, ".seg-time")]) {
      expect(contrast(fg.rgb, card)).toBeGreaterThanOrEqual(AA);
      expect(contrast(fg.rgb, playing), `${theme}: on the playing row`).toBeGreaterThanOrEqual(AA);
    }
  });

  it.each(THEMES)("source badges (%s)", (theme) => {
    const badge = over(colour(theme, ".badge", "background"), colour(theme, "--surface").rgb);
    for (const selector of [".badge.phone", ".badge.desktop", ".badge.web"]) {
      const ratio = contrast(colour(theme, selector).rgb, badge);
      expect(ratio, `${theme}: ${selector} is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
    }
  });

  it.each(THEMES)("white text on primary buttons and on the record button (%s)", (theme) => {
    const white: Rgb = [255, 255, 255];
    for (const selector of [".btn.primary", ".record-btn.idle", ".record-btn"]) {
      const background = colour(theme, selector, "background").rgb;
      expect(contrast(white, background), `${theme}: ${selector}`).toBeGreaterThanOrEqual(AA);
    }
  });

  it.each(THEMES)("the decision and upload counts on their tint (%s)", (theme) => {
    const background = over(colour(theme, ".queue-count", "background"), colour(theme, "--surface").rgb);
    const ratio = contrast(colour(theme, ".queue-count").rgb, background);
    expect(ratio, `${theme}: .queue-count is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
  });

  it.each(THEMES)("switch tracks stand out from the card (non-text 3:1, %s)", (theme) => {
    const card = colour(theme, "--surface").rgb;
    // Off: an outlined track (Windows 11 / Fluent style); on: a filled track.
    expect(contrast(colour(theme, ".switch", "border-color").rgb, card)).toBeGreaterThanOrEqual(3);
    expect(contrast(colour(theme, ".switch.on", "background").rgb, card)).toBeGreaterThanOrEqual(3);
  });
});

describe("motion (C5)", () => {
  const controls = readFileSync(join(here, "views/controls.css"), "utf8");

  it("defines the motion tokens: 150, 180 and 220 ms, ease-out", () => {
    expect(tokens("dark")["--motion-fast"]).toBe("150ms");
    expect(tokens("dark")["--motion-base"]).toBe("180ms");
    expect(tokens("dark")["--motion-slow"]).toBe("220ms");
    expect(tokens("dark")["--ease-out"].replace(/\s/g, "")).toBe("cubic-bezier(0,0,0,1)");
  });

  it("every transition uses a motion token, except level meters that follow the signal", () => {
    for (const [file, source] of [["styles.css", css], ["controls.css", controls]] as const) {
      for (const [, value] of source.matchAll(/transition:\s*([^;]+);/g)) {
        const meter = /\b(width|height) [\d.]+(ms|s) linear\b/.test(value);
        expect(meter || value.trim() === "none" || value.includes("var(--motion-"), `${file}: transition: ${value}`).toBe(true);
      }
    }
  });

  it("turns motion off with reduced motion", () => {
    const reduced = media("(prefers-reduced-motion: reduce)");
    const all = reduced.find((r) => r instanceof CSSStyleRule && r.selectorText.startsWith("*")) as CSSStyleRule | undefined;
    expect(all?.style.getPropertyValue("transition-duration")).toBe("0ms");
    expect(all?.style.getPropertyValue("animation-duration")).toBe("0ms");
  });
});

describe("overlay glass contrast (C7)", () => {
  // The overlays float over any window: check the text on the glass over a white and a black backdrop.
  it.each(THEMES)("caption, pill and review text stay readable on the %s glass over any background", (theme) => {
    const glass = colour(theme, "--glass");
    for (const backdrop of [[255, 255, 255], [0, 0, 0]] as Rgb[]) {
      const surface = over(glass, backdrop);
      for (const fg of ["--glass-text", "--glass-muted"]) {
        const ratio = contrast(colour(theme, fg).rgb, surface);
        expect(ratio, `${theme}: ${fg} on glass over ${backdrop.join(",")} is ${ratio.toFixed(2)}:1`).toBeGreaterThanOrEqual(AA);
      }
    }
  });
});
