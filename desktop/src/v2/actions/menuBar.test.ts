// FIX-05 (PLAN_V2_1_POLISH 3b): the Mac menu bar, made from the action list. The standard menus in the standard order,
// always the same items (greyed, never hidden, when they can't run), the state's words and ticks, macOS's own items
// for the standard commands, the Window and Help menus set as the app's.
import { afterEach, describe, expect, it } from "vitest";
import { ACTIONS, registerAction, shortcutClashes, type ActionContext } from "./registry";
import { MENU_BAR, menuBarModel, menuBarProblems, type NativeEntry } from "./menuBar";

const mac: ActionContext = { platform: "macos" };
const offs: (() => void)[] = [];
afterEach(() => offs.splice(0).forEach((off) => off()));
const labels = (entries: readonly NativeEntry[]): string[] => entries.map((e) => (e.kind === "separator" ? "—" : e.kind === "native" ? `[${e.native}]` : e.label));
const menu = (title: string, ctx: ActionContext = mac) => menuBarModel(ctx).menus.find((m) => m.title === title)!;
const find = (entries: readonly NativeEntry[], action: string): NativeEntry | undefined => {
  for (const e of entries) {
    if ((e.kind === "item" || e.kind === "submenu") && e.action === action) return e;
    if (e.kind === "submenu") {
      const inner = find(e.items, action);
      if (inner) return inner;
    }
  }
  return undefined;
};

