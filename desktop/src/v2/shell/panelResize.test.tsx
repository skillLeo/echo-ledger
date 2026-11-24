// UX-03 [S2] Resizable panels: the place's panel from 200 to 360 px, kept per place for each account on this computer;
// let go below 160 px it closes (as ⊟ does); a double click puts the usual width back.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { NavShell, loadWidths } from "./NavShell";
import { PANEL_CLOSE_BELOW, PANEL_MAX, PANEL_MIN, panelDefault, type Place } from "./NavRail";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ACCOUNT = "server|ayesha@example.test|w1";
const setSize = (width: number) => {
  Object.defineProperty(window, "innerWidth", { value: width, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

function App({ account = ACCOUNT, flyouts = false }: { account?: string; flyouts?: boolean }) {
  const [place, setPlace] = useState<Place | null>("library");
  return (
    <NavShell place={place} onPlace={(p) => setPlace(p)} onHome={() => setPlace(null)} status={{ kind: "ready", text: "Ready to record" }}
      onSetupHealth={() => undefined} layoutFor={account} panelFlyout={flyouts}
      panels={{ library: <p>Library views</p>, projects: <p>Projects list</p>, settings: <p>Groups</p> }}>
      <p>The page</p>
    </NavShell>
  );
}
const edge = (place = "Library") => screen.getByRole("separator", { name: `Resize the ${place} panel` });
const panel = () => screen.getByTestId("nav-panel");
const drag = (from: number, to: number) => {
  fireEvent.pointerDown(edge(), { button: 0, clientX: from });
  fireEvent.pointerMove(edge(), { clientX: to });
  fireEvent.pointerUp(edge(), { clientX: to });
};

describe("resizable panels (UX-03)", () => {
  beforeEach(() => {
    localStorage.clear();
    setSize(1440);
  });
  afterEach(() => cleanup());

  it("the panel's edge: 200 to 360 px, the usual width until one is set", () => {
    expect([PANEL_MIN, PANEL_MAX, PANEL_CLOSE_BELOW]).toEqual([200, 360, 160]);
    expect(panelDefault(true)).toBe(240);
    expect(panelDefault(false)).toBe(260);
    render(<App flyouts />);
    expect(edge()).toHaveAttribute("aria-valuenow", "240");
    expect(panel().style.getPropertyValue("--el-panel-width")).toBe("", "the CSS's usual width");
  });

  it("a drag sets the place's width, kept for this account; each place has its own; a double click resets it", () => {
    const { unmount } = render(<App flyouts />);
    drag(300, 380);
    expect(panel().style.getPropertyValue("--el-panel-width")).toBe("320px");
    expect(edge()).toHaveAttribute("aria-valuenow", "320");
    expect(loadWidths(ACCOUNT)).toEqual({ library: 320 });
    // Projects keeps its own (the usual one).
    fireEvent.click(screen.getByRole("button", { name: "Projects" }));
    expect(edge("Projects")).toHaveAttribute("aria-valuenow", "240");
    fireEvent.keyDown(edge("Projects"), { key: "End" });
    expect(loadWidths(ACCOUNT)).toEqual({ library: 320, projects: 360 });
    unmount();
    // Back again: as it was left.
    render(<App flyouts />);
    expect(panel().style.getPropertyValue("--el-panel-width")).toBe("320px");
    fireEvent.doubleClick(edge());
    expect(panel().style.getPropertyValue("--el-panel-width")).toBe("");
    expect(loadWidths(ACCOUNT)).toEqual({ projects: 360 });
    // Another account starts from the usual widths.
    cleanup();
    render(<App flyouts account="server|bilal@example.test|w1" />);
    expect(edge()).toHaveAttribute("aria-valuenow", "240");
  });

  it("let go below 160 px the panel closes as ⊟ does, and opens again at the width it had", () => {
    render(<App flyouts />);
    drag(300, 340);
    expect(loadWidths(ACCOUNT)).toEqual({ library: 280 });
    drag(300, 150);
    expect(screen.queryByTestId("nav-panel")).toBeNull();
    // Its toggle is in the title row now; the width is kept.
    fireEvent.click(screen.getByRole("button", { name: "Show the Library panel" }));
    expect(panel().style.getPropertyValue("--el-panel-width")).toBe("280px");
  });

  it("in a small window the flyout has the edge too, and let go below 160 px it closes", () => {
    setSize(1000);
    render(<App flyouts />);
    fireEvent.click(screen.getByRole("button", { name: "Show the Library panel" }));
    expect(panel()).toHaveClass("el-panel--flyout");
    drag(300, 120);
    expect(screen.queryByTestId("nav-panel")).toBeNull();
  });

  it("is drawn with tokens: the panel's and the filters' widths from their variables, the edge over the border", async () => {
    const { readFileSync } = await import("node:fs");
    const { dirname, join } = await import("node:path");
    const { fileURLToPath } = await import("node:url");
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
    expect(css).toContain("inline-size: var(--el-panel-width, calc(var(--el-space-64) * 4 + var(--el-space-4)));");
    expect(css).toContain(".el-shell--flyouts .el-panel { inline-size: var(--el-panel-width, calc(var(--el-space-64) * 4 - var(--el-space-16))); }");
    expect(css).toContain("inline-size: var(--el-filter-width, calc(var(--el-space-64) * 5));");
    expect(css).toContain(".el-resize-edge--end { inset-inline-end: calc(-1 * var(--el-space-4)); }");
    expect(css).toContain(".el-resize-edge--start { inset-inline-start: calc(-1 * var(--el-space-4)); }");
    await act(async () => undefined);
  });
});
