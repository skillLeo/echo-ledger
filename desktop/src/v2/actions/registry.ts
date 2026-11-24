// UX-21 (PLAN_V2_1_POLISH 3b): the one list of commands (actions.json) and what runs them. Every menu is made from it
// and sends the action's id: the Mac menu bar, right-click menus, a row's ⋯, Record's menu, the avatar's menu, ⌘K,
// the menu-bar and tray menu, the Dock menu, the Windows Jump List. A screen registers what an id does where it can
// (registerAction) and the registry runs it (runAction) when its conditions hold. Labels follow the app's state
// ("Pin" or "Unpin"), Mac menus get Title Case, Mac right-click menus no shortcuts. checkMenu() is the test's: a menu
// with a label, a shortcut or an action that isn't in the file fails.
import list from "./actions.json";
import * as Icons from "../components/icons";
import type { IconType } from "../components/Button";
import type { MenuItem } from "../components/Menu";
import type { NativeMenuItem } from "../../lib/ipc";

export type Platform = "macos" | "windows";
export type AppliesTo = "none" | "one" | "many" | "oneOrMany";

/** One command, as actions.json lists it (absent fields filled in). */
export type Action = {
  id: string;
  /** Sentence case; the Mac's Title Case is made from it (macTitle). */
  label: string;
  /** Its menu in the Mac menu bar (FIX-05), or null. */
  menu: string | null;
  /** An icon of v2/components/icons.ts, or null. */
  icon: string | null;
  /** The shortcut as shown ("⇧⌘N", "Ctrl+Shift+N"), and any second one ("⌘\\", "F5"). */
  mac: string | null;
  win: string | null;
  macAlso: readonly string[];
  winAlso: readonly string[];
  appliesTo: AppliesTo;
  /** The states it needs (CONDITIONS). */
  when: readonly string[];
  destructive: boolean;
  confirm: string | null;
  /** A Tauri predefined menu item (macOS draws and runs it). */
  native: string | null;
  submenu: boolean;
  /** A shortcut that works in any app (Settings › Shortcuts). */
  global: boolean;
  /** key + on: the label while the state is on; check: ticked while it is on; named: "Undo <what>". */
  state: { key?: string; on?: string; check?: string; named?: string } | null;
};

type Raw = { id: string; label: string } & Partial<Omit<Action, "id" | "label">>;

export const ACTIONS: readonly Action[] = (list.actions as Raw[]).map((a) => ({
  id: a.id, label: a.label, menu: a.menu ?? null, icon: a.icon ?? null, mac: a.mac ?? null, win: a.win ?? null,
  macAlso: a.macAlso ?? [], winAlso: a.winAlso ?? [], appliesTo: a.appliesTo ?? "none", when: a.when ?? [], destructive: a.destructive ?? false,
  confirm: a.confirm ?? null, native: a.native ?? null, submenu: a.submenu ?? false, global: a.global ?? false, state: a.state ?? null,
}));
const BY_ID = new Map(ACTIONS.map((a) => [a.id, a]));

export function findAction(id: string): Action | undefined {
  return BY_ID.get(id);
}
function need(id: string): Action {
  const a = BY_ID.get(id);
  if (!a) throw new Error(`${id} is not in actions.json`);
  return a;
}

/** Where a command is asked for: the platform, the app's state, what it works on, and whether it is a right-click menu. */
export type ActionContext = {
  platform: Platform;
  state?: Readonly<Record<string, boolean | string | null | undefined>>;
  /** The selected or open recordings (ids). */
  targets?: readonly string[];
  /** A right-click menu: what doesn't apply is left out, and on a Mac there are no shortcuts. */
  context?: boolean;
  /** A sub-menu's choice it was picked with (a label, a project, a format, a sort order: choiceItems). */
  value?: string;
  /** What it acts on ("recording", "view", "label", "trash"…): one id (Rename…) can mean each, done by its own screen. */
  kind?: string;
};