describe("the Mac menu bar (FIX-05)", () => {
  it("has the standard menus in the standard order, the Window and Help menus set as the app's", () => {
    const model = menuBarModel(mac);
    expect(model.menus.map((m) => m.title)).toEqual(["EchoLedger", "File", "Edit", "View", "Go", "Recording", "Live", "Window", "Help"]);
    expect(model.menus.find((m) => m.title === "Window")!.role).toBe("window");
    expect(model.menus.find((m) => m.title === "Help")!.role).toBe("help");
  });

  it("the EchoLedger menu: About, Settings…, Account & Plan…, Check for Updates…, Services, Hide, Hide Others, Show All, Quit", () => {
    expect(labels(menu("EchoLedger").items)).toEqual([
      "[about]", "—", "Settings…", "Account & Plan…", "Check for Updates…", "—", "[services]", "—", "[hide]", "[hideOthers]", "[showAll]", "—", "Quit EchoLedger",
    ]);
    // Settings and Quit are our own (Quit keeps a running recording first), with their keys.
    expect(find(menu("EchoLedger").items, "app.quit")).toMatchObject({ kind: "item", accelerator: "CmdOrCtrl+Q", enabled: true });
    expect(find(menu("EchoLedger").items, "app.settings")).toMatchObject({ accelerator: "CmdOrCtrl+,", enabled: true });
  });

  it("File, Edit and View as the plan lists them, Title Case, with their keys", () => {
    expect(labels(menu("File").items)).toEqual([
      "New Recording", "New Voice Note", "New Project…", "—", "Import Audio Files…", "Open Recent", "Sync Now", "—", "[closeWindow]", "—",
      "Rename…", "Share", "Export…", "—", "Print Transcript…",
    ]);
    expect(labels(menu("Edit").items)).toEqual([
      "[undo]", "[redo]", "—", "[cut]", "[copy]", "[paste]", "Paste and Match Style", "Delete", "[selectAll]", "—", "Find",
    ]);
    const findMenu = menu("Edit").items.find((e) => e.kind === "submenu" && e.label === "Find") as Extract<NativeEntry, { kind: "submenu" }>;
    expect(labels(findMenu.items)).toEqual(["Find…", "Find Next", "Find Previous", "Use Selection for Find", "—", "Search Everything…"]);
    expect(labels(menu("View").items)).toEqual([
      "Hide Sidebar", "Hide Filters", "—", "As List", "As Table", "Sort By", "Group By", "Density", "—", "Transcript", "—", "Theme", "—",
      "Actual Size", "Zoom In", "Zoom Out", "—", "[fullscreen]",
    ]);
    expect(labels(menu("Go").items)).toEqual([
      "Back", "Forward", "—", "Library", "Projects", "Live", "Ask", "Activity", "—", "All Recordings", "Needs You", "Unread", "Audio Only", "Shared", "Trash", "My Views",
    ]);
    expect(labels(menu("Window").items)).toEqual(["[minimize]", "[maximize]", "—", "Show Recording Pill", "Show Captions Window", "Show Live Assist Card", "—", "[bringAllToFront]"]);
    expect(labels(menu("Help").items)).toEqual(["EchoLedger Help", "—", "Keyboard Shortcuts", "What's New", "Getting Started Tour", "—", "Setup & Health…", "Report a Problem…"]);
    expect(find(menu("File").items, "file.syncNow")).toMatchObject({ accelerator: "CmdOrCtrl+R" });
    expect(find(menu("File").items, "file.export")).toMatchObject({ accelerator: "Shift+CmdOrCtrl+E" });
  });

  it("every item is in actions.json, every action with a menu is in its menu, and no two share a key", () => {
    const ids = new Set<string>();
    const walk = (entries: readonly NativeEntry[]) => entries.forEach((e) => {
      // A sub-menu titled by itself (Find, Transcript, Theme) has no action of its own.
      if ((e.kind === "item" || e.kind === "submenu") && e.action) ids.add(e.action);
      if (e.kind === "native") ids.add(e.action);
      if (e.kind === "submenu") walk(e.items);
    });
    for (const m of menuBarModel(mac, { "file.openRecent": [{ value: "r1", label: "Bilal Qureshi" }] }).menus) walk(m.items);
    for (const id of ids) expect(ACTIONS.some((a) => a.id === id), id).toBe(true);
    for (const a of ACTIONS.filter((x) => x.menu)) expect(ids.has(a.id), `${a.id} (${a.menu}) is not in the menu bar`).toBe(true);
    expect(shortcutClashes()).toEqual([]);
    // The layout names only actions that exist, and every one that has a menu.
    expect(MENU_BAR.length).toBe(9);
    expect(menuBarProblems()).toEqual([]);
  });

  it("an item that can't run now is greyed, never hidden; it runs once something here can", () => {
    expect(find(menu("Go").items, "go.library")).toMatchObject({ enabled: false });
    offs.push(registerAction("go.library", () => undefined));
    expect(find(menu("Go").items, "go.library")).toMatchObject({ enabled: true });
    // Conditions: Stop Recording only while recording.
    offs.push(registerAction("live.stopRecording", () => undefined));
    expect(find(menu("Live").items, "live.stopRecording")).toMatchObject({ enabled: false });
    expect(find(menu("Live", { ...mac, state: { recording: true } }).items, "live.stopRecording")).toMatchObject({ enabled: true });
  });

  it("the words and ticks follow the app: Hide/Show Sidebar, Pause/Resume, the Auto-record tick, the theme's", () => {
    for (const id of ["view.sidebar", "live.pauseRecording", "live.autoRecord", "view.themeDark"]) offs.push(registerAction(id, () => undefined));
    const now = (state: ActionContext["state"]) => ({ ...mac, state });
    expect(find(menu("View", now({ sidebarHidden: true })).items, "view.sidebar")).toMatchObject({ label: "Show Sidebar" });
    expect(find(menu("Live", now({ recording: true, paused: true })).items, "live.pauseRecording")).toMatchObject({ label: "Resume Recording", enabled: true });
    expect(find(menu("Live", now({ autoRecord: true })).items, "live.autoRecord")).toMatchObject({ checked: true });
    expect(find(menu("Live", now({ autoRecord: false })).items, "live.autoRecord")).toMatchObject({ checked: false });
    const theme = find(menu("View", now({ themeDark: true })).items, "view.themeDark");
    expect(theme).toMatchObject({ checked: true });
  });

  it("Space, ⌘← and ⌘→ are greyed while typing, so they keep their text meaning", () => {
    for (const id of ["recording.playPause", "recording.skipBack", "recording.skipForward"]) offs.push(registerAction(id, () => undefined));
    const ctx = (typing: boolean): ActionContext => ({ ...mac, targets: ["r1"], state: { typing, hasAudio: true } });
    for (const id of ["recording.playPause", "recording.skipBack", "recording.skipForward"]) {
      expect(find(menu("Recording", ctx(false)).items, id), id).toMatchObject({ enabled: true });
      expect(find(menu("Recording", ctx(true)).items, id), id).toMatchObject({ enabled: false });
    }
  });

  it("a sub-menu's choices come from the page (Sort By ›, Labels ›), each sending its action with its value", () => {
    const model = menuBarModel(mac, { "view.sortBy": [{ value: "newest", label: "Newest First", checked: true }, { value: "oldest", label: "Oldest First" }] });
    const sort = find(model.menus.find((m) => m.title === "View")!.items, "view.sortBy") as Extract<NativeEntry, { kind: "submenu" }>;
    expect(sort.items).toEqual([
      { kind: "item", action: "view.sortBy", value: "newest", label: "Newest First", accelerator: null, enabled: false, checked: true },
      { kind: "item", action: "view.sortBy", value: "oldest", label: "Oldest First", accelerator: null, enabled: false, checked: false },
    ]);
    // A sub-menu with nothing to choose is greyed.
    expect(find(model.menus.find((m) => m.title === "Recording")!.items, "recording.labels")).toMatchObject({ kind: "submenu", enabled: false });
  });
});
