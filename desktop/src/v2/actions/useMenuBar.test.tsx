// FIX-05: the Mac menu bar kept in step with the app (a leaf of its own), and every pick from a menu the system drew
// heard once: a context menu's (numbered) by its menu only, any other run by the registry with the focused page's kind.
import { useEffect } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render } from "@testing-library/react";
import { callsOf, emitEvent, resetTauriMock, respond } from "../../test/tauri";
import { canRun, registerAction } from "./registry";
import { MENU_BAR_SETTLE_MS, MenuBar, useMenuBar } from "./useMenuBar";
import { PICK_HOLD_MS, listenToMenuPicks, mayWait, menuPicksReady, menuSnapshot, parsePick, setMenuPart, setPickedHandler, useMenuPart } from "./menuState";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";
import type { NativeMenuBar } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

/** The window: its one listener (MainApp's), the bar, a part's state, a field and two buttons. */
function App({ platform, state }: { platform: string; state?: Record<string, boolean> }) {
  useEffect(() => listenToMenuPicks(platform === "macos" ? "macos" : "windows"), [platform]);
  useMenuBar(platform);
  useMenuPart({ state: state ?? {} });
  return <><input aria-label="A field" /><button type="button">One</button><button type="button">Two</button></>;
}
const settle = () => act(() => { vi.advanceTimersByTime(MENU_BAR_SETTLE_MS + 1); });
const lastBar = () => callsOf("app_menu_set").at(-1)?.bar as NativeMenuBar;
const item = (bar: NativeMenuBar, title: string, action: string) =>
  bar.menus.find((m) => m.title === title)!.items.find((e) => (e.kind === "item" || e.kind === "native") && e.action === action) as Record<string, unknown>;
const offs: (() => void)[] = [];
/** The app's own source (no tests), read from disk, sorted. */
function sourceFiles(): Record<string, string> {
  const root = join(dirname(fileURLToPath(import.meta.url)), "..", "..", "..");
  const out: Record<string, string> = {};
  const walk = (dir: string) => readdirSync(dir).sort().forEach((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) walk(path);
    else if (/\.(ts|tsx)$/.test(name) && !/\.test\.(ts|tsx)$/.test(name) && !path.includes(`${join("src", "test")}`)) out[relative(root, path)] = readFileSync(path, "utf8");
  });
  walk(join(root, "src"));
  return out;
}

