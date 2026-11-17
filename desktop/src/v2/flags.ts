// Interface flags (docs/API.md "Interface flags (since 2.0.0-a)"): the server decides, the app only reads. The core
// keeps the last `GET /api/config` answer; this adapter asks it through two commands and treats anything it cannot
// read as "off", so an older core or server shows the classic screens.
//
// The design (docs/PLAN_V2_PARITY.md 0, docs/API.md "The design switch (since 2.0.0-g)"): classic is everyone's default
// until v2 has every classic feature; the new design is opt-in, both ways, from Settings › Appearance. A 2.0.0-g server
// keeps the choice per account and answers `ui_design`; its flags already follow it, and Appearance offers what its
// `can_try` and `can_switch_back` say. An older core or server has no `ui_design`: the choice is then kept on this
// computer. Until one is made the server's flags stand (its rollout); classic then shows no v2 screen and the new
// design all the desktop's, while a feature flag (live_assist) stays the server's.
//   ui_flags                      → { ui_flags: { "<name>": bool, … }, ui_classic: bool }   (cached; no request)
//   ui_classic_set { classic }    → PUT /api/account/ui {"classic": bool}, the same shape from its answer
import { api, errorMessage } from "../lib/ipc";
import { useEffect, useRef, useState } from "react";

export const UI_FLAG_NAMES = [
  "ui_v2.shell", "ui_v2.home", "ui_v2.library", "ui_v2.recording_controls", "ui_v2.after_call", "ui_v2.live", "ui_v2.ask",
  "ui_v2.settings", "ui_v2.setup_health", "ui_v2.admin", "ui_v2.website", "live_assist",
] as const;
export type UiFlagName = (typeof UI_FLAG_NAMES)[number];
export type UiFlags = Record<UiFlagName, boolean>;
/** The server's design answer (2.0.0-g): the choice, the design shown, and which switch Appearance offers. */
export type DesignState = { choice: "v2" | "classic" | null; current: "v2" | "classic"; canTry: boolean; canSwitchBack: boolean };
export type UiState = { flags: UiFlags; classic: boolean; design: DesignState | null };

export const ALL_OFF: UiState = { flags: Object.fromEntries(UI_FLAG_NAMES.map((n) => [n, false])) as UiFlags, classic: false, design: null };

/** The core's answer, read defensively: unknown flags are ignored, missing or non-true ones are off. */
export function parseUiState(raw: unknown): UiState {
  if (!raw || typeof raw !== "object") return ALL_OFF;
  const r = raw as { ui_flags?: unknown; ui_classic?: unknown };
  const given = r.ui_flags && typeof r.ui_flags === "object" ? (r.ui_flags as Record<string, unknown>) : {};
  const flags = Object.fromEntries(UI_FLAG_NAMES.map((n) => [n, given[n] === true])) as UiFlags;
  return { flags, classic: r.ui_classic === true, design: parseDesign((raw as { ui_design?: unknown }).ui_design) };
}

function parseDesign(raw: unknown): DesignState | null {
  if (!raw || typeof raw !== "object") return null;
  const d = raw as Record<string, unknown>;
  const choice = d.choice === "v2" || d.choice === "classic" ? d.choice : null;
  return { choice, current: d.current === "v2" ? "v2" : "classic", canTry: d.can_try === true, canSwitchBack: d.can_switch_back === true };
}

/** The design this person chose: classic (the default) or the new one. */
export type Design = "classic" | "v2";
export const DESIGN_KEY = "echoledger-design";
/** Sent in this window when the design is chosen; other windows hear the storage change. */
export const DESIGN_EVENT = "echoledger:design";
/** The desktop's v2 screens, all on with the new design (the admin console and the website are the web's). */
export const DESKTOP_V2_FLAGS: readonly UiFlagName[] = UI_FLAG_NAMES.filter((n) => n.startsWith("ui_v2.") && n !== "ui_v2.admin" && n !== "ui_v2.website");

/** The design chosen on this computer, or null before any choice (the server's flags then stand). */
export function readDesign(): Design | null {
  try {
    const kept = localStorage.getItem(DESIGN_KEY);
    return kept === "v2" || kept === "classic" ? kept : null;
  } catch {
    return null;
  }
}

