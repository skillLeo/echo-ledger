// FIX-05 (PLAN_V2_1_POLISH 3b): the Mac menu bar, made from the action list (actions.json, UX-21). The layout below is
// the plan's, menu by menu; the words, keys, ticks and greying come from the registry and the app's state, so the
// same command reads and behaves the same in every menu. Rules (3b): the standard menus in the standard order; always
// the same items, an item that can't run now greyed, never hidden; show/hide items say the current state; Title Case.
// macOS's own items (About, Services, Hide, Undo, Copy, Minimize, Full Screen…) are the system's (`native`). The page
// sends the model to the core (app_menu_set), which draws it; a pick comes back as `menu:action`, run by the registry.
import { ACTIONS, accelerator, actionLabel, available, canRun, findAction, type ActionContext } from "./registry";

/** One place in the layout: an action, a line, or a sub-menu (its own title, or an action's; its choices from the page). */
type Layout = string | { title?: string; action?: string; items?: readonly Layout[]; choices?: true; after?: readonly Layout[] };
const LINE = "-";

/** The menus, in the plan's order (3b, FIX-05). */
export const MENU_BAR: readonly { title: string; role?: "window" | "help"; items: readonly Layout[] }[] = [
  { title: "EchoLedger", items: ["app.about", LINE, "app.settings", "app.accountPlan", "app.checkUpdates", LINE, "app.services", LINE, "app.hide", "app.hideOthers", "app.showAll", LINE, "app.quit"] },
  {
    title: "File",
    items: [
      "file.newRecording", "file.newVoiceNote", "file.newProject", LINE,
      "file.importAudio", { action: "file.openRecent", choices: true, after: [LINE, "file.clearRecent"] }, "file.syncNow", LINE,
      "file.closeWindow", LINE,
      "file.rename", { action: "file.share", items: ["file.copyLink", "file.shareWithPeople"] }, "file.export", LINE,
      "file.printTranscript",
    ],
  },
  {
    title: "Edit",
    items: [
      "edit.undo", "edit.redo", LINE,
      "edit.cut", "edit.copy", "edit.paste", "edit.pasteMatchStyle", "edit.delete", "edit.selectAll", LINE,
      { title: "Find", items: ["edit.find", "edit.findNext", "edit.findPrevious", "edit.useSelectionForFind", LINE, "edit.searchEverything"] },
    ],
  },
  {
    title: "View",
    items: [
      "view.sidebar", "view.filters", LINE,
      "view.asList", "view.asTable", { action: "view.sortBy", choices: true }, { action: "view.groupBy", choices: true }, { action: "view.density", choices: true }, LINE,
      { title: "Transcript", items: ["view.timestamps", "view.speakerNames", { title: "Script", items: ["view.scriptUrdu", "view.scriptRomanUrdu"] }] }, LINE,
      { title: "Theme", items: ["view.themeLight", "view.themeDark", "view.themeSystem", LINE, "view.compactRail"] }, LINE,
      "view.actualSize", "view.zoomIn", "view.zoomOut", LINE,
      "view.fullScreen",
    ],
  },
  {
    title: "Go",
    items: [
      "go.back", "go.forward", LINE,
      "go.library", "go.projects", "go.live", "go.ask", "go.activity", LINE,
      "go.allRecordings", "go.needsYou", "go.unread", "go.audioOnly", "go.shared", "go.trash", { action: "go.myViews", choices: true },
    ],
  },
  {
    title: "Recording",
    items: [
      "recording.open", "recording.details", LINE,
      "recording.playPause", "recording.skipBack", "recording.skipForward", { action: "recording.playbackSpeed", choices: true }, "recording.skipSilence", LINE,
      "recording.transcribe", { action: "recording.translate", choices: true }, "recording.ask", LINE,
      "recording.pin", "recording.important", "recording.unread", { action: "recording.labels", choices: true }, { action: "recording.moveToProject", choices: true },
      "recording.keepForever", LINE,
      "file.copyLink", "recording.copyTranscript", LINE,
      "recording.moveToTrash", "recording.deleteNow",
    ],
  },
  {
    title: "Live",
    items: [
      "live.stopRecording", "live.pauseRecording", LINE,
      "live.startAssist", "live.practice", "live.pastReports",
      "live.translate", "live.captions", "live.dictation", LINE,
      "live.autoRecord", LINE,
      { action: "live.hideFromShares", items: ["live.hide15", "live.hide60", "live.hideUntilQuit", LINE, "live.stopHiding"] },
      "live.hideWindowsNow", "live.showWindows", "live.shareCheck",
    ],
  },
  { title: "Window", role: "window", items: ["window.minimize", "window.zoom", LINE, "window.pill", "window.captions", "window.assistCard", LINE, "window.bringAllToFront"] },
  { title: "Help", role: "help", items: ["help.help", LINE, "help.shortcuts", "help.whatsNew", "help.tour", LINE, "help.setupHealth", "help.reportProblem"] },
];

/** What the core draws (app_menu.rs): our items, macOS's own, lines and sub-menus. */
export type { NativeBarEntry as NativeEntry, NativeMenuBar } from "../../lib/ipc";
import type { NativeBarEntry as NativeEntry, NativeMenuBar } from "../../lib/ipc";

/**
 * A sub-menu's choices, from the page that has them (Sort By's orders, Labels' labels): each sends its action with its
 * value, an id we build (the sort key, the label's id, the project's id), never the words shown.
 */
export type MenuChoices = Partial<Record<string, readonly { value: string; label: string; checked?: boolean }[]>>;

