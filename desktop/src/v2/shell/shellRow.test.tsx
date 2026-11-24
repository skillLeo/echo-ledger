// UX-20 + DES-01 + DES-08 in the shell: one title row at the top of the content column (the page's title, ← →, the
// search), the rail and the panel running to the top (the rail's top cell holds the Mac's window buttons), the rail
// without the logo and Search, a number badge on Activity only, and the avatar labelled Account with its menu.
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useState, type ReactNode } from "react";
import { NavShell, RAIL_FULL_FROM } from "./NavShell";
import { PanelToggle, usePageHistory, type Place } from "./NavRail";
import { usePageTitle } from "../pageTitle";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const setSize = (width: number, height = 900) => {
  Object.defineProperty(window, "innerWidth", { value: width, configurable: true, writable: true });
  Object.defineProperty(window, "innerHeight", { value: height, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

function Titled({ title, count, search }: { title: string; count: number | null; search?: boolean }) {
  const [query, setQuery] = useState("");
  usePageTitle({ title, count, search: search ? { placeholder: "Search recordings", scope: "All recordings", query, setQuery } : undefined });
  return <p data-testid="list">{query || "the list"}</p>;
}

function App({ onPlace = vi.fn(), onHome = vi.fn(), onSearch = vi.fn(), page }: {
  onPlace?: ReturnType<typeof vi.fn>; onHome?: ReturnType<typeof vi.fn>; onSearch?: ReturnType<typeof vi.fn>; page?: (place: Place | null) => ReactNode;
}) {
  const [place, setPlace] = useState<Place | null>("library");
  const panel = (name: string) => <ul aria-label={`${name} views`}><li><button type="button">First {name} view</button></li></ul>;
  return (
    <NavShell place={place} onPlace={(p, again) => { onPlace(p, again); setPlace(p); }} onHome={() => { onHome(); setPlace(null); }}
      status={{ kind: "ready", text: "Ready to record" }} libraryCount={4} activityCount={12} liveDot liveDotWords="recording" setupAttention onSetupHealth={() => undefined}
      onHelp={() => undefined} onRecord={() => undefined} onUpload={() => undefined} onSearch={onSearch} searchKeys="⌘K" layoutFor="server|ayesha@example.test|w1"
      me={{ name: "Ayesha Khan", email: "ayesha@example.test", plan: "Free", usage: "12 of 30 min used this month", color: 4 }}
      account={[{ label: "Theme", onPick: () => undefined }, { label: "Sign out", onPick: () => undefined }]}
      panels={{ library: panel("Library"), projects: panel("Projects"), live: panel("Live"), ask: panel("Ask"), activity: panel("Activity"), settings: panel("Settings") }}>
      <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">The page</h1></header>
      {page ? page(place) : <Titled title="All recordings" count={219} search />}
    </NavShell>
  );
}
const rail = () => within(screen.getByRole("navigation", { name: "Main" }));
const row = () => screen.getByTestId("title-row");
const panel = () => screen.queryByTestId("nav-panel");

describe("the title row in the shell (UX-20)", () => {
  afterEach(() => {
    setSize(1024, 900);
    window.localStorage.clear();
  });

  it("sits at the top of the content column and shows the page's title and count", () => {
    setSize(1440);
    render(<App />);
    const main = screen.getByRole("main");
    expect(main.firstElementChild).toBe(row());
    expect(within(row()).getByRole("heading", { level: 1 }).textContent).toBe("All recordings219");
    // The page's search runs in the row's field.
    expect(within(row()).getByRole("searchbox", { name: "Search recordings" })).toBeInTheDocument();
  });

  it("the rail and the panel run to the top: the rail's top cell (the window's buttons) and the panel's head move the window", () => {
    setSize(1440);
    render(<App />);
    const top = screen.getByRole("navigation", { name: "Main" }).firstElementChild!;
    expect(top).toHaveClass("el-rail__top");
    expect(top).toHaveAttribute("data-tauri-drag-region", "deep");
    expect(top.querySelector("button")).toBeNull();
    // The buttons' own spot is no drag region: Tauri's drag script stops at "false".
    expect(top.querySelector(".el-rail__lights")).toHaveAttribute("data-tauri-drag-region", "false");
    expect(panel()!.querySelector(".el-panel__head")).toHaveAttribute("data-tauri-drag-region", "deep");
    expect(row()).toHaveAttribute("data-tauri-drag-region", "deep");
    // The field takes its own clicks.
    expect(row().querySelector(".el-titlerow__search")).toHaveAttribute("data-tauri-drag-region", "false");
  });

  it("⊟ moves into the row, right after the window's buttons, when the panel is hidden; never into the page's header", () => {
    setSize(1440);
    render(<App />);
    expect(within(row()).queryByRole("button", { name: "Show the Library panel" })).toBeNull();
    fireEvent.click(within(panel()!).getByRole("button", { name: "Hide panel" }));
    expect(panel()).toBeNull();
    const toggle = within(row()).getByRole("button", { name: "Show the Library panel" });
    expect(row().querySelector("button")).toBe(toggle);
    // The page's own header has no toggle while the row has one.
    const head = screen.getByRole("heading", { name: "The page" }).parentElement!;
    expect(within(head).queryByRole("button")).toBeNull();
    // Focus went to it, and it brings the panel back.
    expect(toggle).toHaveFocus();
    fireEvent.click(toggle);
    expect(panel()).not.toBeNull();
  });

  it("← → walk the places visited (greyed when there is nowhere to go), and ⌘[ ⌘] too", () => {
    setSize(1440);
    const onPlace = vi.fn();
    render(<App onPlace={onPlace} page={() => null} />);
    const back = () => within(row()).getByRole("button", { name: "Back" });
    const forward = () => within(row()).getByRole("button", { name: "Forward" });
    expect(back()).toBeDisabled();
    expect(forward()).toBeDisabled();
    fireEvent.click(rail().getByRole("button", { name: "Projects" }));
    fireEvent.click(rail().getByRole("button", { name: /^Activity/ }));
    fireEvent.click(back());
    expect(onPlace).toHaveBeenLastCalledWith("projects", false);
    expect(rail().getByRole("button", { name: "Projects" })).toHaveAttribute("aria-current", "page");
    expect(forward()).toBeEnabled();
    fireEvent.keyDown(window, { key: "[", metaKey: true });
    expect(onPlace).toHaveBeenLastCalledWith("library", false);
    expect(back()).toBeDisabled();
    fireEvent.keyDown(window, { key: "]", metaKey: true });
    expect(onPlace).toHaveBeenLastCalledWith("projects", false);
    fireEvent.click(forward());
    expect(onPlace).toHaveBeenLastCalledWith("activity", false);
    expect(forward()).toBeDisabled();
    // A new place after going back drops what was ahead.
    fireEvent.click(back());
    fireEvent.click(rail().getByRole("button", { name: "Ask" }));
    expect(forward()).toBeDisabled();
  });

  it("a page's own step back comes first (an open recording closes before the place changes)", () => {
    setSize(1440);
    const onPlace = vi.fn();
    function Reading() {
      const [open, setOpen] = useState(true);
      usePageHistory(open ? { back: () => setOpen(false) } : null);
      return <p>{open ? "The recording" : "The list"}</p>;
    }
    render(<App onPlace={onPlace} page={(p) => (p === "library" ? <Reading /> : null)} />);
    const back = within(row()).getByRole("button", { name: "Back" });
    expect(back).toBeEnabled();
    fireEvent.click(back);
    expect(screen.getByText("The list")).toBeInTheDocument();
    expect(onPlace).not.toHaveBeenCalled();
    expect(within(row()).getByRole("button", { name: "Back" })).toBeDisabled();
  });

  it("⌘F searches the page's view in the row (with its scope); a page that takes ⌘F itself keeps it", () => {
    setSize(1440);
    const onSearch = vi.fn();
    const { unmount } = render(<App onSearch={onSearch} />);
    fireEvent.keyDown(window, { key: "f", metaKey: true });
    const field = within(row()).getByRole("searchbox", { name: "Search recordings" });
    expect(field).toHaveFocus();
    expect(row().querySelector(".el-titlerow__scope")!.textContent).toBe("In: All recordings");
    fireEvent.change(field, { target: { value: "invoice" } });
    expect(screen.getByTestId("list").textContent).toBe("invoice");
    unmount();
    render(<App onSearch={onSearch} page={() => null} />);
    const taken = new KeyboardEvent("keydown", { key: "f", metaKey: true, bubbles: true, cancelable: true });
    taken.preventDefault();
    window.dispatchEvent(taken);
    expect(onSearch).not.toHaveBeenCalled();
    // A page without a search of its own: ⌘F searches everything.
    fireEvent.keyDown(window, { key: "f", metaKey: true });
    expect(onSearch).toHaveBeenCalled();
  });

  it("the field opens the search of everything when the page has none", () => {
    setSize(1440);
    const onSearch = vi.fn();
    render(<App onSearch={onSearch} page={() => null} />);
    fireEvent.click(within(row()).getByRole("button", { name: /Search recordings/ }));
    expect(onSearch).toHaveBeenCalled();
  });

  it("a hairline under the row once the page under it has scrolled, and none at the top", () => {
    setSize(1440);
    render(<App page={() => <div data-testid="scroller" style={{ overflow: "auto" }}>long</div>} />);
    const scroller = screen.getByTestId("scroller");
    Object.defineProperty(scroller, "scrollTop", { value: 120, configurable: true, writable: true });
    fireEvent.scroll(scroller);
    expect(row()).toHaveClass("el-titlerow--scrolled");
    scroller.scrollTop = 0;
    fireEvent.scroll(scroller);
    expect(row()).not.toHaveClass("el-titlerow--scrolled");
  });
});

describe("the rail (DES-01) and the avatar (DES-08)", () => {
  afterEach(() => {
    setSize(1024, 900);
    window.localStorage.clear();
  });

  it("no logo and no Search: Record, the places, then Setup, Help, Settings and Account; only Activity has a number", () => {
    setSize(1440);
    render(<App />);
    const names = rail().getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
    expect(names).toEqual([
      "Record", "More ways to record",
      "Library", "Projects", "Live, recording", "Ask", "Activity, 12",
      "Setup, needs setup", "Help", "Settings", "Account, Ayesha Khan",
    ]);
    const activity = rail().getByRole("button", { name: "Activity, 12" });
    expect(activity.querySelector(".el-rail__count")!.textContent).toBe("12");
    expect(rail().getByRole("button", { name: "Library" }).querySelector(".el-rail__count, .el-rail__dot")).toBeNull();
    // Dots: Live's (red, recording) and Setup's (something to do).
    expect(rail().getByRole("button", { name: "Live, recording" }).querySelector(".el-rail__dot--live")).not.toBeNull();
    expect(rail().getByRole("button", { name: "Setup, needs setup" }).querySelector(".el-rail__dot")).not.toBeNull();
    // Every item keeps its name under its icon; the selected one has its pill.
    expect(rail().getByRole("button", { name: "Library" }).querySelector(".el-rail__label")!.textContent).toBe("Library");
    expect(rail().getByRole("button", { name: "Library" }).querySelector(".el-rail__icon")).not.toBeNull();
  });

  it("each rail item's tooltip: its name and its shortcut, after 500 ms", () => {
    vi.useFakeTimers();
    try {
      setSize(1440);
      render(<App />);
      fireEvent.pointerEnter(rail().getByRole("button", { name: "Projects" }));
      act(() => vi.advanceTimersByTime(500));
      expect(screen.getByRole("tooltip").textContent).toMatch(/^Projects (⌘|Ctrl\+)2$/);
      // No browser tooltip on top of it.
      expect(rail().getByRole("button", { name: "Projects" })).not.toHaveAttribute("title");
      fireEvent.pointerLeave(rail().getByRole("button", { name: "Projects" }));
      fireEvent.pointerEnter(rail().getByRole("button", { name: "Account, Ayesha Khan" }));
      act(() => vi.advanceTimersByTime(500));
      expect(screen.getByRole("tooltip").textContent).toBe("Account, theme and settings");
    } finally {
      vi.useRealTimers();
    }
  });

  it("the avatar: a 28 px circle in its colour, labelled Account; its menu opens with the person's avatar, name, email and plan", () => {
    setSize(1440);
    render(<App />);
    const me = rail().getByRole("button", { name: "Account, Ayesha Khan" });
    expect(me.querySelector(".el-avatar")).toHaveClass("el-avatar--rail");
    expect(me.querySelector(".el-avatar")).toHaveAttribute("data-colour", "4");
    expect(me.querySelector(".el-avatar")!.textContent).toBe("AK");
    expect(me.closest(".el-rail__me")!.querySelector(".el-rail__label")!.textContent).toBe("Account");
    fireEvent.click(me);
    const menu = screen.getByRole("menu", { name: "Account, Ayesha Khan" });
    const head = menu.querySelector(".el-menu__header")!;
    expect(head.querySelector(".el-avatar")).toHaveClass("el-avatar--menu");
    expect(head.textContent).toContain("Ayesha Khan");
    expect(head.textContent).toContain("ayesha@example.test");
    expect(head.textContent).toContain("Free · 12 of 30 min used this month");
    expect([...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.textContent)).toEqual(["Theme", "Sign out"]);
  });

  it("a short window: Help and Setup move into the avatar's menu, every label stays and nothing scrolls", () => {
    setSize(1440, RAIL_FULL_FROM - 1);
    render(<App />);
    expect(rail().queryByRole("button", { name: "Help" })).toBeNull();
    expect(rail().queryByRole("button", { name: /^Setup/ })).toBeNull();
    expect(rail().getByRole("button", { name: "Library" }).querySelector(".el-rail__label")).toBeVisible();
    fireEvent.click(rail().getByRole("button", { name: "Account, Ayesha Khan" }));
    const items = [...screen.getByRole("menu", { name: "Account, Ayesha Khan" }).querySelectorAll('[role^="menuitem"]')].map((m) => m.textContent);
    expect(items).toEqual(["Setup & health", "Help", "Theme", "Sign out"]);
    act(() => setSize(1440, RAIL_FULL_FROM));
    expect(rail().getByRole("button", { name: "Help" })).toBeInTheDocument();
  });
});