/** The conditions `when` names. Left unsaid, a state that is usually so (can record, has audio) holds. */
export const CONDITIONS: Readonly<Record<string, (c: ActionContext) => boolean>> = {
  canRecord: (c) => c.state?.canRecord !== false,
  recording: (c) => !!c.state?.recording,
  notRecording: (c) => !c.state?.recording,
  hasTranscript: (c) => !!c.state?.hasTranscript,
  hasAudio: (c) => c.state?.hasAudio !== false,
  notTyping: (c) => !c.state?.typing,
  updateReady: (c) => !!c.state?.updateReady,
  canGoBack: (c) => !!c.state?.canGoBack,
  canGoForward: (c) => !!c.state?.canGoForward,
  hiding: (c) => !!c.state?.hiding,
  noPhoto: (c) => !c.state?.photo,
};

const fits = (applies: AppliesTo, n: number) => (applies === "none" ? true : applies === "one" ? n === 1 : applies === "many" ? n >= 2 : n >= 1);

/** Whether the command can run now: its conditions hold and it has what it works on. */
export function available(id: string, ctx: ActionContext): boolean {
  const a = need(id);
  return fits(a.appliesTo, ctx.targets?.length ?? 0) && a.when.every((c) => CONDITIONS[c]?.(ctx) ?? false);
}

const LOWER = new Set(list.titleCase.lower);
/**
 * The Mac's Title Case of a sentence-case label (pure): every word capitalised but the short ones in the middle (a, and,
 * for, from, to…); a hyphen's second half stays as it is ("Auto-record"); the names the file keeps stay as written.
 */
export function macTitle(label: string): string {
  const words = label.split(" ");
  return words.map((w, i) => {
    const bare = w.replace(/[…,.:;!?]+$/, "");
    if (i > 0 && i < words.length - 1 && LOWER.has(bare)) return w;
    return w.charAt(0).toLocaleUpperCase() + w.slice(1);
  }).join(" ");
}

/** The command's words now: its state's (Unpin, Show sidebar, Undo Move to trash), in the platform's case. */
export function actionLabel(id: string, ctx: ActionContext): string {
  const a = need(id);
  let label = a.label;
  if (a.state?.key && a.state.on && ctx.state?.[a.state.key]) label = a.state.on;
  if (a.state?.named) {
    const what = ctx.state?.[a.state.named];
    if (typeof what === "string" && what) label = `${a.label} ${what.charAt(0).toLocaleLowerCase()}${what.slice(1)}`;
  }
  return ctx.platform === "macos" ? macTitle(label) : label;
}

export function shortcutOf(id: string, platform: Platform): string | null {
  const a = need(id);
  return platform === "macos" ? a.mac : a.win;
}

const MAC_KEYS: Record<string, string> = { "⌃": "Control", "⌥": "Alt", "⇧": "Shift", "⌘": "Meta" };
const KEY_NAMES: Record<string, string> = { "⌫": "Backspace", "\u21A9": "Enter", "←": "ArrowLeft", "→": "ArrowRight", "−": "-", Ctrl: "Control", Del: "Delete" };
/** A shortcut as aria-keyshortcuts says it: "⇧⌘N" → "Shift+Meta+N", "Ctrl+Shift+E" → "Control+Shift+E". */
export function ariaKeys(shortcut: string): string {
  if (/^[⌃⌥⇧⌘]/.test(shortcut)) {
    const mods = [...shortcut].filter((c) => MAC_KEYS[c]);
    const key = [...shortcut].slice(mods.length).join("");
    return [...mods.map((m) => MAC_KEYS[m]), KEY_NAMES[key] ?? key].join("+");
  }
  const parts = shortcut.endsWith("++") ? [...shortcut.slice(0, -2).split("+"), "+"] : shortcut.split("+");
  return parts.map((p) => KEY_NAMES[p] ?? p).join("+");
}

/** Shortcuts macOS keeps for itself, or for the menu items it draws (About, Hide, Minimize, Quit…). */
export const MAC_SYSTEM: readonly string[] = [
  "⌘Space", "⌃Space", "⌃⌘Space", "⌘Tab", "⌘`", "⇧⌘3", "⇧⌘4", "⇧⌘5", "⌃⌘Q", "⌥⌘Esc", "⌥⌘D", "⌃↑", "⌃↓", "⌃←", "⌃→",
  "⌘H", "⌥⌘H", "⌘M", "⌘Q",
];