beforeEach(() => {
  resetTauriMock();
  respond("app_menu_set", null);
  vi.useFakeTimers();
});
afterEach(() => {
  offs.splice(0).forEach((off) => off());
  // Unmounted while the clock is ours, so classic's bar coming back is this test's, not the next one's.
  cleanup();
  vi.runOnlyPendingTimers();
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

  it("a pick from the system's menus runs its action once, with its choice or its thing", async () => {
    const sort = vi.fn();
    const library = vi.fn();
    const rename = vi.fn();
    offs.push(registerAction("view.sortBy", sort), registerAction("go.library", library), registerAction("project.rename", rename));
    // Two parts of the window ask for the picks: one listener all the same, so each pick runs once.
    render(<><App platform="macos" /><App platform="macos" /></>);
    await act(() => emitEvent("menu:action", "go.library"));
    expect(library).toHaveBeenCalledTimes(1);
    await act(() => emitEvent("menu:action", "view.sortBy=oldest"));
    expect(sort).toHaveBeenCalledTimes(1);
    expect(sort).toHaveBeenCalledWith(expect.objectContaining({ value: "oldest", platform: "macos" }));
    await act(() => emitEvent("menu:action", "project.rename@p1"));
    expect(rename).toHaveBeenCalledTimes(1);
    expect(rename).toHaveBeenCalledWith(expect.objectContaining({ targets: ["p1"] }));
    // Not well formed: nothing runs.
    await act(() => emitEvent("menu:action", "view.sortBy=a=b"));
    await act(() => emitEvent("menu:action", "project.rename@p@1"));
    expect(sort).toHaveBeenCalledTimes(1);
    expect(rename).toHaveBeenCalledTimes(1);
  });

  it("a pick whose command isn't there yet (the window just built) waits for it, runs once, and is let go after a while", async () => {
    render(<App platform="macos" />);
    // New Recording picked while the window was being built: nothing here answers it yet.
    const record = vi.fn();
    await act(() => emitEvent("menu:action", "file.newRecording"));
    expect(record).not.toHaveBeenCalled();
    // The shell registers its commands: it runs, once.
    act(() => { offs.push(registerAction("file.newRecording", record)); });
    act(() => { vi.advanceTimersByTime(1); });
    expect(record).toHaveBeenCalledTimes(1);
    act(() => { offs.push(registerAction("go.projects", () => undefined)); });
    act(() => { vi.advanceTimersByTime(1); });
    expect(record).toHaveBeenCalledTimes(1);
    // Only the latest waits: an older one is replaced.
    const library = vi.fn();
    const trash = vi.fn();
    await act(() => emitEvent("menu:action", "go.library"));
    await act(() => emitEvent("menu:action", "go.trash"));
    act(() => { offs.push(registerAction("go.library", library), registerAction("go.trash", trash)); });
    act(() => { vi.advanceTimersByTime(1); });
    expect(library).not.toHaveBeenCalled();
    expect(trash).toHaveBeenCalledTimes(1);
    // Nothing comes for it (the lock screen): let go after a while, never run later.
    const shared = vi.fn();
    await act(() => emitEvent("menu:action", "go.shared"));
    act(() => { vi.advanceTimersByTime(PICK_HOLD_MS + 1); });
    act(() => { offs.push(registerAction("go.shared", shared)); });
    act(() => { vi.advanceTimersByTime(1); });
    expect(shared).not.toHaveBeenCalled();
  });

  it("only a pick that acts on no recording waits: a held Move to Trash never runs later on another selection", async () => {
    render(<App platform="macos" />);
    setMenuPart("page", { targets: ["r1"] });
    const trash = vi.fn();
    await act(() => emitEvent("menu:action", "recording.moveToTrash"));
    act(() => setMenuPart("page", { targets: ["r2"] }));
    act(() => { offs.push(registerAction("recording.moveToTrash", trash)); });
    act(() => { vi.advanceTimersByTime(1); });
    expect(trash).not.toHaveBeenCalled();
    // A thing of its own (the tree's project) doesn't wait either.
    const rename = vi.fn();
    await act(() => emitEvent("menu:action", "project.rename@p1"));
    act(() => { offs.push(registerAction("project.rename", rename)); });
    act(() => { vi.advanceTimersByTime(1); });
    expect(rename).not.toHaveBeenCalled();
    expect(mayWait({ id: "go.library" })).toBe(true);
    expect(mayWait({ id: "file.newVoiceNote" })).toBe(true);
    expect(mayWait({ id: "view.themeDark" })).toBe(true);
    expect(mayWait({ id: "help.shortcuts" })).toBe(true);
    for (const id of ["recording.moveToTrash", "recording.pin", "file.rename", "file.export", "recording.deleteNow"]) expect(mayWait({ id }), id).toBe(false);
    setMenuPart("page", null);
  });

  it("a context menu's pick (numbered) goes to its menu only, never through the registry; others never go to it", async () => {
    const pin = vi.fn();
    const labels = vi.fn();
    offs.push(registerAction("recording.pin", pin), registerAction("recording.labels", labels));
    setMenuPart("page", { targets: ["r1"] });
    const taken: unknown[] = [];
    let claim = true;
    offs.push(setPickedHandler((payload, pick) => {
      taken.push([payload, pick]);
      return claim;
    }));
    render(<App platform="macos" />);
    // Claimed: run by its menu, not the registry.
    await act(() => emitEvent("menu:action", "recording.labels@r1=clients~4"));
    expect(taken).toEqual([["recording.labels@r1=clients~4", { id: "recording.labels", target: "r1", value: "clients", menu: 4 }]]);
    // Not claimed (a menu let go, an earlier one's): dropped, still never the registry's.
    claim = false;
    await act(() => emitEvent("menu:action", "recording.pin~3"));
    expect(taken).toHaveLength(2);
    offs.splice(0, 0, setPickedHandler(null));
    await act(() => emitEvent("menu:action", "recording.pin~5"));
    expect(pin).not.toHaveBeenCalled();
    expect(labels).not.toHaveBeenCalled();
    // A pick with no number (the menu bar, Record's menu) is the registry's, never the menu's: run once.
    taken.length = 0;
    offs.push(setPickedHandler((payload, pick) => { taken.push([payload, pick]); return true; }));
    await act(() => emitEvent("menu:action", "recording.pin"));
    expect(taken).toEqual([]);
    expect(pin).toHaveBeenCalledTimes(1);
    expect(pin).toHaveBeenCalledWith(expect.objectContaining({ targets: ["r1"] }));
    setMenuPart("page", null);
  });

  it("the menu bar runs and greys for the focused page's kind, with its selection", async () => {
    const pin = vi.fn();
    const viewRename = vi.fn();
    offs.push(registerAction("recording.pin", pin, "recording"), registerAction("file.rename", viewRename, "view"));
    render(<App platform="macos" />);
    await settle();
    // Selected things of no kind: the Library's Pin (a recording's) can't run for them, greyed.
    const pinItem = () => item(lastBar(), "Recording", "recording.pin");
    act(() => setMenuPart("library", { targets: ["r1", "r2"] }));
    await settle();
    expect(pinItem()).toMatchObject({ enabled: false });
    await act(() => emitEvent("menu:action", "recording.pin"));
    expect(pin).not.toHaveBeenCalled();
    // The focused page's selection, of its kind: Pin runs for those; the sidebar's Rename… (a view's) doesn't.
    act(() => setMenuPart("library", { targets: ["r1", "r2"], kind: "recording" }));
    await settle();
    expect(pinItem()).toMatchObject({ enabled: true });
    expect(item(lastBar(), "File", "file.rename")).toMatchObject({ enabled: false });
    await act(() => emitEvent("menu:action", "recording.pin"));
    expect(pin).toHaveBeenCalledTimes(1);
    expect(pin).toHaveBeenCalledWith(expect.objectContaining({ kind: "recording", targets: ["r1", "r2"] }));
    // Asked with no kind (the palette): the Library's Pin isn't there.
    expect(canRun("recording.pin")).toBe(false);
    act(() => setMenuPart("library", null));
    await settle();
    expect(pinItem()).toMatchObject({ enabled: false });
  });

  it("a focus change or the same state again tells no one: the menus' state changes only when it changes", async () => {
    const { getByRole, getByLabelText } = render(<App platform="macos" state={{ sidebarHidden: false }} />);
    await settle();
    const drawn = callsOf("app_menu_set").length;
    const before = menuSnapshot();
    act(() => { (getByRole("button", { name: "One" }) as HTMLElement).focus(); });
    act(() => { (getByRole("button", { name: "Two" }) as HTMLElement).focus(); });
    act(() => setMenuPart("again", { state: { sidebarHidden: false } }));
    await settle();
    expect(menuSnapshot()).toBe(before);
    expect(callsOf("app_menu_set")).toHaveLength(drawn);
    // Into a field: typing, said once.
    act(() => { (getByLabelText("A field") as HTMLElement).focus(); });
    await settle();
    expect(menuSnapshot()).not.toBe(before);
    expect(menuSnapshot().state.typing).toBe(true);
    setMenuPart("again", null);
  });

  it("the leaf: classic's bar comes back when the last one goes, not while moving between screens", async () => {
    const { rerender, unmount } = render(<MenuBar key="lock" platform="macos" />);
    await settle();
    expect(lastBar()).not.toBeNull();
    // The lock screen to the app: one leaf goes, another comes; classic's bar never comes back in between.
    rerender(<MenuBar key="app" platform="macos" />);
    await settle();
    expect(callsOf("app_menu_set").filter((c) => c.bar === null)).toHaveLength(0);
    unmount();
    await settle();
    expect(callsOf("app_menu_set").at(-1)).toEqual({ bar: null });
  });

  it("the listener says when it hears picks (MainApp waits for it before the window says it is ready)", async () => {
    await expect(menuPicksReady()).resolves.toBeUndefined();
    const stop = listenToMenuPicks("macos");
    // The listener's own subscription: it settles once the core's event reaches it.
    await expect(menuPicksReady()).resolves.toBeTypeOf("function");
    stop();
  });

  it("a target or value's own %, =, @ and ~ come percent-encoded and are decoded; a label ending in ~12 is no menu", async () => {
    for (const [coded, plain] of [["50%25", "50%"], ["a%3Db", "a=b"], ["me%40home", "me@home"], ["x%7Ey", "x~y"], ["Clients%7E12", "Clients~12"], ["a%3db", "a=b"]]) {
      expect(parsePick(`recording.labels=${coded}`), coded).toEqual({ id: "recording.labels", value: plain });
      expect(parsePick(`recording.labels@${coded}=${coded}~3`), coded).toEqual({ id: "recording.labels", target: plain, value: plain, menu: 3 });
    }
    // Not ours: a bare "%", another escape, a half one.
    for (const bad of ["recording.labels=50%", "recording.labels=%zz", "recording.labels=%4", "recording.labels=a%20b"]) expect(parsePick(bad), bad).toBeNull();
    // Through the window's listener: the menu bar's Labels › "Clients~12" runs with that label, not as menu 12's.
    const labels = vi.fn();
    offs.push(registerAction("recording.labels", labels));
    const menus = vi.fn(() => true);
    offs.push(setPickedHandler(menus));
    setMenuPart("page", { targets: ["r1"] });
    render(<App platform="macos" />);
    await act(() => emitEvent("menu:action", "recording.labels=Clients%7E12"));
    expect(menus).not.toHaveBeenCalled();
    expect(labels).toHaveBeenCalledTimes(1);
    expect(labels).toHaveBeenCalledWith(expect.objectContaining({ value: "Clients~12", targets: ["r1"] }));
    setMenuPart("page", null);
  });

  it("nothing else listens to the system's menus: one listener, so a pick runs once", () => {
    // The code only, not what the comments say about the event.
    const code = (text: string) => text.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:])\/\/.*$/gm, "$1");
    const files = sourceFiles();
    expect(Object.keys(files)).toEqual(expect.arrayContaining(["src/lib/ipc.ts", "src/v2/actions/menuState.ts", "src/v2/shell/NavShell.tsx", "src/views/main/MainApp.tsx"]));
    const naming = Object.entries(files).filter(([, text]) => /["'`]menu:action["'`]/.test(code(text))).map(([path]) => path);
    expect(naming).toEqual(["src/lib/ipc.ts", "src/v2/actions/menuState.ts"]);
    // ipc.ts only names the event's payload; menuState.ts listens once.
    expect(code(files["src/lib/ipc.ts"]).match(/["'`]menu:action["'`]/g)).toHaveLength(1);
    expect(code(files["src/v2/actions/menuState.ts"]).match(/listenTo\(\s*["'`]menu:action["'`]/g)).toHaveLength(1);
    // MainApp mounts the listener and the leaf; it never follows the menus' state itself (a focus change redraws only the leaf).
    expect(code(files["src/views/main/MainApp.tsx"])).toMatch(/listenToMenuPicks\(/);
    expect(code(files["src/views/main/MainApp.tsx"])).not.toMatch(/useMenuBar\(|useMenuSnapshot\(|watchHandlers|handlersVersion/);
  });
});
