// FIX-05: the Mac menu bar kept in step with the app, and every pick from a menu the system drew run by the registry.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render } from "@testing-library/react";
import { callsOf, emitEvent, resetTauriMock, respond } from "../../test/tauri";
import { registerAction } from "./registry";
import { MENU_BAR_SETTLE_MS, useMenuBar } from "./useMenuBar";
import { parsePick, setMenuPart, setPickedHandler, useMenuPart } from "./menuState";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";
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

  it("the open context menu has the first say over a pick; what it leaves runs through the registry", async () => {
    const labels = vi.fn();
    offs.push(registerAction("recording.labels", labels));
    const taken: unknown[] = [];
    offs.push(setPickedHandler((pick, payload) => {
      taken.push([pick, payload]);
      return pick.value === "mine";
    }));
    render(<App platform="macos" />);
    await act(() => emitEvent("menu:action", "recording.labels@r1=mine"));
    expect(taken).toEqual([[{ id: "recording.labels", target: "r1", value: "mine" }, "recording.labels@r1=mine"]]);
    expect(labels).not.toHaveBeenCalled();
    await act(() => emitEvent("menu:action", "recording.labels@r1=clients"));
    expect(labels).toHaveBeenCalledTimes(1);
    expect(labels).toHaveBeenCalledWith(expect.objectContaining({ value: "clients", targets: ["r1"] }));
  });

  it("one form for every pick: the choice after =, the thing after @, both together; the core's own prefix read too", () => {
    expect(parsePick("go.library")).toEqual({ id: "go.library" });
    expect(parsePick("view.sortBy=newest")).toEqual({ id: "view.sortBy", value: "newest" });
    expect(parsePick("project.rename@p1")).toEqual({ id: "project.rename", target: "p1" });
    expect(parsePick("recording.labels@r1=clients")).toEqual({ id: "recording.labels", target: "r1", value: "clients" });
    expect(parsePick("action:view.sortBy=newest")).toEqual({ id: "view.sortBy", value: "newest" });
    // A target or value holding a separator, or an empty part, is refused (they are ids we build, never text).
    for (const bad of ["view.sortBy=a=b", "view.sortBy=a@b", "project.rename@p@1", "project.rename@=x", "view.sortBy=", "project.rename@", "=x", "library", "go..library", "go.library#x"]) {
      expect(parsePick(bad), bad).toBeNull();
    }
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
  });
});