/** Two commands with one shortcut, on a Mac or on Windows (pure): "mac ⌘N: a, b". */
export function shortcutClashes(items: readonly { id: string; mac?: string | null; win?: string | null; macAlso?: readonly string[]; winAlso?: readonly string[] }[] = ACTIONS): string[] {
  const out: string[] = [];
  for (const [platform, keysOf] of [
    ["mac", (a: (typeof items)[number]) => [a.mac, ...(a.macAlso ?? [])]],
    ["windows", (a: (typeof items)[number]) => [a.win, ...(a.winAlso ?? [])]],
  ] as const) {
    const owners = new Map<string, string[]>();
    for (const a of items) for (const k of keysOf(a)) if (k) owners.set(k, [...(owners.get(k) ?? []), a.id]);
    for (const [k, ids] of owners) if (ids.length > 1) out.push(`${platform} ${k}: ${ids.join(", ")}`);
  }
  return out;
}

type Handler = { run: (ctx: ActionContext) => void; kind?: string };
const handlers = new Map<string, Handler[]>();
/** The newest handler for an id that takes this kind of thing (one registered without a kind takes any). */
const handlerFor = (id: string, kind?: string): Handler | undefined =>
  [...(handlers.get(id) ?? [])].reverse().find((h) => !h.kind || h.kind === kind);
/**
 * What an id does here (the screen that can do it); the newest registration runs. With a `kind` it runs only for that
 * kind of thing (ActionContext.kind): the Library's Rename… is a recording's, the sidebar's a view's. Returns what
 * takes it back.
 */
export function registerAction(id: string, run: (ctx: ActionContext) => void, kind?: string): () => void {
  need(id);
  const handler: Handler = { run, kind };
  handlers.set(id, [...(handlers.get(id) ?? []), handler]);
  return () => handlers.set(id, (handlers.get(id) ?? []).filter((h) => h !== handler));
}
/** Runs the command if something here can and its conditions hold. True when it ran. */
export function runAction(id: string, ctx: ActionContext): boolean {
  const handler = handlerFor(id, ctx.kind);
  if (!handler || !available(id, ctx)) return false;
  handler.run(ctx);
  return true;
}

/**
 * A menu made from ids ("-" sets the next item apart): the label, icon, shortcut, tick and danger from the file; it
 * sends the id (runAction). The menu bar greys what can't run; a right-click menu leaves it out, and on a Mac shows no
 * shortcuts.
 */
export function menuItems(ids: readonly string[], ctx: ActionContext): (MenuItem & { action: string })[] {
  const out: (MenuItem & { action: string })[] = [];
  let apart = false;
  for (const id of ids) {
    if (id === "-") {
      apart = out.length > 0;
      continue;
    }
    const a = need(id);
    const can = available(id, ctx);
    if (!can && ctx.context) continue;
    const icon = a.icon ? (Icons as unknown as Record<string, IconType>)[a.icon] : undefined;
    out.push({
      action: id, label: actionLabel(id, ctx), icon,
      shortcut: ctx.context && ctx.platform === "macos" ? undefined : shortcutOf(id, ctx.platform) ?? undefined,
      danger: a.destructive || undefined, disabled: !can || undefined,
      checked: a.state?.check ? !!ctx.state?.[a.state.check] : undefined,
      separator: apart || undefined,
      onPick: () => void runAction(id, ctx),
    });
    apart = false;
  }
  return out;
}

/**
 * A sub-menu's choices (UX-22): a label's name, a project, a format, a sort order. Each sends the sub-menu's action with
 * its value; their words are the choices' own, so checkMenu takes them only under that sub-menu.
 */
export function choiceItems(action: string, choices: readonly { value: string; label: string; checked?: boolean; disabled?: boolean; icon?: MenuItem["icon"] }[],
  ctx: ActionContext): MenuItem[] {
  need(action);
  return choices.map((c) => ({
    action, choice: c.value, label: c.label, icon: c.icon, checked: c.checked, disabled: c.disabled || undefined,
    onPick: () => void runAction(action, { ...ctx, value: c.value }),
  }));
}

/** Whether something here does `id` now (a screen registered it): a right-click menu shows only what can run. */
export function canRun(id: string, kind?: string): boolean {
  return !!handlerFor(id, kind);
}

