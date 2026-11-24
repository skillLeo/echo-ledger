import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { fitRow } from "./fitRow";

// The Library's row 2 never cuts a label (the owner's request, Mac 2.0.15): the end's labels go first, then the tabs
// scroll sideways. 8 px always sits between the tabs and the end's controls.
describe("the Library's toolbar row keeps every label whole", () => {
  // Tabs 420 px wide; [Filters] [Sort & display] 230 px with their labels, 72 px as icons.
  const tabs = 420;
  const labelled = 230;
  const icons = 72;

  it("with room for everything, the tabs and both labels show and nothing scrolls", () => {
    expect(fitRow(1000, tabs, labelled, icons)).toEqual({ labels: true, scrolls: false });
    expect(fitRow(tabs + 8 + labelled, tabs, labelled, icons)).toEqual({ labels: true, scrolls: false });
  });

  it("a pixel short, the end's labels go (their icons stay) before any tab moves", () => {
    expect(fitRow(tabs + 8 + labelled - 1, tabs, labelled, icons)).toEqual({ labels: false, scrolls: false });
    expect(fitRow(tabs + 8 + icons, tabs, labelled, icons)).toEqual({ labels: false, scrolls: false });
  });

  it("with no room even for the icons, the tabs scroll sideways; their words never wrap or get cut", () => {
    expect(fitRow(tabs + 8 + icons - 1, tabs, labelled, icons)).toEqual({ labels: false, scrolls: true });
    expect(fitRow(300, tabs, labelled, icons)).toEqual({ labels: false, scrolls: true });
  });

  it("the tabs' and the row's styles: one line each, never squeezed; only a row with no room scrolls, with no fade", () => {
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
    const rule = (selector: string) => {
      const at = css.indexOf(`\n${selector} {`);
      expect(at, selector).toBeGreaterThan(-1);
      return css.slice(at, css.indexOf("}", at));
    };
    expect(rule(".el-lib-tabs__tab")).toMatch(/white-space: nowrap/);
    expect(rule(".el-lib-tabs__tab")).toMatch(/flex: none/);
    expect(rule(".el-lib-tabs")).not.toMatch(/overflow/);
    expect(rule(".el-lib-tabs--scrolls")).toMatch(/overflow-x: auto/);
    expect(rule(".el-lib-tabs--scrolls")).not.toMatch(/gradient|mask/);
    expect(rule(".el-lib-row2__end")).toMatch(/flex: none/);
  });
});
