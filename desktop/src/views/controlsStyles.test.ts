// A3.3 / A2.2: jsdom cannot lay out or animate, so these tests pin the CSS contract of the pill and the review card:
// sizes that fit their windows, the 180 ms ease-out slide-in, no motion with reduced motion, and transparent window
// backgrounds. The look on a real screen is a manual QA step.
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

describe("review card styles", () => {
  it("is a 340 x 148 card with a 16 px radius, bottom-right in the 360 x 168 window", () => {
    const root = styleOf("review-root");
    expect(root.padding).toBe("10px");
    expect(root.justifyContent).toBe("flex-end");
    expect(root.alignItems).toBe("flex-end");
    const card = styleOf("review-card", "review-root");
    expect(card.width).toBe("340px");
    expect(card.height).toBe("148px");
    expect(card.borderRadius).toBe("16px");
    expect(card.borderWidth).toBe("1px");
  });

  it("slides in over 180 ms ease-out from 12 px below", () => {
    const card = styleOf("review-card", "review-root");
    expect(card.animationName).toBe("ctl-slide-in");
    expect(card.animationDuration).toBe("180ms");
    expect(card.animationTimingFunction).toBe("ease-out");
    const keyframes = [...style.sheet!.cssRules].find((r): r is CSSKeyframesRule => r instanceof CSSKeyframesRule && r.name === "ctl-slide-in");
    expect(keyframes?.cssRules[0].cssText.replace(/\s+/g, " ")).toContain("transform: translateY(12px)");
    expect(keyframes?.cssRules[0].cssText).toContain("opacity: 0");
  });

  it("does not move with reduced motion", () => {
    const reduced = rulesIn("prefers-reduced-motion");
    const card = reduced.find((r) => r.selectorText.includes(".review-card"));
    expect(card?.style.animation).toBe("none");
    expect(card?.selectorText).toContain(".ask-chip");
  });

  it("ellipsises the first caption line", () => {
    const line = styleOf("review-line", "review-root");
    expect(line.textOverflow).toBe("ellipsis");
    expect(line.whiteSpace).toBe("nowrap");
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
