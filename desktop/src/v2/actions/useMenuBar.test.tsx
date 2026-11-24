// FIX-05: the Mac menu bar kept in step with the app, and every pick from a menu the system drew run by the registry.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render } from "@testing-library/react";
import { callsOf, emitEvent, resetTauriMock, respond } from "../../test/tauri";
import { registerAction } from "./registry";
import { MENU_BAR_SETTLE_MS, useMenuBar } from "./useMenuBar";
import { parsePick, setMenuPart, useMenuPart } from "./menuState";
import type { NativeMenuBar } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

function App({ platform, state }: { platform: string; state?: Record<string, boolean> }) {
  useMenuBar(platform);
  useMenuPart({ state: state ?? {} });
  return <input aria-label="A field" />;
}
const settle = () => act(() => { vi.advanceTimersByTime(MENU_BAR_SETTLE_MS + 1); });
const lastBar = () => callsOf("app_menu_set").at(-1)?.bar as NativeMenuBar;
const item = (bar: NativeMenuBar, title: string, action: string) =>
  bar.menus.find((m) => m.title === title)!.items.find((e) => (e.kind === "item" || e.kind === "native") && e.action === action) as Record<string, unknown>;
const offs: (() => void)[] = [];

beforeEach(() => {
  resetTauriMock();
  respond("app_menu_set", null);
  vi.useFakeTimers();
});
afterEach(() => {
  offs.splice(0).forEach((off) => off());
  vi.useRealTimers();
});

describe("the menu bar in step with the app (FIX-05)", () => {
  it("on a Mac it is drawn once the state settles, and again when it changes", async () => {
    const { rerender } = render(<App platform="macos" state={{ sidebarHidden: false }} />);
    await settle();
    expect(callsOf("app_menu_set")).toHaveLength(1);
    expect(lastBar().menus.map((m) => m.title)).toEqual(["EchoLedger", "File", "Edit", "View", "Go", "Recording", "Live", "Window", "Help"]);
    // Nothing changed: not drawn again.
    rerender(<App platform="macos" state={{ sidebarHidden: false }} />);
    await settle();
    expect(callsOf("app_menu_set")).toHaveLength(1);
    // The sidebar hidden: the item says Show Sidebar.
    offs.push(registerAction("view.sidebar", () => undefined));
    rerender(<App platform="macos" state={{ sidebarHidden: true }} />);
    await settle();
    expect(item(lastBar(), "View", "view.sidebar")).toMatchObject({ label: "Show Sidebar", enabled: true });
  });

  it("a command registered later is no longer greyed", async () => {
    render(<App platform="macos" />);
    await settle();
    expect(item(lastBar(), "Go", "go.library")).toMatchObject({ enabled: false });
    act(() => { offs.push(registerAction("go.library", () => undefined)); });
    await settle();
    expect(item(lastBar(), "Go", "go.library")).toMatchObject({ enabled: true });
  });

  it("typing in a field greys Space, ⌘← and ⌘→", async () => {
    offs.push(registerAction("recording.playPause", () => undefined));
    setMenuPart("selected", { targets: ["r1"], state: { hasAudio: true } });
    const { getByLabelText } = render(<App platform="macos" />);
    await settle();
    const play = () => lastBar().menus.find((m) => m.title === "Recording")!.items.find((e) => e.kind === "item" && e.action === "recording.playPause") as Record<string, unknown>;
    expect(play()).toMatchObject({ enabled: true });
    act(() => { (getByLabelText("A field") as HTMLInputElement).focus(); });
    await settle();
    expect(play()).toMatchObject({ enabled: false });
    setMenuPart("selected", null);
  });

  it("Windows has no menu bar: nothing is drawn", async () => {
    render(<App platform="windows" />);
    await settle();
    expect(callsOf("app_menu_set")).toHaveLength(0);
  });

  it("a pick from the system's menus runs its action once, with its choice", async () => {
    const sort = vi.fn();
    const library = vi.fn();
    offs.push(registerAction("view.sortBy", sort), registerAction("go.library", library));
    // Two parts of the window ask for the picks: one listener all the same.
    render(<><App platform="macos" /><App platform="macos" /></>);
    await act(() => emitEvent("menu:action", "go.library"));
    expect(library).toHaveBeenCalledTimes(1);
    await act(() => emitEvent("menu:action", "view.sortBy#oldest"));
    expect(sort).toHaveBeenCalledWith(expect.objectContaining({ value: "oldest", platform: "macos" }));
    expect(parsePick("recording.labels#Clients #2")).toEqual({ id: "recording.labels", value: "Clients #2" });
  });
});
