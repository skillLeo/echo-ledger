// UX-02 (PLAN_V2_1_POLISH, flag v21_panel_flyout): the panel can always be opened. From 1200 px it is docked; from 760
// to 1199 px it is hidden and opens as a flyout over the page, with a scrim, closing on Esc, a press outside or a
// choice, and a pin docks it (remembered for small windows). Collapsed is 0 px wide: the toggle sits at the start of the
// page header. ⌃⌘S and ⌘\ (Ctrl+\) toggle it.
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useEffect, useState, type ReactNode } from "react";
import { DOCK_FROM, NavShell } from "./NavShell";
import { PanelToggle, type Place } from "./NavRail";
import { SelectionBar } from "../components/SelectionBar";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const setWidth = (width: number) => {
  Object.defineProperty(window, "innerWidth", { value: width, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

function App({ flag = true, layoutFor, page }: { flag?: boolean; layoutFor?: string | null; page?: ReactNode }) {
  const [place, setPlace] = useState<Place | null>("library");
  const panel = (name: string) => <ul aria-label={`${name} views`}><li><button type="button">First {name} view</button></li><li><button type="button">Second {name} view</button></li></ul>;
  return (
    <NavShell place={place} onPlace={(p) => setPlace(p)} onHome={() => setPlace(null)} panelFlyout={flag} layoutFor={layoutFor}
      status={{ kind: "ready", text: "Ready to record" }} onSetupHealth={() => undefined}
      panels={{ library: panel("Library"), projects: panel("Projects"), live: panel("Live"), ask: panel("Ask"), activity: panel("Activity"), settings: panel("Settings") }}>
      <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">The page</h1></header>
      {page}
    </NavShell>
  );
}
const panel = () => screen.queryByTestId("nav-panel");

/** A recording open on the page, closed by Esc from a document listener added before the flyout's (as LibraryV2's is). */
function OpenRecording() {
  const [open, setOpen] = useState(true);
  useEffect(() => {
    const on = (e: KeyboardEvent) => {
      if (open && e.key === "Escape" && !e.defaultPrevented) {
        e.preventDefault();
        setOpen(false);
      }
    };
    document.addEventListener("keydown", on);
    return () => document.removeEventListener("keydown", on);
  }, [open]);
  return open ? <p>The recording is open</p> : <p>The list</p>;
}
/** A selection on the page: the real selection bar, which leaves selection mode on Esc. */
function Selecting() {
  const [on, setOn] = useState(true);
  return on ? <SelectionBar label="Selected recordings" count={2} allSelected={false} onToggleAll={() => undefined} onExit={() => setOn(false)} actions={[]} main={[]} />
    : <p>No selection</p>;
}
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

  it("Esc closes the flyout only: an open recording and a selection behind the scrim stay as they were", () => {
    setWidth(1024);
    render(<App page={<><OpenRecording /><Selecting /></>} />);
    fireEvent.click(toggle()!);
    // From inside the flyout (focus moves into it when it opens).
    expect(panel()!.contains(document.activeElement)).toBe(true);
    fireEvent.keyDown(document.activeElement!, { key: "Escape" });
    expect(panel()).toBeNull();
    expect(screen.getByText("The recording is open")).toBeInTheDocument();
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toBeInTheDocument();
    // From outside it (the rail): the same.
    fireEvent.click(toggle()!);
    const rail = within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Projects" });
    rail.focus();
    fireEvent.keyDown(rail, { key: "Escape" });
    expect(panel()).toBeNull();
    expect(screen.getByText("The recording is open")).toBeInTheDocument();
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toBeInTheDocument();
    // With the flyout closed, Esc is the page's again.
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(screen.getByText("The list")).toBeInTheDocument();
  });

  it("while the flyout is up the page is inert (the rail is not), and focus goes back to the toggle that opened it", () => {
    setWidth(1024);
    render(<App />);
    const main = screen.getByRole("main");
    const opener = toggle()!;
    opener.focus();
    fireEvent.click(opener);
    expect(main.hasAttribute("inert")).toBe(true);
    expect(screen.getByRole("navigation", { name: "Main" }).closest("[inert]")).toBeNull();
    // The toggle stays, saying the flyout is open, so focus has somewhere to go back to.
    expect(opener.isConnected).toBe(true);
    expect(opener).toHaveAttribute("aria-expanded", "true");
    fireEvent.keyDown(document.activeElement!, { key: "Escape" });
    expect(main.hasAttribute("inert")).toBe(false);
    expect(opener).toHaveAttribute("aria-expanded", "false");
    expect(document.activeElement).toBe(opener);
    // Closed from its own ⊟ or by a press on the scrim: back to the toggle too.
    fireEvent.click(opener);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Close panel" }));
    expect(document.activeElement).toBe(opener);
    fireEvent.click(opener);
    fireEvent.pointerDown(screen.getByTestId("panel-scrim"));
    expect(document.activeElement).toBe(opener);
  });

  it("focus never falls to the page's body: hiding or unpinning moves it to the header toggle, showing moves it into the panel", () => {
    setWidth(1440);
    render(<App />);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Hide panel" }));
    expect(document.activeElement).toBe(toggle());
    fireEvent.click(toggle()!);
    expect(panel()!.contains(document.activeElement)).toBe(true);
    act(() => setWidth(1024));
    fireEvent.click(toggle()!);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Pin the panel" }));
    expect(panel()!.contains(document.activeElement)).toBe(true);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Unpin the panel" }));
    expect(panel()).toBeNull();
    expect(document.activeElement).toBe(toggle());
  });

  it("the panels' layout and the pin are each account's own on this computer", () => {
    setWidth(1440);
    const { unmount } = render(<App layoutFor="server|a@x.com|w1" />);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Hide panel" }));
    unmount();
    const other = render(<App layoutFor="server|b@x.com|w1" />);
    expect(panel()).not.toBeNull();
    other.unmount();
    const again = render(<App layoutFor="server|a@x.com|w1" />);
    expect(panel()).toBeNull();
    again.unmount();
    // The pin too.
    setWidth(1024);
    const pinA = render(<App layoutFor="server|a@x.com|w1" />);
    fireEvent.click(toggle()!);
    fireEvent.click(within(panel()!).getByRole("button", { name: "Pin the panel" }));
    pinA.unmount();
    const pinB = render(<App layoutFor="server|b@x.com|w1" />);
    expect(panel()).toBeNull();
    pinB.unmount();
    render(<App layoutFor="server|a@x.com|w1" />);
    expect(panel()).not.toBeNull();
    expect(panel()).not.toHaveClass("el-panel--flyout");
  });

  it("an account's first start keeps what this computer had before (the layout saved for no account)", () => {
    setWidth(1440);
    window.localStorage.setItem("echoledger-nav-panels", JSON.stringify(["library"]));
    render(<App layoutFor="server|a@x.com|w1" />);
    expect(panel()).toBeNull();
  });

  it("the slide-in belongs to the flag: the shell says when it is on", () => {
    setWidth(1024);
    const on = render(<App />);
    expect(on.container.querySelector(".el-shell")).toHaveClass("el-shell--flyouts");
    on.unmount();
    const off = render(<App flag={false} />);
    expect(off.container.querySelector(".el-shell")).not.toHaveClass("el-shell--flyouts");
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
