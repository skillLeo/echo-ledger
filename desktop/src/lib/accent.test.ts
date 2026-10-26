import { afterEach, describe, expect, it } from "vitest";
import { accentTokens, applyAccent, contrast, parseHex, parseRgb, setSystemAccent, type Rgb } from "./accent";

const hex = (value: string): Rgb => parseHex(value)!;
const WHITE: Rgb = [255, 255, 255];
const SURFACE = { light: hex("#f0f1f4"), dark: hex("#222328") };

describe("system accent (C2)", () => {
  afterEach(() => {
    setSystemAccent(null);
    delete document.documentElement.dataset.theme;
  });

  it("parses #rrggbb and computed rgb() colours", () => {
    expect(parseHex("#0078D4")).toEqual([0, 120, 212]);
    expect(parseHex("0078d4")).toEqual([0, 120, 212]);
    expect(parseHex("blue")).toBeNull();
    expect(parseRgb("rgb(0, 122, 255)")).toEqual([0, 122, 255]);
    expect(parseRgb("rgba(10, 20, 30, 0.5)")).toEqual([10, 20, 30]);
    expect(parseRgb("")).toBeNull();
  });

  // Windows default blue, macOS yellow, a near-black and a pale pink: every accent keeps AA in both themes.
  it.each(["#0078d4", "#ffcc00", "#101010", "#ffd1dc", "#8b5cf6"])("keeps AA for %s in both themes", (value) => {
    for (const theme of ["light", "dark"] as const) {
      const t = accentTokens(hex(value), theme);
      expect(contrast(WHITE, hex(t["--accent-strong"])), `${theme} white on --accent-strong`).toBeGreaterThanOrEqual(4.5);
      expect(contrast(hex(t["--accent-text"]), SURFACE[theme]), `${theme} --accent-text`).toBeGreaterThanOrEqual(4.5);
      expect(contrast(hex(t["--accent"]), SURFACE[theme]), `${theme} --accent indicator`).toBeGreaterThanOrEqual(3);
      expect(t["--accent-soft"]).toMatch(/^rgba\(\d+, \d+, \d+, 0\.(16|1)\)$/);
    }
  });

  it("leaves an accent that already passes unchanged", () => {
    expect(accentTokens(hex("#5b21b6"), "light")["--accent-strong"]).toBe("#5b21b6");
  });

  it("applies the tokens for the current theme and removes them when cleared", () => {
    document.documentElement.dataset.theme = "light";
    setSystemAccent(hex("#0078d4"));
    const style = document.documentElement.style;
    expect(style.getPropertyValue("--accent-strong")).toBe(accentTokens(hex("#0078d4"), "light")["--accent-strong"]);

    document.documentElement.dataset.theme = "dark";
    applyAccent();
    expect(style.getPropertyValue("--accent-text")).toBe(accentTokens(hex("#0078d4"), "dark")["--accent-text"]);

    setSystemAccent(null);
    expect(style.getPropertyValue("--accent")).toBe("");
  });
});
