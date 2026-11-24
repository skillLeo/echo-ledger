// What the app's menus know of the moment (FIX-05, 3b): each part of the window says its own state (the shell: the
// sidebar, Back and Forward, recording; MainApp: the theme, captions; the focused page: what is selected, of which kind,
// playing), and the sub-menus' choices (Sort By's orders, Labels' labels). The menu bar is drawn from it, greyed by what
// runs for that kind (canRun), and a pick from the menu bar runs with it. The same ids, words and conditions as every
// other menu (registry.ts).
import { useEffect, useId, useSyncExternalStore } from "react";
import { listenTo } from "../../lib/ipc";
import { canRun, findAction, runAction, watchHandlers, type ActionContext } from "./registry";
import type { MenuChoices } from "./menuBar";

type State = NonNullable<ActionContext["state"]>;
/**
 * One part's say: its state, its sub-menus' choices, and (the focused page) what is selected and the kinds of thing its
 * commands are for, the selection's first ("recording", then the list's own "list": As Table, Sort By…).
 */
type Part = { state?: State; choices?: MenuChoices; targets?: readonly string[]; kind?: string | readonly string[] };
const parts = new Map<string, Part>();
const subscribers = new Set<() => void>();
let snapshot: { state: State; choices: MenuChoices; targets: readonly string[]; kinds: readonly string[] } = { state: {}, choices: {}, targets: [], kinds: [] };

/** The merged state's contents, the same whatever order the parts said it in. */
const contents = (s: typeof snapshot) => JSON.stringify([
  Object.entries(s.state).sort(([a], [b]) => a.localeCompare(b)),
  Object.entries(s.choices).sort(([a], [b]) => a.localeCompare(b)),
  s.targets,
  s.kinds,
]);

/** Merges what the parts say; only a real change makes a new snapshot and tells the subscribers (the menu bar). */
function recompute() {
  const state: State = {};
  const choices: MenuChoices = {};
  let targets: readonly string[] = [];
  let kinds: readonly string[] = [];
  for (const part of parts.values()) {
    Object.assign(state, part.state);
    Object.assign(choices, part.choices);
    // The latest part with a selection is the focused page's: its things and their kind go together.
    if (part.targets) {
      targets = part.targets;
      kinds = part.kind === undefined ? [] : typeof part.kind === "string" ? [part.kind] : part.kind;
    }
  }
  const next = { state, choices, targets, kinds };
  if (contents(next) === contents(snapshot)) return;
  snapshot = next;
  subscribers.forEach((notify) => notify());
}

/** Says a part's state and choices (replacing what it said before); `null` takes the part away. */
export function setMenuPart(id: string, part: Part | null) {
  if (part) parts.set(id, { state: part.state ?? {}, choices: part.choices ?? {}, targets: part.targets, kind: part.kind });
  else parts.delete(id);
  recompute();
}

/**
 * A component's share of the menus' state while it is mounted: its state, its sub-menus' choices, and, for the page in
 * front, what it has selected and of which kind ("recording": the Library's Pin, Labels, Move… run for those).
 */
