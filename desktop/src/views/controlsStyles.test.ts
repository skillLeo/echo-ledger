// A3.3 / v1.5 / the call choice: jsdom cannot lay out or animate, so these tests pin the CSS contract of the pill and
// the after-call card: sizes that fit their windows, a solid surface, the 200 ms slide-in, no motion with reduced motion,
// and transparent window backgrounds. The look on a real screen is a manual QA step.
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
    const ring = [...style.sheet!.cssRules].find((r): r is CSSStyleRule => r instanceof CSSStyleRule && r.selectorText === ".rec-dot::after");
    expect(ring?.style.animationName).toBe("rec-pulse");
    const reduced = rulesIn("prefers-reduced-motion");
    const still = reduced.find((r) => r.selectorText === ".rec-dot::after");
    expect(still?.style.animation).toBe("none");
  });
});

describe("after-call styles (the call choice)", () => {
  it("puts the card at the top of the window, centred, next to the pill", () => {
    const root = styleOf("review-root");
    expect(root.padding).toBe("10px");
    expect(root.justifyContent).toBe("center");
    expect(root.alignItems).toBe("flex-start");
    expect(styleOf("call-card", "review-root").maxWidth).toBe("100%");
  });

  it("is a solid surface from the theme, never see-through, radius 16, in the app's font", () => {
    const rule = topLevelRule(".call-card")!;
    expect(rule.style.background).toBe("var(--surface)");
    expect(rule.style.getPropertyValue("backdrop-filter")).toBe("");
    expect(rule.style.borderRadius).toBe("var(--radius-sheet)");
    expect(appCss).toMatch(/--radius-sheet: 16px;/);
    expect(rule.style.boxShadow).toBe("var(--shadow-2)");
    expect(rule.style.fontFamily).toBe("var(--font-sans)");
    expect(rule.style.color).toBe("var(--text)");
  });

  it("has one filled button: Delete and the confirmations' actions are quiet text buttons", () => {
    const quiet = topLevelRule(".call-text-btn {") ?? topLevelRule(".call-text-btn");
    expect(quiet?.style.background).toBe("transparent");
  });

  it("uses the classic radius and shadow tokens, never raw values, in the call choice's rules", () => {
    const ours = [...style.sheet!.cssRules].filter((r): r is CSSStyleRule => r instanceof CSSStyleRule && /\.(call-|rec-menu|rec-choice)/.test(r.selectorText));
    expect(ours.length).toBeGreaterThan(10);
    for (const r of ours) {
      if (r.style.borderRadius) expect(r.style.borderRadius, r.selectorText).toMatch(/^var\(--radius-/);
      if (r.style.boxShadow) expect(r.style.boxShadow, r.selectorText).toMatch(/^var\(--shadow-/);
    }
  });

  it("puts what was done and the name on lines of their own, so the name is never cut to a few letters", () => {
    expect(styleOf("call-done-text", "call-done-row").flexDirection).toBe("column");
    expect(styleOf("call-group-headline", "call-done-row").whiteSpace).toBe("normal");
  });

  it("ellipsises a long name and keeps the length in tabular digits", () => {
    const title = styleOf("call-card-title", "call-card");
    expect(title.textOverflow).toBe("ellipsis");
    expect(title.whiteSpace).toBe("nowrap");
    expect(styleOf("call-card-meta", "call-card").fontVariantNumeric).toBe("tabular-nums");
  });

  it("slides in over 200 ms, decelerating, like the ask chip", () => {
    const card = styleOf("call-card", "review-root");
    expect(card.animationName).toBe("ctl-slide-in");
    expect(card.animationDuration).toBe("var(--motion-base)");
    expect(card.animationTimingFunction).toBe("var(--ease-out)");
    const keyframes = [...style.sheet!.cssRules].find((r): r is CSSKeyframesRule => r instanceof CSSKeyframesRule && r.name === "ctl-slide-in");
    expect(keyframes?.cssRules[0].cssText).toContain("opacity: 0");
  });

  it("counts the 8 s down with a thin line that starts from what is left", () => {
    const keyframes = [...style.sheet!.cssRules].find((r): r is CSSKeyframesRule => r instanceof CSSKeyframesRule && r.name === "call-line");
    expect(keyframes?.cssRules[0].cssText).toContain("var(--from");
    expect(styleOf("call-card-line", "call-card").height).toBe("3px");
  });

  it("does not move and the line stops with reduced motion", () => {
    const reduced = rulesIn("prefers-reduced-motion");
    const cards = reduced.find((r) => r.selectorText.includes(".call-card") && !r.selectorText.includes("line"));
    expect(cards?.style.animation).toBe("none");
    expect(cards?.selectorText).toContain(".ask-chip");
    const line = reduced.find((r) => r.selectorText.includes(".call-card-line"));
    expect(line?.style.animation).toBe("none");
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