/** Answered by the core itself, with or without the page: Settings… and Quit (it keeps a running recording first). */
export const CORE_ACTIONS: ReadonlySet<string> = new Set(["app.settings", "app.quit"]);

/**
 * The core's own commands (native_menu.rs CORE_RUNS, the same list): run by the core, page or not, as the tray's are,
 * so they are enabled by their conditions alone; with no page the core keeps their words, greying and ticks in step.
 */
export const CORE_RUNS: readonly string[] = [
  "file.newRecording", "file.newVoiceNote", "live.startAssist",
  "live.stopRecording", "live.pauseRecording", "live.captions", "live.autoRecord", "live.hideFromShares", "live.hide15",
  "live.hide60", "live.hideUntilQuit", "live.stopHiding", "live.hideWindowsNow", "live.showWindows", "window.pill",
];

/**
 * A system-wide shortcut the person set (Settings › Shortcuts), shown on its item as it is now: Hide EchoLedger Windows
 * Now carries the hide-all shortcut (Tauri's form, "CommandOrControl+Alt+Shift+P", from the status), none when cleared.
 */
const GLOBAL_KEYS: Readonly<Record<string, string>> = { "live.hideWindowsNow": "hideAllShortcut" };
function globalKey(id: string, at: ActionContext): string | null | undefined {
  const key = GLOBAL_KEYS[id];
  if (!key) return undefined;
  const keys = at.state?.[key];
  return typeof keys === "string" && keys ? keys : null;
}

/** A core command's look rule for the core (app_menu.rs CoreItem): its conditions, the state its words follow, its tick. */
function coreItem(id: string, at: ActionContext) {
  const a = findAction(id)!;
  const key = a.state?.key ?? null;
  const words = (on: boolean) => (key ? actionLabel(id, { ...at, state: { ...at.state, [key]: on } }) : null);
  return { when: [...a.when], state: key, off: words(false), on: words(true), check: a.state?.check ?? null };
}

/**
 * Keys with no modifier (Space, Return) are shown in the plan, but a menu key with no modifier would take the key from a
 * text field before the field sees it: they stay the page's own keys, not the menu's.
 */
const menuKey = (shortcut: string | null) => (shortcut && /^[⌃⌥⇧⌘]/.test(shortcut) ? accelerator(shortcut) : null);

/**
 * The menu bar for this moment (pure): the app's state in `ctx`, the sub-menus' choices from the pages, and the kinds
 * of thing the focused page's commands are for (each item greyed by whether something answers it for one of them).
 */
export function menuBarModel(ctx: ActionContext, choices: MenuChoices = {}, kinds: readonly string[] = []): NativeMenuBar {
  const at = { ...ctx, platform: "macos" as const };
  const runs = (id: string) => kinds.some((kind) => canRun(id, kind)) || canRun(id, ctx.kind);
  const entry = (layout: Layout): NativeEntry => {
    if (layout === LINE) return { kind: "separator" };
    if (typeof layout === "string") {
      const action = findAction(layout);
      if (!action) throw new Error(`${layout} is not in actions.json`);
      if (action.native && !CORE_ACTIONS.has(action.id)) return { kind: "native", action: action.id, native: action.native };
      const core = CORE_RUNS.includes(action.id);
      const enabled = CORE_ACTIONS.has(action.id) || ((core || runs(action.id)) && available(action.id, at));
      return {
        kind: "item", action: action.id, label: actionLabel(action.id, at), accelerator: globalKey(action.id, at) ?? menuKey(action.mac), enabled,
        checked: action.state?.check ? !!at.state?.[action.state.check] : null,
        ...(core ? { core: coreItem(action.id, at) } : {}),
      };
    }
    const label = layout.title ?? (layout.action ? actionLabel(layout.action, at) : "");
    if (layout.choices && layout.action) {
      const id = layout.action;
      const can = runs(id) && available(id, at);
      const items: NativeEntry[] = (choices[id] ?? []).map((c) => ({
        kind: "item", action: id, value: c.value, label: c.label, accelerator: null, enabled: can, checked: !!c.checked,
      }));
      // What follows the choices (Open Recent's Clear Menu), only when there are choices to follow.
      const after = items.length ? (layout.after ?? []).map(entry) : [];
      return { kind: "submenu", action: id, label, enabled: can && items.length > 0, items: [...items, ...after] };
    }
    return { kind: "submenu", action: layout.action, label, enabled: true, items: (layout.items ?? []).map(entry) };
  };
  return { menus: MENU_BAR.map((m) => ({ title: m.title, ...(m.role ? { role: m.role } : {}), items: m.items.map(entry) })) };
}

/** Every action id the layout names (for its own checks). */
export function menuBarActions(): string[] {
  const out: string[] = [];
  const walk = (items: readonly Layout[]) => items.forEach((l) => {
    if (typeof l === "string") { if (l !== LINE) out.push(l); return; }
    if (l.action) out.push(l.action);
    if (l.items) walk(l.items);
    if (l.after) walk(l.after);
  });
  MENU_BAR.forEach((m) => walk(m.items));
  return out;
}

/** The layout names only actions in actions.json, and names every action that has a menu. */
export function menuBarProblems(): string[] {
  const named = new Set(menuBarActions());
  return [
    ...[...named].filter((id) => !findAction(id)).map((id) => `${id} is not in actions.json`),
    ...ACTIONS.filter((a) => a.menu && !named.has(a.id)).map((a) => `${a.id} (${a.menu}) is not in the menu bar`),
  ];
}
