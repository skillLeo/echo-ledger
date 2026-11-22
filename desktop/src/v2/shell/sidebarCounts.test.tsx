// The sidebar's counts (S2, the owner's report: grey numbers on a teal pill): a smart folder's count is quiet text with
// no pill, in the secondary colour; only a count that needs attention (Activity's) keeps the accent pill. Each count's
// text reads at 4.5:1 or more on the background it actually sits on, in light and dark, from the stylesheet's tokens.
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { render } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { NavItem } from "../components";
import { BellIcon } from "../components/icons";

const here = dirname(fileURLToPath(import.meta.url));
const v2Css = readFileSync(join(here, "..", "styles", "v2.css"), "utf8");
const tokensCss = readFileSync(join(here, "..", "..", "tokens.css"), "utf8");

let style: HTMLStyleElement;
beforeAll(() => {
  style = document.createElement("style");
  style.textContent = v2Css;
  document.head.appendChild(style);
});
afterAll(() => style.remove());

/** The role tokens of one theme: the light ones at :root, the dark ones laid over them (`:root[data-theme="dark"]`). */
function tokens(theme: "light" | "dark"): Record<string, string> {
  const block = (selector: string) => {
    const at = tokensCss.indexOf(`${selector} {`);
    const body = tokensCss.slice(at, tokensCss.indexOf("\n}", at));
    return Object.fromEntries([...body.matchAll(/(--[\w-]+):\s*(#[0-9a-f]{6})\b/gi)].map((m) => [m[1], m[2].toLowerCase()]));
  };
  const light = block(":root");
  return theme === "light" ? light : { ...light, ...block(':root[data-theme="dark"]') };
}

function luminance(hex: string): number {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
function contrast(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p);
  return (x + 0.05) / (y + 0.05);
}

/** The markup the shell draws: Activity's count (needs attention) and a place's count (a smart folder). */
function drawSidebar() {
  render(
    <nav className="el-root el-sidebar">
      <NavItem icon={BellIcon} label="Activity" count={6} />
      <ul className="el-nav-sub">
        <li>
          <button type="button" className="el-nav-item el-nav-item--sub el-nav-item--place" aria-label="Inbox, 12">
            <span className="el-nav-item__label">Inbox</span>
            <span className="el-nav-item__count el-caption el-num" data-testid="place-count">12</span>
          </button>
        </li>
      </ul>
    </nav>,
  );
  const counts = document.querySelectorAll<HTMLElement>(".el-nav-item__count");
  return { attention: counts[0], place: document.querySelector<HTMLElement>("[data-testid=place-count]")! };
}

/** A selector's specificity as one number (ids, then classes, attributes and pseudo-classes, then elements). */
function specificity(selector: string): number {
  const ids = (selector.match(/#[\w-]+/g) ?? []).length;
  const classes = (selector.match(/\.[\w-]+|\[[^\]]+\]|:(?!:)[\w-]+(\([^)]*\))?/g) ?? []).length;
  const elements = (selector.replace(/\.[\w-]+|\[[^\]]+\]|:[\w-]+(\([^)]*\))?|#[\w-]+/g, " ").match(/[a-z][\w-]*/gi) ?? []).length;
  return ids * 10_000 + classes * 100 + elements;
}

/** The value the cascade gives `el` for `prop` from the stylesheet's top-level rules (the highest specificity, then the
 * last one), as written: jsdom does not resolve var() in shorthands, so the declarations themselves are compared. */
function cascaded(el: Element, prop: string): string | null {
  let best: { spec: number; order: number; value: string } | null = null;
  [...style.sheet!.cssRules].forEach((rule, order) => {
    if (!(rule instanceof CSSStyleRule)) return;
    const value = rule.style.getPropertyValue(prop);
    if (!value) return;
    for (const selector of rule.selectorText.split(",").map((x) => x.trim())) {
      let matches = false;
      try {
        matches = el.matches(selector);
      } catch {
        matches = false;
      }
      if (!matches) continue;
      const spec = specificity(selector);
      if (!best || spec > best.spec || (spec === best.spec && order >= best.order)) best = { spec, order, value: value.trim() };
    }
  });
  return best ? (best as { value: string }).value : null;
}

describe("the sidebar's counts", () => {
  it("a smart folder's count is quiet text: no accent background, no pill, the secondary text colour", () => {
    const { place } = drawSidebar();
    expect(cascaded(place, "background")).toBe("none");
    expect(cascaded(place, "background")).not.toContain("accent");
    expect(cascaded(place, "border-radius")).toMatch(/^0(px)?$/);
    expect(cascaded(place, "padding-inline")).toMatch(/^0(px)?$/);
    expect(cascaded(place, "color")).toBe("var(--el-role-text-secondary)");
  });

  it("only a count that needs attention keeps the accent pill", () => {
    const { attention } = drawSidebar();
    expect(cascaded(attention, "background")).toBe("var(--el-role-accent-default)");
    expect(cascaded(attention, "color")).toBe("var(--el-role-text-on-accent)");
  });

  describe.each(["light", "dark"] as const)("in %s", (theme) => {
    const t = tokens(theme);
    // A place's count sits on the sidebar, on its row's hover, or on the current row; Activity's on its own pill.
    const pairs: [string, string, string][] = [
      ["a smart folder's count on the sidebar", "--el-role-text-secondary", "--el-role-bg-subtle"],
      ["a smart folder's count on its row's hover", "--el-role-text-secondary", "--el-role-border-default"],
      ["a smart folder's count on the current row", "--el-role-text-secondary", "--el-role-bg-surface"],
      ["Activity's count on its accent pill", "--el-role-text-on-accent", "--el-role-accent-default"],
    ];
    it.each(pairs)("%s reads at 4.5:1 or more", (_what, fg, bg) => {
      expect(t[fg], fg).toMatch(/^#[0-9a-f]{6}$/);
      expect(t[bg], bg).toMatch(/^#[0-9a-f]{6}$/);
      expect(contrast(t[fg], t[bg])).toBeGreaterThanOrEqual(4.5);
    });

    it("the grey-on-teal the owner saw would not have passed (the test catches it)", () => {
      expect(contrast(t["--el-role-text-secondary"], t["--el-role-accent-default"])).toBeLessThan(4.5);
    });
  });
});
