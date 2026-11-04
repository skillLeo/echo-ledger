// A3.3 / v1.5: jsdom cannot lay out or animate, so these tests pin the CSS contract of the pill and the after-call
// toast and chip: sizes that fit their windows, the 180 ms ease-out slide-in, no motion with reduced motion, and
// transparent window backgrounds. The look on a real screen is a manual QA step.
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";

// Vitest replaces CSS imports (even `?raw`) with empty strings, so read the real stylesheets from disk.
const dir = (import.meta as ImportMeta & { dirname: string }).dirname;
const controlsCss = readFileSync(`${dir}/controls.css`, "utf8");
const appCss = readFileSync(`${dir}/../styles.css`, "utf8");

let style: HTMLStyleElement;

beforeAll(() => {
  style = document.createElement("style");
  style.textContent = `${appCss}\n${controlsCss}`;
  document.head.appendChild(style);
});

afterAll(() => {
  style.remove();
});

function styleOf(className: string, parentClass?: string): CSSStyleDeclaration {
  const el = document.createElement("div");
  el.className = className;
  const host = parentClass ? document.createElement("div") : document.body;
  if (parentClass) {
    host.className = parentClass;
    document.body.appendChild(host);
  }
  host.appendChild(el);
  return getComputedStyle(el);
}

function rulesIn(conditionPart: string): CSSStyleRule[] {
  const media = [...style.sheet!.cssRules].filter((r): r is CSSMediaRule => r instanceof CSSMediaRule && r.conditionText.includes(conditionPart));
  return media.flatMap((m) => [...m.cssRules].filter((r): r is CSSStyleRule => r instanceof CSSStyleRule));
}

function topLevelRule(selectorPart: string): CSSStyleRule | undefined {
  return [...style.sheet!.cssRules].find((r): r is CSSStyleRule => r instanceof CSSStyleRule && r.selectorText.includes(selectorPart));
}

describe("recording pill styles", () => {
  it("is one 36 px row with an 18 px radius inside the 280 x 44 window", () => {
    const root = styleOf("pill-root");
    expect(root.padding).toBe("4px");
    const pill = styleOf("rec-pill", "pill-root");
    expect(pill.height).toBe("36px");
    expect(pill.borderRadius).toBe("18px");
    expect(pill.display).toBe("flex");
    expect(pill.borderWidth).toBe("1px");
  });

  it("uses tabular digits for the timer and ellipsises the source label", () => {
    expect(styleOf("rec-timer", "pill-root").fontVariantNumeric).toBe("tabular-nums");
    const label = styleOf("rec-label", "pill-root");
    expect(label.textOverflow).toBe("ellipsis");
    expect(label.whiteSpace).toBe("nowrap");
    expect(styleOf("rec-meter", "pill-root").width).toBe("3px");
  });

  it("pulses the REC dot, but not with reduced motion", () => {
    expect(styleOf("rec-dot", "pill-root").animationName).toBe("rec-pulse");
    const reduced = rulesIn("prefers-reduced-motion");
    const dot = reduced.find((r) => r.selectorText === ".rec-dot");
    expect(dot?.style.animation).toBe("none");
  });
});

describe("after-call styles", () => {
  it("puts the toast and chip bottom-right in the 360 x 168 window, one 36 px pill row each", () => {
    const root = styleOf("review-root");
    expect(root.padding).toBe("10px");
    expect(root.justifyContent).toBe("flex-end");
    expect(root.alignItems).toBe("flex-end");
    const pill = styleOf("after-pill", "review-root");
    expect(pill.height).toBe("36px");
    expect(pill.borderRadius).toBe("18px");
    expect(pill.display).toBe("flex");
    expect(pill.maxWidth).toBe("340px");
    expect(pill.borderWidth).toBe("1px");
  });

  it("uses the recording pill's dark surface in both themes", () => {
    const rule = topLevelRule(".after-pill");
    expect(rule?.style.getPropertyValue("--ctl-surface").trim()).toBe("var(--pill-bg)");
    expect(rule?.style.getPropertyValue("--ctl-text").trim()).toBe("var(--pill-text)");
  });

  it("ellipsises a long title and keeps the length in tabular digits", () => {
    const title = styleOf("after-title", "after-pill");
    expect(title.textOverflow).toBe("ellipsis");
    expect(title.whiteSpace).toBe("nowrap");
    expect(styleOf("after-duration", "after-pill").fontVariantNumeric).toBe("tabular-nums");
  });

  it("slides in over 180 ms ease-out from 12 px below, like the ask chip", () => {
    for (const className of ["after-pill", "ask-chip"]) {
      const s = styleOf(className, "review-root");
      expect(s.animationName).toBe("ctl-slide-in");
      expect(s.animationDuration).toBe("180ms");
      expect(s.animationTimingFunction).toBe("ease-out");
    }
    const keyframes = [...style.sheet!.cssRules].find((r): r is CSSKeyframesRule => r instanceof CSSKeyframesRule && r.name === "ctl-slide-in");
    expect(keyframes?.cssRules[0].cssText.replace(/\s+/g, " ")).toContain("transform: translateY(12px)");
    expect(keyframes?.cssRules[0].cssText).toContain("opacity: 0");
  });

  it("counts the chip down with a thin line that starts from what is left", () => {
    const keyframes = [...style.sheet!.cssRules].find((r): r is CSSKeyframesRule => r instanceof CSSKeyframesRule && r.name === "after-countdown");
    expect(keyframes?.cssRules[0].cssText).toContain("var(--from");
    expect(styleOf("after-progress", "after-pill").height).toBe("2px");
  });

  it("does not move or count down with reduced motion", () => {
    const reduced = rulesIn("prefers-reduced-motion");
    const pills = reduced.find((r) => r.selectorText.includes(".after-pill"));
    expect(pills?.style.animation).toBe("none");
    expect(pills?.selectorText).toContain(".ask-chip");
    const lines = reduced.find((r) => r.selectorText.includes(".after-progress"));
    expect(lines?.style.display).toBe("none");
  });
});

describe("window backgrounds", () => {
  it.each(["pill", "review"])("the %s window has a transparent page background", (label) => {
    const rule = topLevelRule(`html[data-window="${label}"] body`);
    expect(rule, `no rule for html[data-window="${label}"] body`).toBeDefined();
    expect(rule!.selectorText).toContain(`html[data-window="${label}"]`);
    expect(rule!.style.background || rule!.style.backgroundColor).toBe("transparent");
  });
});