/**
 * Try the new design, or switch back: on a 2.0.0-g server its `PUT /api/account/ui {"design"}` (errors, such as
 * nothing open to try, reach the caller); otherwise kept on this computer. Either way this window's views read the
 * flags again, and the other windows on their next screen.
 */
export async function chooseDesign(design: Design): Promise<void> {
  let server: UiState | null = null;
  try {
    server = parseUiState(await api.uiFlags());
  } catch {
    // An older core: the choice stays on this computer.
  }
  if (server?.design) {
    await api.uiDesignSet(design);
  } else {
    try {
      localStorage.setItem(DESIGN_KEY, design);
    } catch {
      // Storage is off: the choice lasts until the window reloads.
    }
  }
  window.dispatchEvent(new CustomEvent(DESIGN_EVENT, { detail: design }));
}

/**
 * An answer without `ui_design`, under the design chosen here: none chosen leaves the server's flags; classic turns every
 * v2 screen off, the new design every desktop one on.
 */
export function applyDesign(state: UiState, design: Design | null): UiState {
  if (design === null) return { ...state, design: null };
  const flags = { ...state.flags };
  for (const n of UI_FLAG_NAMES) if (n.startsWith("ui_v2.")) flags[n] = design === "v2" && DESKTOP_V2_FLAGS.includes(n);
  return { flags, classic: design === "classic", design: null };
}

/** Which switch Settings › Appearance offers: the server's `can_try` / `can_switch_back`, else the design shown here. */
export function designOffer(state: UiState): { canTry: boolean; canSwitchBack: boolean } {
  if (state.design) return { canTry: state.design.canTry, canSwitchBack: state.design.canSwitchBack };
  const v2 = anyV2(state);
  return { canTry: !v2, canSwitchBack: v2 };
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
  return { flags: Object.fromEntries(UI_FLAG_NAMES.map((n) => [n, on.has(n)])) as UiFlags, classic: false, design: null };
}

/** The flags for this person now, under the design chosen here; all v2 screens off when the core can't say. */
export async function readUiState(): Promise<UiState> {
  const forced = typeof location !== "undefined" ? devOverride(location.search) : null;
  if (forced) return forced;
  let server = ALL_OFF;
  try {
    server = parseUiState(await api.uiFlags());
  } catch {
    // An older core: no feature flags; the design still decides the screens.
  }
  // A 2.0.0-g server has applied the person's design to its flags already.
  return server.design ? server : applyDesign(server, readDesign());
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
  // A design chosen in this window or another is read again at once (not while recording: then at the next screen).
  const [chosen, setChosen] = useState(0);
  useEffect(() => {
    const again = () => setChosen((n) => n + 1);
    const fromAnother = (e: StorageEvent) => { if (e.key === DESIGN_KEY) again(); };
    window.addEventListener(DESIGN_EVENT, again);
    window.addEventListener("storage", fromAnother);
    return () => {
      window.removeEventListener(DESIGN_EVENT, again);
      window.removeEventListener("storage", fromAnother);
    };
  }, []);
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
  }, [enabled, screen, chosen]);
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
  return a.classic === b.classic && JSON.stringify(a.design) === JSON.stringify(b.design) && UI_FLAG_NAMES.every((n) => a.flags[n] === b.flags[n]);
}

/** Settings › Appearance's switch: what it offers now, and the choice (busy while it is sent, its error in words). */
export function useDesignSwitch(): { canTry: boolean; canSwitchBack: boolean; busy: boolean; error: string | null; choose: (design: Design) => Promise<void> } {
  const [state, setState] = useState<UiState | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let live = true;
    const read = () => void readUiState().then((s) => live && setState(s));
    read();
    window.addEventListener(DESIGN_EVENT, read);
    return () => {
      live = false;
      window.removeEventListener(DESIGN_EVENT, read);
    };
  }, []);
  const offer = state ? designOffer(state) : { canTry: false, canSwitchBack: false };
  const choose = async (design: Design) => {
    setBusy(true);
    setError(null);
    try {
      await chooseDesign(design);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return { ...offer, busy, error, choose };
}
