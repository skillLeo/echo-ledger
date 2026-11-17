// Interface flags (docs/API.md "Interface flags (since 2.0.0-a)"): the server decides, the app only reads. The core
// keeps the last `GET /api/config` answer; this adapter asks it through two commands and treats anything it cannot
// read as "off", so an older core or server shows the classic screens.
//   ui_flags                      → { ui_flags: { "<name>": bool, … }, ui_classic: bool }   (cached; no request)
//   ui_classic_set { classic }    → PUT /api/account/ui {"classic": bool}, the same shape from its answer
import { api } from "../lib/ipc";
import { useEffect, useRef, useState } from "react";

export const UI_FLAG_NAMES = [
  "ui_v2.shell", "ui_v2.home", "ui_v2.library", "ui_v2.recording_controls", "ui_v2.after_call", "ui_v2.live", "ui_v2.ask",
  "ui_v2.settings", "ui_v2.setup_health", "ui_v2.admin", "ui_v2.website", "live_assist",
] as const;
export type UiFlagName = (typeof UI_FLAG_NAMES)[number];
export type UiFlags = Record<UiFlagName, boolean>;
export type UiState = { flags: UiFlags; classic: boolean };

export const ALL_OFF: UiState = { flags: Object.fromEntries(UI_FLAG_NAMES.map((n) => [n, false])) as UiFlags, classic: false };

/** The core's answer, read defensively: unknown flags are ignored, missing or non-true ones are off. */
export function parseUiState(raw: unknown): UiState {
  if (!raw || typeof raw !== "object") return ALL_OFF;
  const r = raw as { ui_flags?: unknown; ui_classic?: unknown };
  const given = r.ui_flags && typeof r.ui_flags === "object" ? (r.ui_flags as Record<string, unknown>) : {};
  const flags = Object.fromEntries(UI_FLAG_NAMES.map((n) => [n, given[n] === true])) as UiFlags;
  return { flags, classic: r.ui_classic === true };
}

/** Whether any redesigned screen is on: Settings then offers "Switch back to classic". */
export function anyV2(state: UiState): boolean {
  return UI_FLAG_NAMES.some((n) => n.startsWith("ui_v2.") && state.flags[n]);
}

/**
 * A development build may force flags for trying the screens before the server has them:
 * `?uiflags=ui_v2.shell,ui_v2.library`. A release build has no such override (the check is inline on DEV).
 */
function devOverride(search: string): UiState | null {
  if (!import.meta.env.DEV) return null;
  const list = new URLSearchParams(search).get("uiflags");
  if (list === null) return null;
  const on = new Set(list.split(",").map((s) => s.trim()));
  return { flags: Object.fromEntries(UI_FLAG_NAMES.map((n) => [n, on.has(n)])) as UiFlags, classic: false };
}

/** The flags for this person now; all off when the core has no such command or answers nothing usable. */
export async function readUiState(): Promise<UiState> {
  const forced = typeof location !== "undefined" ? devOverride(location.search) : null;
  if (forced) return forced;
  try {
    return parseUiState(await api.uiFlags());
  } catch {
    return ALL_OFF;
  }
}

/** "Switch back to classic" (or to the new screens): the server's new answer. Errors reach the caller. */
export async function setClassic(classic: boolean): Promise<UiState> {
  return parseUiState(await api.uiClassicSet(classic));
}

/**
 * The flags in a view. Read at start and again whenever `screen` changes (a change applies on the next screen the
 * person opens), never while `recording` is true, so nothing switches under a running call. The third value is the
 * `screen` the flags were last read for: a window that must not show stale flags waits until it equals its key.
 */
export function useUiFlags(enabled: boolean, screen: string, recording: boolean): [UiState, (next: UiState) => void, string | null] {
  const [state, setState] = useState<UiState>(ALL_OFF);
  const [readFor, setReadFor] = useState<string | null>(null);
  const recordingRef = useRef(recording);
  recordingRef.current = recording;
  useEffect(() => {
    if (!enabled || recordingRef.current) return;
    let live = true;
    void readUiState().then((next) => {
      if (!live || recordingRef.current) return;
      setState((prev) => (same(prev, next) ? prev : next));
      setReadFor(screen);
    });
    return () => {
      live = false;
    };
  }, [enabled, screen]);
  return [state, setState, readFor];
}

/** How many times `on` turned from false to true (a window shown, a recording started): a key for useUiFlags. */
export function useRisingCount(on: boolean): number {
  const [count, setCount] = useState(0);
  const last = useRef(false);
  useEffect(() => {
    if (on && !last.current) setCount((n) => n + 1);
    last.current = on;
  }, [on]);
  return count;
}

function same(a: UiState, b: UiState): boolean {
  return a.classic === b.classic && UI_FLAG_NAMES.every((n) => a.flags[n] === b.flags[n]);
}
