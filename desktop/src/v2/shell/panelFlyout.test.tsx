// UX-02 (PLAN_V2_1_POLISH, flag v21_panel_flyout): the panel can always be opened. From 1200 px it is docked; from 760
// to 1199 px it is hidden and opens as a flyout over the page, with a scrim, closing on Esc, a press outside or a
// choice, and a pin docks it (remembered for small windows). Collapsed is 0 px wide: the toggle sits at the start of the
// page header. ⌃⌘S and ⌘\ (Ctrl+\) toggle it.
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import { DOCK_FROM, NavShell } from "./NavShell";
import { PanelToggle, type Place } from "./NavRail";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const setWidth = (width: number) => {
  Object.defineProperty(window, "innerWidth", { value: width, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

function App({ flag = true }: { flag?: boolean }) {
  const [place, setPlace] = useState<Place | null>("library");
  const panel = (name: string) => <ul aria-label={`${name} views`}><li><button type="button">First {name} view</button></li><li><button type="button">Second {name} view</button></li></ul>;
  return (
    <NavShell place={place} onPlace={(p) => setPlace(p)} onHome={() => setPlace(null)} panelFlyout={flag}
      status={{ kind: "ready", text: "Ready to record" }} onSetupHealth={() => undefined}
      panels={{ library: panel("Library"), projects: panel("Projects"), live: panel("Live"), ask: panel("Ask"), activity: panel("Activity"), settings: panel("Settings") }}>
      <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">The page</h1></header>
    </NavShell>
  );
}
const panel = () => screen.queryByTestId("nav-panel");
const toggle = () => screen.queryByRole("button", { name: /^Show the .* panel$/ });

describe("the panel can always be opened (UX-02)", () => {
  afterEach(() => {
    setWidth(1024);
    window.localStorage.clear();
  });

  it("docks from 1200 px; below it is hidden, with the toggle at the start of the page header", () => {
    expect(DOCK_FROM).toBe(1200);
    setWidth(1199);
    render(<App />);
    expect(panel()).toBeNull();
    const header = screen.getByRole("heading", { name: "The page" }).parentElement!;
    expect(within(header).getByRole("button", { name: "Show the Library panel" })).toBe(header.firstElementChild?.querySelector("button"));
    act(() => setWidth(1200));
    expect(panel()).not.toBeNull();
    expect(panel()).not.toHaveClass("el-panel--flyout");
    expect(toggle()).toBeNull();
  });

  it("at 1024 every panel item is two clicks away: the toggle (or the place in the rail), then the item", () => {
    setWidth(1024);
    render(<App />);
    fireEvent.click(toggle()!);
    expect(panel()).toHaveClass("el-panel--flyout");
    expect(within(panel()!).getByRole("button", { name: "Second Library view" })).toBeVisible();
    // Another place: one click on it in the rail opens its flyout, the second picks.
    fireEvent.keyDown(document, { key: "Escape" });
    expect(panel()).toBeNull();
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Projects" }));
    expect(within(panel()!).getByRole("button", { name: "Second Projects view" })).toBeInTheDocument();
  });

  it("the flyout has a scrim; a press on it, Esc or a choice closes it", async () => {
    setWidth(1024);
    render(<App />);
    fireEvent.click(toggle()!);
    const scrim = screen.getByTestId("panel-scrim");
    fireEvent.pointerDown(scrim);
    expect(panel()).toBeNull();
    expect(screen.queryByTestId("panel-scrim")).toBeNull();
    fireEvent.click(toggle()!);
    fireEvent.keyDown(document, { key: "Escape" });
    expect(panel()).toBeNull();
    fireEvent.click(toggle()!);
    fireEvent.click(within(panel()!).getByRole("button", { name: "First Library view" }));
    await act(() => new Promise((r) => setTimeout(r, 10)));
    expect(panel()).toBeNull();
  });

  it("a pin docks it in a small window, remembered for small windows; unpinned it is a flyout again", () => {
    setWidth(1024);
    const { unmount } = render(<App />);
    fireEvent.click(toggle()!);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Pin the panel" }));
    expect(panel()).not.toHaveClass("el-panel--flyout");
    expect(screen.queryByTestId("panel-scrim")).toBeNull();
    unmount();
    render(<App />);
    expect(panel()).not.toBeNull();
    expect(panel()).not.toHaveClass("el-panel--flyout");
    fireEvent.click(within(panel()!).getByRole("button", { name: "Unpin the panel" }));
    expect(panel()).toBeNull();
    expect(toggle()).not.toBeNull();
  });

  it("collapsed is 0 px wide: no column is left, and the toggle in the page header brings it back", () => {
    setWidth(1440);
    const { container } = render(<App />);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Hide panel" }));
    expect(panel()).toBeNull();
    expect(container.querySelector(".el-panel-show")).toBeNull();
    fireEvent.click(toggle()!);
    expect(panel()).not.toBeNull();
    expect(panel()).not.toHaveClass("el-panel--flyout");
  });

  it("⌃⌘S and ⌘\\ toggle it", () => {
    setWidth(1440);
    render(<App />);
    fireEvent.keyDown(window, { key: "s", metaKey: true, ctrlKey: true });
    expect(panel()).toBeNull();
    fireEvent.keyDown(window, { key: "s", metaKey: true, ctrlKey: true });
    expect(panel()).not.toBeNull();
    fireEvent.keyDown(window, { key: "\\", metaKey: true });
    expect(panel()).toBeNull();
  });

  it("without the flag the window is as before: docked from 1100 px, no toggle in the page header", () => {
    setWidth(1150);
    render(<App flag={false} />);
    expect(panel()).not.toBeNull();
    fireEvent.click(within(panel()!).getByRole("button", { name: "Hide panel" }));
    const header = screen.getByRole("heading", { name: "The page" }).parentElement!;
    expect(within(header).queryByRole("button")).toBeNull();
    expect(screen.getByRole("button", { name: "Show the Library panel" })).toHaveClass("el-panel-show");
  });
});
