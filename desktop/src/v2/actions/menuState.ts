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

/**
 * A pick's payload (native_menu.rs): "view.sortBy#newest" is view.sortBy with the choice newest (CHOICE);
 * "project.rename@p1" is project.rename on p1 (TARGET).
 */
export function parsePick(payload: string): { id: string; value?: string; target?: string } {
  const hash = payload.indexOf("#");
  const head = hash < 0 ? payload : payload.slice(0, hash);
  const value = hash < 0 ? undefined : payload.slice(hash + 1);
  const at = head.indexOf("@");
  const id = at < 0 ? head : head.slice(0, at);
  return { id, ...(value !== undefined ? { value } : {}), ...(at < 0 ? {} : { target: head.slice(at + 1) }) };
}

/**
 * Runs every pick from a menu the system drew (the menu bar, Record's menu on a Mac), once however many parts of the
 * window ask for it (MainApp's menu bar, the shell's Record menu): one listener while any of them is mounted.
 */
let listening: { count: number; platform: ActionContext["platform"]; stop: () => void } | null = null;
export function listenToMenuPicks(platform: ActionContext["platform"]): () => void {
  if (listening) {
    listening.count++;
    listening.platform = platform;
  } else {
    const off = listenTo("menu:action", (payload) => {
      const { id, value, target } = parsePick(payload);
      const ctx = menuContext(listening?.platform ?? platform, value);
      runAction(id, target ? { ...ctx, targets: [target] } : ctx);
    });
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

/** A component that has a menu the system draws: its picks run while it is mounted. */
export function useMenuPicks(platform: ActionContext["platform"]) {
  useEffect(() => listenToMenuPicks(platform), [platform]);
}
