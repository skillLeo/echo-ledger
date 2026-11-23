// The three levels (PLAN_NAV_3_LEVELS §2, §3, §6): the rail's places with their badges, dots and ⌘1…⌘6; the selected
// place pressed again starts it over; the panel hidden by ⊟ or ⌘\ and remembered per place; under 1100 px a flyout
// from the rail that closes once something is chosen; Record's menu with call recording; the logo opens Home.
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import { FLYOUT_BELOW, NavShell } from "./NavShell";
import type { Place } from "./NavRail";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const setWidth = (width: number) => {
  Object.defineProperty(window, "innerWidth", { value: width, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

function App({ onPlace = vi.fn(), onHome = vi.fn(), toggleCalls = vi.fn() }: { onPlace?: ReturnType<typeof vi.fn>; onHome?: ReturnType<typeof vi.fn>; toggleCalls?: ReturnType<typeof vi.fn> }) {
  const [place, setPlace] = useState<Place | null>("library");
  const panel = (name: string) => <ul aria-label={`${name} views`}><li><button type="button">First {name} view</button></li><li><button type="button" aria-expanded={false}>A group</button></li></ul>;
  return (
    <NavShell place={place} onPlace={(p, again) => { onPlace(p, again); setPlace(p); }} onHome={() => { onHome(); setPlace(null); }}
      status={{ kind: "ready", text: "Ready to record" }} libraryCount={4} activityCount={2} liveDot liveDotWords="recording" setupAttention onSetupHealth={() => undefined}
      onHelp={() => undefined} onRecord={() => undefined} onUpload={() => undefined} callRecording={{ on: true, toggle: toggleCalls }} onSearch={() => undefined} searchKeys="⌘K"
      me={{ name: "Ayesha Khan", email: "ayesha@example.test", plan: null }} account={[{ label: "Sign out", onPick: () => undefined }]}
      panels={{ library: panel("Library"), projects: panel("Projects"), live: panel("Live"), ask: panel("Ask"), activity: panel("Activity"), settings: panel("Settings") }}
      feet={{ library: <p>Synced just now</p> }}>
      <p>The page</p>
    </NavShell>
  );
}
const rail = () => within(screen.getByRole("navigation", { name: "Main" }));
const panel = () => screen.queryByTestId("nav-panel");

describe("the three levels", () => {
  afterEach(() => {
    setWidth(1024);
    window.localStorage.clear();
  });

  it("the rail: Home's logo, Record, the places with their badges and dots, and at its foot Search, Setup, Help, Settings and the avatar", () => {
    setWidth(1440);
    render(<App />);
    const names = rail().getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
    expect(names).toEqual([
      "EchoLedger, Home", "Record", "More ways to record",
      "Library, 4", "Projects", "Live, recording", "Ask", "Activity, 2",
      "Search", "Setup, needs setup", "Help", "Settings", "Ayesha Khan: account",
    ]);
    // ⌘1…⌘6 said on the places.
    expect(rail().getByRole("button", { name: "Library, 4" })).toHaveAttribute("aria-keyshortcuts", "Meta+1 Control+1");
    expect(rail().getByRole("button", { name: "Settings" })).toHaveAttribute("aria-keyshortcuts", "Meta+6 Control+6");
    expect(rail().getByRole("button", { name: "Library, 4" })).toHaveAttribute("aria-current", "page");
    // The panel beside the page: the place's name and its views.
    expect(within(panel()!).getByRole("heading", { name: "Library" })).toBeInTheDocument();
    expect(within(panel()!).getByText("Synced just now")).toBeInTheDocument();
  });

  it("a place goes back as it was left; the selected place pressed again starts it over; the logo opens Home", () => {
    setWidth(1440);
    const onPlace = vi.fn();
    const onHome = vi.fn();
    render(<App onPlace={onPlace} onHome={onHome} />);
    fireEvent.click(rail().getByRole("button", { name: "Projects" }));
    expect(onPlace).toHaveBeenLastCalledWith("projects", false);
    fireEvent.click(rail().getByRole("button", { name: "Projects" }));
    expect(onPlace).toHaveBeenLastCalledWith("projects", true);
    fireEvent.click(rail().getByRole("button", { name: "EchoLedger, Home" }));
    expect(onHome).toHaveBeenCalled();
    expect(rail().getByRole("button", { name: "EchoLedger, Home" })).toHaveAttribute("aria-current", "page");
    // Home has no panel.
    expect(panel()).toBeNull();
  });

  it("⌘1…⌘6 go to the places; ⌘ with the backslash key hides and shows the panel, remembered per place", () => {
    setWidth(1440);
    const onPlace = vi.fn();
    render(<App onPlace={onPlace} />);
    fireEvent.keyDown(window, { key: "4", metaKey: true });
    expect(onPlace).toHaveBeenLastCalledWith("ask", false);
    fireEvent.keyDown(window, { key: "1", ctrlKey: true });
    expect(onPlace).toHaveBeenLastCalledWith("library", false);
    fireEvent.keyDown(window, { key: "\\", metaKey: true });
    expect(panel()).toBeNull();
    expect(JSON.parse(window.localStorage.getItem("echoledger-nav-panels")!)).toEqual(["library"]);
    // Another place keeps its own: shown.
    fireEvent.click(rail().getByRole("button", { name: "Projects" }));
    expect(panel()).not.toBeNull();
    fireEvent.click(rail().getByRole("button", { name: "Library, 4" }));
    expect(panel()).toBeNull();
    // Shown again from its button, or ⊟ hides it.
    fireEvent.click(screen.getByRole("button", { name: "Show the Library panel" }));
    expect(panel()).not.toBeNull();
    fireEvent.click(within(panel()!).getByRole("button", { name: "Hide panel" }));
    expect(panel()).toBeNull();
    // Never while typing.
    fireEvent.click(screen.getByRole("button", { name: "Show the Library panel" }));
    const field = document.createElement("input");
    document.body.appendChild(field);
    fireEvent.keyDown(field, { key: "\\", metaKey: true });
    fireEvent.keyDown(field, { key: "2", metaKey: true });
    expect(panel()).not.toBeNull();
    field.remove();
  });

  it("under 1100 px the panel is a flyout from the rail that closes once something is chosen, or on Esc", async () => {
    // The width is the token's: tokens.css --el-breakpoint-flyout.
    const tokens = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "..", "tokens.css"), "utf8");
    expect(tokens).toContain(`--el-breakpoint-flyout: ${FLYOUT_BELOW}px;`);
    setWidth(1024);
    const onPlace = vi.fn();
    render(<App onPlace={onPlace} />);
    expect(panel()).toBeNull();
    const library = rail().getByRole("button", { name: "Library, 4" });
    expect(library).toHaveAttribute("aria-expanded", "false");
    // The selected place's icon starts it over (the way back) and opens its flyout.
    fireEvent.click(library);
    expect(onPlace).toHaveBeenLastCalledWith("library", true);
    expect(panel()).toHaveClass("el-panel--flyout");
    expect(library).toHaveAttribute("aria-expanded", "true");
    // Pressed again while open: it starts over again and stays open.
    fireEvent.click(library);
    expect(onPlace).toHaveBeenCalledTimes(2);
    expect(panel()).not.toBeNull();
    // A fold stays open; a view chosen closes it.
    fireEvent.click(within(panel()!).getByRole("button", { name: "A group" }));
    expect(panel()).not.toBeNull();
    fireEvent.click(within(panel()!).getByRole("button", { name: "First Library view" }));
    await act(() => new Promise((r) => setTimeout(r, 10)));
    expect(panel()).toBeNull();
    // Another place: it goes there and its flyout opens.
    fireEvent.click(rail().getByRole("button", { name: "Projects" }));
    expect(onPlace).toHaveBeenLastCalledWith("projects", false);
    expect(panel()).toHaveClass("el-panel--flyout");
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(panel()).toBeNull();
  });

  it("the flyout's focus: back to its rail item on Esc, a press outside or ⊟; to the page after a choice", async () => {
    setWidth(1024);
    render(<App />);
    const library = () => rail().getByRole("button", { name: "Library, 4" });
    const open = () => {
      library().focus();
      fireEvent.click(library());
      expect(panel()).not.toBeNull();
      // Focus moves into it.
      expect(panel()!.contains(document.activeElement)).toBe(true);
    };
    open();
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(library()).toHaveFocus();
    open();
    fireEvent.pointerDown(screen.getByText("The page"));
    expect(panel()).toBeNull();
    expect(library()).toHaveFocus();
    open();
    fireEvent.click(within(panel()!).getByRole("button", { name: "Close panel" }));
    expect(library()).toHaveFocus();
    open();
    fireEvent.click(within(panel()!).getByRole("button", { name: "First Library view" }));
    await act(() => new Promise((r) => setTimeout(r, 20)));
    expect(document.activeElement).toBe(screen.getByRole("main"));
  });

  it("in a narrow window ⌘ with the backslash key opens the flyout from where focus is and Esc gives it back there", () => {
    setWidth(1024);
    render(<App />);
    // From the page.
    const page = document.createElement("button");
    page.textContent = "On the page";
    screen.getByRole("main").appendChild(page);
    page.focus();
    fireEvent.keyDown(page, { key: "\\", metaKey: true });
    expect(panel()).toHaveClass("el-panel--flyout");
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(panel()).toBeNull();
    expect(page).toHaveFocus();
    // From nowhere: the place's rail item takes it back; the key again closes it there.
    page.blur();
    fireEvent.keyDown(document.body, { key: "\\", metaKey: true });
    expect(panel()).not.toBeNull();
    fireEvent.keyDown(document.body, { key: "\\", metaKey: true });
    expect(panel()).toBeNull();
    expect(rail().getByRole("button", { name: "Library, 4" })).toHaveFocus();
  });

  it("in a short window the rail keeps its icons only, every place and its foot still named (the old sidebar's short foot)", () => {
    setWidth(1440);
    Object.defineProperty(window, "innerHeight", { value: 640, configurable: true, writable: true });
    window.dispatchEvent(new Event("resize"));
    render(<App />);
    expect(document.querySelector(".el-shell")).toHaveClass("el-shell--short-rail");
    for (const name of ["Library, 4", "Activity, 2", "Search", "Setup, needs setup", "Help", "Settings", "Ayesha Khan: account"]) {
      expect(rail().getByRole("button", { name })).toBeInTheDocument();
    }
    // The panel's foot (the Library's Trash and sync line, the status) is the panel's, under its scrolling list.
    expect(within(panel()!).getByText("Synced just now").closest(".el-panel__foot")).not.toBeNull();
    Object.defineProperty(window, "innerHeight", { value: 900, configurable: true, writable: true });
    act(() => { window.dispatchEvent(new Event("resize")); });
    expect(document.querySelector(".el-shell")).not.toHaveClass("el-shell--short-rail");
  });

  it("Record's menu: Record now, Import audio files, Call recording on or off, the Record page", () => {
    setWidth(1440);
    const toggleCalls = vi.fn();
    render(<App toggleCalls={toggleCalls} />);
    fireEvent.click(rail().getByRole("button", { name: "More ways to record" }));
    const menu = within(screen.getByRole("menu", { name: "More ways to record" }));
    expect([...screen.getByRole("menu", { name: "More ways to record" }).querySelectorAll('[role^="menuitem"]')].map((m) => m.textContent)).toEqual(
      ["Record now", "Import audio files…", "Call recording", "Record page"]);
    expect(menu.getByRole("menuitemcheckbox", { name: "Call recording" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(menu.getByRole("menuitemcheckbox", { name: "Call recording" }));
    expect(toggleCalls).toHaveBeenCalled();
  });
});