/** What is wrong with a menu (the test's check): an item with no action, an unknown one, other words or keys. */
export function checkMenu(items: readonly (MenuItem & { action?: string })[], platform: Platform, parent?: string): string[] {
  const out: string[] = [];
  for (const item of items) {
    if (item.choice !== undefined) {
      if (!parent || item.action !== parent) out.push(`"${item.label}": a choice outside its sub-menu (${item.action ?? "no action"})`);
      continue;
    }
    if (!item.action) {
      out.push(`"${item.label}": no action in actions.json`);
    } else {
      const a = findAction(item.action);
      if (!a) out.push(`"${item.label}": ${item.action} is not in actions.json`);
      else {
        const ctx = { platform };
        const words = [a.label, a.state?.on].filter((w): w is string => !!w).map((w) => (platform === "macos" ? macTitle(w) : w));
        const ok = words.includes(item.label) || (!!a.state?.named && item.label.startsWith(actionLabel(a.id, ctx)));
        if (!ok) out.push(`"${item.label}": ${a.id} is "${words[0]}"`);
        const keys = [platform === "macos" ? a.mac : a.win, ...(platform === "macos" ? a.macAlso : a.winAlso)];
        if (item.shortcut && !keys.includes(item.shortcut)) out.push(`"${item.label}": shortcut ${item.shortcut}, actions.json says ${keys[0] ?? "none"}`);
      }
    }
    if (item.items) out.push(...checkMenu(item.items, platform, item.action));
  }
  return out;
}

const ACCEL: Record<string, string> = { "⌃": "Ctrl", "⌥": "Alt", "⇧": "Shift", "⌘": "CmdOrCtrl" };
/** A shortcut as Tauri's accelerator for a native menu: "⇧⌘N" → "Shift+CmdOrCtrl+N", "Ctrl+O" → "CmdOrCtrl+O". */
export function accelerator(shortcut: string): string {
  if (/^[⌃⌥⇧⌘]/.test(shortcut)) {
    const mods = [...shortcut].filter((c) => ACCEL[c]);
    const key = [...shortcut].slice(mods.length).join("");
    return [...mods.map((m) => ACCEL[m]), KEY_NAMES[key] ?? key].join("+");
  }
  return shortcut.split("+").map((p) => (p === "Ctrl" ? "CmdOrCtrl" : KEY_NAMES[p] ?? p)).join("+");
}

/** A menu made by menuItems() as a native menu's lines (native_menu.rs): a line apart becomes a separator before it. */
export function nativeItems(items: readonly MenuItem[], title?: string, depth = 0): NativeMenuItem[] {
  // A title line (several chosen, UX-22), greyed, with a hairline under it.
  const out: NativeMenuItem[] = title ? [{ action: null, label: title, enabled: false }, { action: null }] : [];
  for (const i of items) {
    if (i.separator && out.length && out[out.length - 1].action !== null) out.push({ action: null });
    // A note, not a command ("Up to 200 at a time…"): greyed.
    if (!i.action) out.push({ action: null, label: i.label, enabled: false });
    // A sub-menu, one level deep (3b): its choices come back with their values.
    else if (i.items?.length) {
      if (depth === 0) out.push({ action: i.action, label: i.label, enabled: !i.disabled, items: nativeItems(i.items, undefined, 1) });
    } else {
      out.push({
        action: i.action, label: i.label, accelerator: i.shortcut ? accelerator(i.shortcut) : null, enabled: !i.disabled, checked: i.checked ?? null,
        ...(i.choice !== undefined ? { value: i.choice } : {}),
      });
    }
  }
  return out;
}

/** Whether a key press is this shortcut ("⇧⌘N" on a Mac, "Ctrl+Shift+N" on Windows): the same keys, no others. */
export function matchesShortcut(e: KeyboardEvent, shortcut: string): boolean {
  const mac = /^[⌃⌥⇧⌘]/.test(shortcut);
  const mods = mac ? [...shortcut].filter((c) => ACCEL[c]) : shortcut.split("+").slice(0, -1);
  const key = mac ? [...shortcut].slice(mods.length).join("") : shortcut.split("+").pop()!;
  const want = {
    meta: mac ? mods.includes("⌘") : false,
    ctrl: mac ? mods.includes("⌃") : mods.includes("Ctrl"),
    alt: mac ? mods.includes("⌥") : mods.includes("Alt"),
    shift: mac ? mods.includes("⇧") : mods.includes("Shift"),
  };
  if (e.metaKey !== want.meta || e.ctrlKey !== want.ctrl || e.altKey !== want.alt || e.shiftKey !== want.shift) return false;
  return e.key.toLowerCase() === (KEY_NAMES[key] ?? key).toLowerCase();
}
