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
//   event ui:flags                → the same shape, whenever the core's cached flags change
import { api, errorMessage, listenTo } from "../lib/ipc";
import { useCallback, useEffect, useRef, useState } from "react";

export const UI_FLAG_NAMES = [
  "ui_v2.shell", "ui_v2.home", "ui_v2.library", "ui_v2.recording_controls", "ui_v2.after_call", "ui_v2.live", "ui_v2.ask",
  "ui_v2.settings", "ui_v2.setup_health", "ui_v2.admin", "ui_v2.website", "live_assist", "v21_panel_flyout",
  // UX-20 on Windows: the main window's own title row (contract 2.0.0-s). The core decides the window from it (wintitle.rs)
  // and the page follows the core's answer (lib/titlebar.ts), not this list's.
  "v21_win_titlebar",
  // UX-07: zoom like Chrome in the main window (contract 2.0.0-r).
  "v21_zoom",
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

function forcedFlags(): UiState | null {
  return typeof location !== "undefined" ? devOverride(location.search) : null;
}

/** The core's answer as this window shows it: a 2.0.0-g server has applied the person's design to its flags already. */
function shownFrom(server: UiState): UiState {
  return server.design ? server : applyDesign(server, readDesign());
}

/**
 * readUiState, whether the read failed, and whether the answer is the server's. It is not when the read failed, or
 * before the core's first `GET /api/config` answer, when it says everything off with no design (as an older server with
 * nothing on does, which is taken the same way).
 */
async function readFromCore(): Promise<{ state: UiState; heard: boolean; failed: boolean }> {
  const forced = forcedFlags();
  if (forced) return { state: forced, heard: true, failed: false };
  let server = ALL_OFF;
  let failed = false;
  try {
    server = parseUiState(await api.uiFlags());
  } catch {
    // An older core: no feature flags; the design still decides the screens.
    failed = true;
  }
  return { state: shownFrom(server), heard: !same(server, ALL_OFF), failed };
}

/**
 * A window that hears `ui:flags` and whose read failed before it had any answer tries again after these waits (ms),
 * then takes the core's `ui:flags` at once. Without them a busy core at start would leave it waiting for an
 * announcement that may never come (the core says only a change), and then take the next change at once, in the middle
 * of a screen. The pill and the after-call card (`atOnce: false`) never wait: they draw at once and read again next time.
 */
export const READ_RETRY_MS: readonly number[] = [250, 1000];

/** The flags for this person now, under the design chosen here; all v2 screens off when the core can't say. */
export async function readUiState(): Promise<UiState> {
  return (await readFromCore()).state;
}

/** "Switch back to classic" (or to the new screens): the server's new answer. Errors reach the caller. */
export async function setClassic(classic: boolean): Promise<UiState> {
  return parseUiState(await api.uiClassicSet(classic));
}

/**
 * The flags in a view. Read at start and again whenever `screen` changes (a change applies on the next screen the
 * person opens) or a recording ends, never while `recording` is true, so nothing switches under a running call. The
 * third value is the `screen` the flags were last read for: a window that must not show stale flags waits until it
 * equals its key.
 *
 * A window can open before the core has its first `GET /api/config` answer, and then reads everything off. Until it
 * has the server's answer (or while its last read failed, tried again first: READ_RETRY_MS) it takes the core's
 * `ui:flags` at once, or when the recording ends if one runs; after that, a `ui:flags` change waits for the next screen
 * like any other. It listens before its first read, so no announcement falls between the two. Every answer is shown by
 * an effect of a render that is not recording, never straight from the event or the read. `atOnce: false` (the pill
 * and the after-call card, which read again before each thing they show) leaves `ui:flags` alone: what they show never
 * changes under the person.
 */
export function useUiFlags(enabled: boolean, screen: string, recording: boolean, options: { atOnce?: boolean } = {}): [UiState, (next: UiState) => void, string | null] {
  const atOnce = options.atOnce !== false;
  const [state, setState] = useState<UiState>(ALL_OFF);
  const [readFor, setReadFor] = useState<string | null>(null);
  const recordingRef = useRef(recording);
  recordingRef.current = recording;
  const enabledRef = useRef(enabled);
  enabledRef.current = enabled;
  // Whether this window shows the server's answer; until it does, `ui:flags` applies at once.
  const heard = useRef(false);
  // An answer (a read's, or the core's first `ui:flags`) waits here and is shown by an effect of a render that is not
  // recording: a recording whose status is set but not drawn yet never sees the flags change with its first render.
  const [answer, setAnswer] = useState<Answer | null>(null);
  // Reads tried again after a failure before any answer (READ_RETRY_MS), in this window's life.
  const retries = useRef(0);
  // The server's answer in the last `ui:flags`, and how many came: one that comes while a read is on its way is newer.
  const announced = useRef<UiState | null>(null);
  const announcements = useRef(0);
  const [listening, setListening] = useState(!atOnce);
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
    if (!atOnce) return;
    let live = true;
    const un = listenTo("ui:flags", (raw) => {
      if (!live || forcedFlags()) return;
      announced.current = parseUiState(raw);
      announcements.current += 1;
      if (heard.current || !enabledRef.current) return;
      setAnswer({ state: shownFrom(announced.current), heard: true, screen: null });
    });
    // Read even when listening fails (an older core): the window then works as before.
    const listens = () => live && setListening(true);
    un.then(listens, listens);
    return () => {
      live = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, [atOnce]);
  useEffect(() => {
    if (!enabled || !listening || recordingRef.current) return;
    let live = true;
    let timer = 0;
    const since = announcements.current;
    const read = () => void readFromCore().then((got) => {
      if (!live) return;
      if (atOnce && got.failed && !heard.current && retries.current < READ_RETRY_MS.length) {
        timer = window.setTimeout(() => { if (!heard.current) read(); }, READ_RETRY_MS[retries.current]);
        retries.current += 1;
        return;
      }
      // `ui:flags` heard while this read was on its way is newer; one heard before stands when the read had no answer of
      // the server's (it failed, or the core said everything off).
      const latest = announced.current;
      const take = latest !== null && (announcements.current !== since || !got.heard);
      setAnswer({ state: take ? shownFrom(latest) : got.state, heard: take || got.heard, screen });
    });
    read();
    return () => {
      live = false;
      window.clearTimeout(timer);
    };
  }, [atOnce, enabled, listening, screen, chosen, recording]);
  useEffect(() => {
    if (!answer || recording) return;
    // Only this answer is done: one set after it was drawn (React runs this effect before that update) stays for next.
    setAnswer((current) => (current === answer ? null : current));
    // The core's first `ui:flags` comes second to a read that has had the server's answer meanwhile.
    if (answer.screen === null && heard.current) return;
    heard.current = answer.heard;
    setState((prev) => (same(prev, answer.state) ? prev : answer.state));
    setReadFor(answer.screen ?? screen);
  }, [answer, recording, screen]);
  // The server's answer set from here ("Switch back to classic"): a later `ui:flags` waits for the next screen.
  const set = useCallback((next: UiState) => {
    heard.current = true;
    setState(next);
  }, []);
  return [state, set, readFor];
}

/** An answer waiting to be shown: the flags, whether they are the server's, and the screen read for (null: `ui:flags`). */
type Answer = { state: UiState; heard: boolean; screen: string | null };

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