export function useMenuPart(part: Part) {
  const id = useId();
  const key = JSON.stringify(part);
  useEffect(() => {
    setMenuPart(id, part);
    // `key` stands for the part's contents.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, key]);
  useEffect(() => () => setMenuPart(id, null), [id]);
}

/** The menus' state now (the menu bar redraws from it). */
export function useMenuSnapshot() {
  return useSyncExternalStore((notify) => {
    subscribers.add(notify);
    return () => subscribers.delete(notify);
  }, () => snapshot, () => snapshot);
}
export const menuSnapshot = () => snapshot;

/** The kind an action runs for here (pure on the snapshot): the first of the focused page's kinds something answers it for. */
export function kindFor(id: string, kinds: readonly string[] = snapshot.kinds): string | undefined {
  return kinds.find((kind) => canRun(id, kind));
}

/**
 * The context the menu bar is drawn and its picks run with: the app's state, the focused page's selection, the kind
 * the action `id` runs for there, the choice.
 */
export function menuContext(platform: ActionContext["platform"], value?: string, id?: string): ActionContext {
  const now = menuSnapshot();
  const kind = id === undefined ? now.kinds[0] : kindFor(id, now.kinds);
  return { platform, state: now.state, targets: now.targets, ...(kind !== undefined ? { kind } : {}), ...(value !== undefined ? { value } : {}) };
}

/**
 * What a pick says: the action, what it acts on (when that isn't the selection), the choice it was picked with, and
 * the number of the context menu it came from (popup.ts numbers each one it shows).
 */
export type MenuPick = { id: string; target?: string; value?: string; menu?: number };

/** The id an item the system draws starts with (native_menu.rs PREFIX); a pick's payload is the rest. */
const ITEM_PREFIX = "action:";
const ACTION_ID = /^[A-Za-z0-9]+(\.[A-Za-z0-9]+)+$/;
/** The form's own characters inside a target or a value, percent-encoded by the core (native_menu.rs ESCAPES). */
const ESCAPES: Readonly<Record<string, string>> = { "%25": "%", "%3D": "=", "%40": "@", "%7E": "~" };
/** A target or value as the core wrote it, decoded; null when empty, holding a separator or a "%" that isn't ours. */
function decode(raw: string | undefined): string | undefined | null {
  if (raw === undefined) return undefined;
  if (raw === "" || /[@=~]/.test(raw)) return null;
  let bad = false;
  const out = raw.replace(/%(..)?/g, (code: string) => {
    const plain = ESCAPES[code.toUpperCase()];
    if (plain === undefined) bad = true;
    return plain ?? "";
  });
  return bad ? null : out;
}

/**
 * A pick, read the one way every menu the system draws writes it (native_menu.rs item_id; the core sends it without its
 * "action:"): `<id>[@<target>][=<value>][~<n>]`. "go.library"; "view.sortBy=newest", a choice; "project.rename@p1", on
 * a thing; "recording.labels@r1=clients", both; "recording.pin~4", from context menu 4. The number comes off first,
 * then the value, then the target; "%", "=", "@" and "~" inside a target or value come percent-encoded and are decoded
 * here (a label ending in "~12" is a label, never menu 12). Null when it isn't well formed.
 */
export function parsePick(payload: string): MenuPick | null {
  let rest = payload.startsWith(ITEM_PREFIX) ? payload.slice(ITEM_PREFIX.length) : payload;
  let menu: number | undefined;
  const tilde = rest.lastIndexOf("~");
  if (tilde >= 0) {
    const n = rest.slice(tilde + 1);
    if (!/^[0-9]+$/.test(n)) return null;
    menu = Number(n);
    rest = rest.slice(0, tilde);
  }
  const eq = rest.indexOf("=");
  const head = eq < 0 ? rest : rest.slice(0, eq);
  const at = head.indexOf("@");
  const id = at < 0 ? head : head.slice(0, at);
  const target = decode(at < 0 ? undefined : head.slice(at + 1));
  const value = decode(eq < 0 ? undefined : rest.slice(eq + 1));
  if (!ACTION_ID.test(id) || target === null || value === null) return null;
  return { id, ...(target !== undefined ? { target } : {}), ...(value !== undefined ? { value } : {}), ...(menu !== undefined ? { menu } : {}) };
}

/**
 * Who answers a context menu's picks (popup.ts registers its runPicked): given the pick as the core sent it, true when
 * it was one of the open menu's and ran. One at a time; the returned function takes it away.
 */
type PickedHandler = (payload: string, pick: MenuPick) => boolean;
let pickedHandler: PickedHandler | null = null;
export function setPickedHandler(handler: PickedHandler | null): () => void {
  pickedHandler = handler;
  return () => {
    if (pickedHandler === handler) pickedHandler = null;
  };
}

/** The context a numberless pick runs with: the focused page's, or its own thing's (`@target`, no selection's kind). */
function pickContext(pick: MenuPick, platform: ActionContext["platform"]): ActionContext {
  const ctx = menuContext(platform, pick.value, pick.id);
  if (pick.target === undefined) return ctx;
  return { platform: ctx.platform, state: ctx.state, targets: [pick.target], ...(pick.value !== undefined ? { value: pick.value } : {}) };
}

/** How long a pick nothing here answers yet waits for its command (the window built again, the app coming into use). */
export const PICK_HOLD_MS = 5000;
/** The latest such pick, run once its command is registered, let go after PICK_HOLD_MS (the lock screen, sign-in). */
let held: { pick: MenuPick; platform: () => ActionContext["platform"]; stop: () => void } | null = null;

function letGo() {
  held?.stop();
  held = null;
}

/**
 * The commands a pick may wait for (pure): only one that acts on no recording, the same whenever it runs (Go, View, Help,
 * File's New…, the app's); one on the selection (Move to Trash) never runs seconds later on another one.
 */
export function mayWait(pick: MenuPick): boolean {
  const action = findAction(pick.id);
  return !!action && action.appliesTo === "none" && pick.target === undefined && /^(go|view|help|app)\.|^file\.new/.test(pick.id);
}

function hold(pick: MenuPick, platform: () => ActionContext["platform"]) {
  letGo();
  if (!mayWait(pick)) return;
  const timer = window.setTimeout(letGo, PICK_HOLD_MS);
  let soon = 0;
  // Told when a command is registered; tried a moment later, once the screen that registered it has settled.
  const unwatch = watchHandlers(() => {
    window.clearTimeout(soon);
    soon = window.setTimeout(() => {
      if (!held || held.pick !== pick || !canRun(pick.id, pickContext(pick, platform()).kind)) return;
      letGo();
      runAction(pick.id, pickContext(pick, platform()));
    }, 0);
  });
  held = { pick, platform, stop: () => { window.clearTimeout(timer); window.clearTimeout(soon); unwatch(); } };
}

/**
 * One pick from a menu the system drew. A numbered one is a context menu's: only its handler may run it, and one nobody
 * claims (a menu let go, an earlier one's) is dropped, never run through the registry. Any other (the menu bar,
 * Record's menu, the tree's project menu) runs through the registry with the focused page's state, selection and kind;
 * a pick on a thing (`@target`) runs on that thing instead of the selection. One whose command nothing here answers
 * yet (the window was just built, the app isn't in use yet) waits for it, the latest only, for PICK_HOLD_MS, when it
 * acts on no recording (mayWait).
 */
function runPick(payload: string, platform: () => ActionContext["platform"]) {
  const pick = parsePick(payload);
  if (!pick) return;
  if (pick.menu !== undefined) {
    pickedHandler?.(payload, pick);
    return;
  }
  // A newer pick replaces one still waiting.
  letGo();
  const ctx = pickContext(pick, platform());
  if (!canRun(pick.id, ctx.kind)) {
    hold(pick, platform);
    return;
  }
  runAction(pick.id, ctx);
}

type Platform = ActionContext["platform"];
/**
 * The window's one listener for picks from the menus the system draws (the menu bar, Record's menu and the tree's
 * project menu on a Mac, the page's context menus), mounted by MainApp so it runs in the classic shell too. Nothing
 * else listens to `menu:action`: a pick runs once. Counted, so a second mount never adds a second run. The platform
 * may be a function, read at each pick (MainApp knows it once the core has said).
 */
let listening: { count: number; platform: Platform | (() => Platform); ready: Promise<unknown>; stop: () => void } | null = null;
export function listenToMenuPicks(platform: Platform | (() => Platform)): () => void {
  if (listening) {
    listening.count++;
    listening.platform = platform;
  } else {
    const now = () => {
      const p = listening?.platform ?? platform;
      return typeof p === "function" ? p() : p;
    };
    const off = listenTo("menu:action", (payload) => runPick(payload, now));
    listening = { count: 1, platform, ready: off, stop: () => void off.then((stop) => stop()).catch(() => undefined) };
  }
  let done = false;
  return () => {
    if (done || !listening) return;
    done = true;
    if (--listening.count === 0) {
      listening.stop();
      listening = null;
      // No window to run it in any more.
      letGo();
    }
  };
}

/**
 * Settles once the listener hears picks: MainApp says the window is ready (announceReady) only after it, so a pick the
 * core kept while the window was built (native_menu.rs picked) reaches it.
 */
export function menuPicksReady(): Promise<unknown> {
  return listening?.ready ?? Promise.resolve();
}
