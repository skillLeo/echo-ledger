// What the app's menus know of the moment (FIX-05, 3b): each part of the window says its own state (the shell: the
// sidebar, Back and Forward, recording; MainApp: the theme, captions; a page: what is selected, playing), and the
// sub-menus' choices (Sort By's orders, Labels' labels). The menu bar is drawn from it, and a pick from any menu the
// system drew (`menu:action`) runs with it. The same ids, words and conditions as every other menu (registry.ts).
import { useEffect, useId, useSyncExternalStore } from "react";
import { listenTo } from "../../lib/ipc";
import { runAction, type ActionContext } from "./registry";
import type { MenuChoices } from "./menuBar";

type State = NonNullable<ActionContext["state"]>;
const parts = new Map<string, { state: State; choices: MenuChoices; targets?: readonly string[] }>();
const subscribers = new Set<() => void>();
let snapshot: { state: State; choices: MenuChoices; targets: readonly string[] } = { state: {}, choices: {}, targets: [] };

function recompute() {
  const state: State = {};
  const choices: MenuChoices = {};
  let targets: readonly string[] = [];
  for (const part of parts.values()) {
    Object.assign(state, part.state);
    Object.assign(choices, part.choices);
    if (part.targets) targets = part.targets;
  }
  snapshot = { state, choices, targets };
  subscribers.forEach((notify) => notify());
}

/** Says a part's state and choices (replacing what it said before); `null` takes the part away. */
export function setMenuPart(id: string, part: { state?: State; choices?: MenuChoices; targets?: readonly string[] } | null) {
  if (part) parts.set(id, { state: part.state ?? {}, choices: part.choices ?? {}, targets: part.targets });
  else parts.delete(id);
  recompute();
}

/** A component's share of the menus' state while it is mounted: its state, its sub-menus' choices, what it has selected. */
export function useMenuPart(part: { state?: State; choices?: MenuChoices; targets?: readonly string[] }) {
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

/** The context a menu pick runs with: the app's state, what is selected, the choice it was picked with. */
export function menuContext(platform: ActionContext["platform"], value?: string): ActionContext {
  const now = menuSnapshot();
  return { platform, state: now.state, targets: now.targets, ...(value !== undefined ? { value } : {}) };
}

/** What a pick says: the action, what it acts on (when that isn't the selection), the choice it was picked with. */
export type MenuPick = { id: string; target?: string; value?: string };

/** The id an item the system draws starts with (native_menu.rs PREFIX); a pick's payload is the rest. */
const ITEM_PREFIX = "action:";
const ACTION_ID = /^[A-Za-z0-9]+(\.[A-Za-z0-9]+)+$/;
/** A target or a value is an id we build, never free text: it never holds the separators. */
const isPart = (part: string | undefined) => part === undefined || (part !== "" && !/[@=]/.test(part));

/**
 * A pick, read the one way every menu the system draws writes it (native_menu.rs item_id; the core sends it without its
 * "action:"): "go.library"; "view.sortBy=newest", a choice; "project.rename@p1", on a thing; "recording.labels@r1=clients",
 * both. Null when it isn't well formed (a target or value holding "@" or "=", an empty part).
 */
export function parsePick(payload: string): MenuPick | null {
  const rest = payload.startsWith(ITEM_PREFIX) ? payload.slice(ITEM_PREFIX.length) : payload;
  const eq = rest.indexOf("=");
  const head = eq < 0 ? rest : rest.slice(0, eq);
  const value = eq < 0 ? undefined : rest.slice(eq + 1);
  const at = head.indexOf("@");
  const id = at < 0 ? head : head.slice(0, at);
  const target = at < 0 ? undefined : head.slice(at + 1);
  if (!ACTION_ID.test(id) || !isPart(target) || !isPart(value)) return null;
  return { id, ...(target !== undefined ? { target } : {}), ...(value !== undefined ? { value } : {}) };
}

/**
 * The open context menu's say over a pick, before the registry's (popup.ts's runPicked, fix/D-menus-v21): it answers
 * true when the pick was its own and it ran it. One at a time; the returned function takes it away.
 */
type PickedHandler = (pick: MenuPick, payload: string) => boolean;
let pickedHandler: PickedHandler | null = null;
export function setPickedHandler(handler: PickedHandler | null): () => void {
  pickedHandler = handler;
  return () => {
    if (pickedHandler === handler) pickedHandler = null;
  };
}

/** One pick from a menu the system drew: the open context menu's if it takes it, else the registry runs it. */
function runPick(payload: string, platform: ActionContext["platform"]) {
  const pick = parsePick(payload);
  if (!pick) return;
  if (pickedHandler?.(pick, payload)) return;
  const ctx = menuContext(platform, pick.value);
  runAction(pick.id, pick.target !== undefined ? { ...ctx, targets: [pick.target] } : ctx);
}

/**
 * The window's one listener for picks from the menus the system draws (the menu bar, Record's menu and the tree's
 * project menu on a Mac, the page's context menus), mounted by MainApp (useMenuBar) so it runs in the classic shell
 * too. Nothing else listens to `menu:action`: a pick runs once. Counted, so a second mount (a test, two windows'
 * worth of hooks) never adds a second run.
 */
let listening: { count: number; platform: ActionContext["platform"]; stop: () => void } | null = null;
export function listenToMenuPicks(platform: ActionContext["platform"]): () => void {
  if (listening) {
    listening.count++;
    listening.platform = platform;
  } else {
    const off = listenTo("menu:action", (payload) => runPick(payload, listening?.platform ?? platform));
    listening = { count: 1, platform, stop: () => void off.then((stop) => stop()).catch(() => undefined) };
  }
  let done = false;
  return () => {
    if (done || !listening) return;
    done = true;
    if (--listening.count === 0) {
      listening.stop();
      listening = null;
    }
  };
}
