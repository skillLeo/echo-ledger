// The app's frame in three levels (PLAN_NAV_3_LEVELS): the rail (● Record, Library, Projects, Live, Ask, Activity; at
// its foot Setup & health, Help, Settings and the avatar, labelled Account), the selected place's panel (hidden by ⊟ or
// ⌘\ and remembered per place; in a small window a flyout from the rail that closes once something is chosen), and the
// page under the one title row (UX-20: ← →, the page's title and count, the search, the status items). The rail and the
// panel run to the top: on a Mac the window's buttons sit in the rail's top cell. Clicking the selected place again
// starts that place over; ⌘1…⌘6 go to the places.
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { Avatar, Icons, Status } from "../components";
import { Menu, type MenuItem } from "../components/Menu";
import { useTip } from "../components/Tip";
import { initials } from "../settings/initials";
import { useMenuPart } from "../actions/menuState";
import { PageTitleSlot, usePageTitleSlot } from "../pageTitle";
import { accountTip, PageHistoryContext, PANEL_MAX, PANEL_MIN, PLACE_NAMES, PanelToggleContext, PlacePanel, placeList, RAIL_PLACES, RailItem, panelDefault, placeMenuItems, railFit, railItemProps, RailMore, SHOW_PANEL_EVENT, type FlyoutClose, type PageHistory, type Place } from "./NavRail";
import { TitleRow } from "./TitleRow";
import { readKept, writeKept } from "./kept";
import { FAILED_WORDS, railBadgeLabel } from "../../lib/badge";
import { RecordSplit } from "./RecordSplit";
import { useRailKeys } from "./railKeys";
import { matchesShortcut, menuItems, registerAction, runAction, shortcutOf, type ActionContext } from "../actions/registry";
import type { ShellStatus } from "./status";
import { WindowButtons } from "./WindowButtons";
import { BrandMark } from "../../components/BrandMark";
import { useWinTitlebar } from "../../lib/titlebar";
import { changePreferences, loadPreferences, PREFERENCES_EVENT, type Preferences } from "../../lib/shell";

/** Below this window width the panel is a flyout from the rail: tokens.css `--el-breakpoint-flyout` (a test keeps them one). */
export const FLYOUT_BELOW = 1100;
/** With v21_panel_flyout (UX-02): docked from this window width, a flyout below it (760–1199 px), unless pinned. */
export const DOCK_FROM = 1200;
const PANEL_KEY = "echoledger-nav-panels";
/** Whether the panel is pinned beside the page in a small window (UX-02). */
const PIN_KEY = "echoledger-nav-pinned-small";
// A layout choice is each account's own on this computer (kept.ts: readKept, writeKept).
function loadPinned(account: string | null | undefined): boolean {
  return readKept(PIN_KEY, account) === "1";
}
function savePinned(account: string | null | undefined, pinned: boolean) {
  writeKept(PIN_KEY, account, pinned ? "1" : "0");
}
/**
 * From this window height the rail has room for all of it (DES-01: the title row's top cell, Record, five places at 56
 * px, Setup, Help, Settings and Account); below it Help and Setup move into the avatar's menu, so the rail never clips
 * and never scrolls (720×560 at the least, FIX-08).
 */
export const RAIL_FULL_FROM = 680;
/**
 * The same for the compact rail (icons only, 44 px items): 126 px less (nine items 12 px shorter, and Record's name and
 * its 4 px gap), so all of it shows from the least window height (720×560, FIX-08).
 */
export const RAIL_FULL_FROM_COMPACT = 554;
const PRESS_HOLD_MS = 3000;
/** The actions whose keys the window answers here (the menu bar's, once FIX-05 draws it, take them first on a Mac). */
const RECORD_KEYS = ["file.newRecording", "file.newVoiceNote", "file.importAudio", "live.stopRecording"] as const;

/** Which places' panels are hidden, each account's own on this computer (shown unless hidden). */
function loadHidden(account: string | null | undefined): Set<Place> {
  try {
    const saved: unknown = JSON.parse(readKept(PANEL_KEY, account) ?? "[]");
    return new Set(Array.isArray(saved) ? saved.filter((p): p is Place => RAIL_PLACES.includes(p as Place)) : []);
  } catch {
    return new Set();
  }
}
function saveHidden(account: string | null | undefined, hidden: ReadonlySet<Place>) {
  writeKept(PANEL_KEY, account, JSON.stringify([...hidden]));
}

/** Each place's panel width, each account's own on this computer (the usual width where none is kept). */
const WIDTHS_KEY = "echoledger-nav-panel-widths";
export function loadWidths(account: string | null | undefined): Partial<Record<Place, number>> {
  try {
    const saved: unknown = JSON.parse(readKept(WIDTHS_KEY, account) ?? "{}");
    if (!saved || typeof saved !== "object") return {};
    return Object.fromEntries(Object.entries(saved).filter(([p, w]) => RAIL_PLACES.includes(p as Place) && typeof w === "number"
      && w >= PANEL_MIN && w <= PANEL_MAX)) as Partial<Record<Place, number>>;
  } catch {
    return {};
  }
}
function saveWidths(account: string | null | undefined, widths: Partial<Record<Place, number>>) {
  writeKept(WIDTHS_KEY, account, JSON.stringify(widths));
}

const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName)
    || !!target.closest('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]'));

export type NavShellProps = {
  /** The place shown (null: Home, the Record page). */
  place: Place | null;
  /** Go to a place (the window brings back how it was left); `again`: it was the place shown, so start it over. */
  onPlace: (place: Place, again: boolean) => void;
  onHome: () => void;
  status: ShellStatus;
  /** Each place's panel and, for some, its foot (the Library's Trash and sync line). */
  panels: Partial<Record<Place, ReactNode>>;
  feet?: Partial<Record<Place, ReactNode>>;
  /** The Library's Needs you: no longer a badge in the rail (DES-01: only Activity has a number), kept for the callers. */
  libraryCount?: number;
  activityCount?: number;
  /** Activity's badge in red: something failed (UX-08); the accent otherwise. */
  activityTone?: "accent" | "danger";
  /** Live's red dot: a call is recorded, or call recording is on and listening. */
  liveDot?: boolean;
  liveDotWords?: string;
  /** Setup & health's dot: something to fix. */
  setupAttention?: boolean;
  onSetupHealth: () => void;
  onHelp?: () => void;
  recording?: boolean;
  onRecord?: () => unknown;
  onUpload?: () => void;
  onVoiceNote?: () => void;
  /** Call recording on or off (Settings › Recording's switch), from Record's menu. */
  callRecording?: { on: boolean; toggle: () => void };
  /** Search everything (⌘K), starting from what was typed in the title row. */
  onSearch?: (typed?: string) => void;
  searchKeys?: string;
  /** At the end of the title row: status items that show only when needed (Restart to update, the share badge, zoom). */
  titleEnd?: ReactNode;
  /** The person (DES-08): their photo or their colour (avatar_color), and the plan's line for the account menu. */
  me?: { name: string; email: string; plan: string | null; usage?: string | null; color?: number | null; photo?: string | null } | null;
  /** The avatar's menu: the account, the plan, the workspace, the theme, sign out. */
  account?: MenuItem[];
  uploading?: string | null;
  notices?: ReactNode;
  banners?: ReactNode;
  /** v21_panel_flyout (UX-02): docked from 1200 px, a flyout with a scrim below it unless pinned, 0 px when hidden with
   * the toggle at the start of the page header, ⌃⌘S. Off: as before (docked from 1100 px). */
  panelFlyout?: boolean;
  /** Whose layout this is (the panels hidden, the pin): the server, the email and the workspace; null until known. */
  layoutFor?: string | null;
  children: ReactNode;
};

export function NavShell({ place, onPlace, onHome, status, panels, feet, activityCount, activityTone, liveDot, liveDotWords, setupAttention, onSetupHealth,
  onHelp, recording, onRecord, onUpload, onVoiceNote, callRecording, onSearch, searchKeys, me, account, uploading, notices, banners, panelFlyout = false, layoutFor = null, titleEnd, children }: NavShellProps) {
  const [width, setWidth] = useState(() => (typeof window === "undefined" ? 1440 : window.innerWidth));
  const [height, setHeight] = useState(() => (typeof window === "undefined" ? 900 : window.innerHeight));
  useEffect(() => {
    const follow = () => {
      setWidth(window.innerWidth);
      setHeight(window.innerHeight);
    };
    window.addEventListener("resize", follow);
    return () => window.removeEventListener("resize", follow);
  }, []);
  const [pinned, setPinned] = useState(() => loadPinned(layoutFor));
  const small = width < (panelFlyout ? DOCK_FROM : FLYOUT_BELOW);
  // A small window's panel is a flyout, unless pinned there (v21_panel_flyout).
  const narrow = small && !(panelFlyout && pinned);
  // Windows' own title row (v21_win_titlebar, wintitle.rs): the mark in the rail's top cell, the window's buttons at the
  // title row's end.
  const winRow = useWinTitlebar();
  // The compact rail (Settings › Appearance, View › Theme): icons only, each keeping its name for assistive tools and in
  // its tooltip ("Library ⌘1"); kept on this computer, followed at once.
  const [compactRail, setCompactRail] = useState(() => loadPreferences().compactRail);
  useEffect(() => {
    const follow = (e: Event) => setCompactRail(!!(e as CustomEvent<Preferences>).detail?.compactRail);
    window.addEventListener(PREFERENCES_EVENT, follow);
    return () => window.removeEventListener(PREFERENCES_EVENT, follow);
  }, []);
  // A short window: Help and Setup move into the avatar's menu; every place keeps its name.
  const short = height < (compactRail ? RAIL_FULL_FROM_COMPACT : RAIL_FULL_FROM);
  const [hidden, setHidden] = useState<Set<Place>>(() => loadHidden(layoutFor));
  const [widths, setWidths] = useState(() => loadWidths(layoutFor));
  // Another account: its own layout.
  const [layoutOf, setLayoutOf] = useState(layoutFor);
  if (layoutFor !== layoutOf) {
    setLayoutOf(layoutFor);
    setHidden(loadHidden(layoutFor));
    setPinned(loadPinned(layoutFor));
    setWidths(loadWidths(layoutFor));
  }
  // UX-03: a place's panel width, kept for this account; null puts the usual width back.
  const setPanelWidth = (p: Place, w: number | null) => {
    setWidths((now) => {
      const next = { ...now };
      if (w === null) delete next[p];
      else next[p] = w;
      saveWidths(layoutFor, next);
      return next;
    });
  };
  const [flyout, setFlyout] = useState(false);
  const hasPanel = !!place && !!panels[place];
  const shown = hasPanel && (narrow ? flyout : !hidden.has(place!));
  // The flyout's opener (its rail item), to take focus back when it closes without a choice.
  const opener = useRef<HTMLElement | null>(null);
  const main = useRef<HTMLElement>(null);
  const box = useRef<HTMLDivElement>(null);
  // Where focus goes once the panel has come or gone (after the page is no longer inert): the page after a choice, the
  // flyout's opener, else where it would be lost (the panel gone: the page header's toggle; the panel back: into it).
  const focusNext = useRef<"main" | "opener" | "toggle" | "panel" | null>(null);
  useLayoutEffect(() => {
    const next = focusNext.current;
    if (!next) return;
    focusNext.current = null;
    const toggle = () => main.current?.querySelector<HTMLElement>(".el-titlerow__toggle") ?? null;
    if (next === "main") return main.current?.focus();
    if (next === "opener") return (opener.current?.isConnected ? opener.current : toggle())?.focus();
    const active = document.activeElement;
    if (active && active !== document.body && active.isConnected) return;
    (next === "toggle" ? toggle() : box.current?.querySelector<HTMLElement>(".el-panel button"))?.focus();
  });
  const closeFlyout = useCallback((why: FlyoutClose) => {
    focusNext.current = why === "chose" ? "main" : "opener";
    setFlyout(false);
  }, []);
  const togglePanel = useCallback(() => {
    if (!place || !panels[place]) return;
    if (narrow) {
      // ⌘\ opens the flyout from where focus is (the place's rail item when it has it), and closes it back there.
      if (flyout) return closeFlyout("hide");
      opener.current = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
        ? document.activeElement : document.querySelector<HTMLElement>(`.el-rail [data-place="${place}"]`);
      setFlyout(true);
      return;
    }
    focusNext.current = hidden.has(place) ? "panel" : "toggle";
    setHidden((now) => {
      const next = new Set(now);
      if (next.has(place)) next.delete(place);
      else next.add(place);
      saveHidden(layoutFor, next);
      return next;
    });
  }, [place, panels, narrow, flyout, closeFlyout, hidden, layoutFor]);
  // Another place: the flyout closes (it opens again from the rail).
  // Wide again: a flyout left open there is closed (it would open by itself when the window narrows).
  if (flyout && !narrow) setFlyout(false);
  const [lastPlace, setLastPlace] = useState(place);
  if (place !== lastPlace) {
    setLastPlace(place);
    if (flyout && !narrow) setFlyout(false);
  }
  // A place pressed (or its ⌘ key): there, as it was left, or started over when it was the place shown already; in a
  // narrow window its flyout opens too (and stays open when it was).
  const press = (p: Place) => {
    const again = p === place;
    if (narrow) {
      // Its rail item, or ⋯ More (or the avatar) when it is in there (a high zoom).
      opener.current = document.querySelector<HTMLElement>(`.el-rail [data-place="${p}"]`) ?? document.querySelector<HTMLElement>(".el-rail__more .el-rail__item")
        ?? document.querySelector<HTMLElement>(".el-rail__me .el-rail__item");
      setFlyout(!!panels[p]);
    }
    onPlace(p, again);
  };
  // The page's title in the row (pageTitle.tsx): the page mounted last.
  const [title, titleSlot] = usePageTitleSlot();
  // Back and Forward (UX-20): a page's own step first (usePageHistory), else the places visited, Home among them.
  const steps = useRef(new Map<string, PageHistory>());
  const [, setStepsSeen] = useState("");
  // In the order the pages were mounted (a page that has no step now keeps its place); the last is asked first.
  const historySlot = useMemo(() => {
    const seen = () => setStepsSeen([...steps.current].map(([k, v]) => `${k}:${v.back ? "b" : ""}${v.forward ? "f" : ""}`).join("|"));
    return {
      set(id: string, given: PageHistory | null) {
        steps.current.set(id, given ?? {});
        seen();
      },
      withdraw(id: string) {
        steps.current.delete(id);
        seen();
      },
    };
  }, []);
  const pageStep = (way: "back" | "forward") => [...steps.current.values()].reverse().find((h) => h[way])?.[way] ?? null;
  const [visits, setVisits] = useState<{ back: (Place | null)[]; forward: (Place | null)[]; at: Place | null }>({ back: [], forward: [], at: place });
  if (place !== visits.at) setVisits((v) => (place === v.at ? v : { back: [...v.back, v.at], forward: [], at: place }));
  const visit = (to: Place | null) => (to === null ? onHome() : onPlace(to, false));
  const walk = (way: "back" | "forward") => {
    const own = pageStep(way);
    if (own) return own();
    const list = way === "back" ? visits.back : visits.forward;
    if (!list.length) return;
    const to = list[list.length - 1];
    setVisits((v) => (way === "back"
      ? { back: v.back.slice(0, -1), forward: [...v.forward, v.at], at: to }
      : { back: [...v.back, v.at], forward: v.forward.slice(0, -1), at: to }));
    visit(to);
  };
  const canWalk = (way: "back" | "forward") => !!pageStep(way) || (way === "back" ? visits.back : visits.forward).length > 0;
  // ⌘F: the page's own search in the row (each press), else everything; the page may take it first (the Library).
  const [findSeq, setFindSeq] = useState(0);
  // ⌘1…⌘6 (Ctrl elsewhere): the places; ⌘\ the panel. Never while typing or behind a dialog.
  const keys = useRef<(e: KeyboardEvent) => void>(() => undefined);
  keys.current = (e) => {
    if (e.defaultPrevented) return;
    // Record's keys (DES-02, actions.json): ⌘N, ⇧⌘N, ⌘O and ⌘. (Ctrl on Windows), even while typing; never behind a dialog.
    for (const id of RECORD_KEYS) {
      const keys = shortcutOf(id, recordLive.current.ctx.platform);
      if (!keys || !matchesShortcut(e, keys)) continue;
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      e.preventDefault();
      runAction(id, recordLive.current.ctx);
      return;
    }
    const mod = e.metaKey || e.ctrlKey;
    if (mod && !e.altKey && !e.shiftKey && e.key.toLowerCase() === "f" && !document.querySelector('[role="alertdialog"], [aria-modal="true"]')) {
      e.preventDefault();
      if (title?.search) setFindSeq((n) => n + 1);
      else onSearch?.();
      return;
    }
    // ⌘[ ⌘] (Ctrl+[ ] and Alt+← → on Windows): Back and Forward, never while typing.
    const way = (mod && !e.altKey && !e.shiftKey && (e.key === "[" || e.key === "]")) ? (e.key === "[" ? "back" : "forward")
      : (e.altKey && !mod && !e.shiftKey && (e.key === "ArrowLeft" || e.key === "ArrowRight")) ? (e.key === "ArrowLeft" ? "back" : "forward") : null;
    if (way && !typing(e.target) && !document.querySelector('[role="alertdialog"], [aria-modal="true"]')) {
      e.preventDefault();
      walk(way);
      return;
    }
    if (!(e.metaKey || e.ctrlKey) || e.altKey || e.shiftKey || typing(e.target)) return;
    if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
    // ⌃⌘S: the Mac's own Show/Hide Sidebar (v21_panel_flyout); ⌘\ (Ctrl+\) as before.
    if (panelFlyout && e.metaKey && e.ctrlKey && e.key.toLowerCase() === "s") {
      e.preventDefault();
      togglePanel();
      return;
    }
    if (e.key === "\\") {
      e.preventDefault();
      togglePanel();
      return;
    }
    const at = /^[1-6]$/.test(e.key) ? Number(e.key) - 1 : -1;
    if (at < 0) return;
    e.preventDefault();
    press(RAIL_PLACES[at]);
  };
  useEffect(() => {
    const on = (e: KeyboardEvent) => keys.current(e);
    // The mouse's back (button 3) and forward (4), unless the page took them (the Library's open recording).
    const side = (e: MouseEvent) => {
      if ((e.button !== 3 && e.button !== 4) || e.defaultPrevented) return;
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      e.preventDefault();
      walkRef.current(e.button === 3 ? "back" : "forward");
    };
    window.addEventListener("keydown", on);
    window.addEventListener("mouseup", side);
    return () => {
      window.removeEventListener("keydown", on);
      window.removeEventListener("mouseup", side);
    };
  }, []);
  const walkRef = useRef(walk);
  walkRef.current = walk;
  // A hairline under the title row once the page under it has scrolled (any list in the content column).
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const el = main.current;
    if (!el) return;
    const on = (e: Event) => {
      const target = e.target instanceof HTMLElement ? e.target : null;
      if (target && !target.closest(".el-titlerow")) setScrolled(target.scrollTop > 0);
    };
    el.addEventListener("scroll", on, true);
    return () => el.removeEventListener("scroll", on, true);
  }, []);
  const [scrolledAt, setScrolledAt] = useState(place);
  if (place !== scrolledAt) {
    setScrolledAt(place);
    setScrolled(false);
  }
  // A page asks for its panel (Settings' home: Show groups).
  const showPanel = useRef<() => void>(() => undefined);
  showPanel.current = () => {
    if (!place || !panels[place]) return;
    if (narrow) {
      opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      setFlyout(true);
      return;
    }
    if (hidden.has(place)) togglePanel();
  };
  useEffect(() => {
    const on = () => showPanel.current();
    window.addEventListener(SHOW_PANEL_EVENT, on);
    return () => window.removeEventListener(SHOW_PANEL_EVENT, on);
  }, []);
  // One press records or stops; while the core answers, more presses do nothing.
  const [pressing, setPressing] = useState<{ from: boolean } | null>(null);
  if (pressing && !!recording !== pressing.from) setPressing(null);
  useEffect(() => {
    if (!pressing) return;
    const fallback = window.setTimeout(() => setPressing(null), PRESS_HOLD_MS);
    return () => window.clearTimeout(fallback);
  }, [pressing]);
  const stops = !!onRecord && !!recording;
  const pressRecord = () => {
    if (!onRecord) return onHome();
    if (pressing) return;
    setPressing({ from: !!recording });
    void Promise.resolve(onRecord()).catch(() => setPressing(null));
  };
  const mac = searchKeys?.startsWith("⌘") ?? false;
  // Record's menu (DES-02), every item an action of actions.json (UX-21): New recording, New voice note, Import audio
  // files…, the Auto-record tick, and the Record page (Home, now that the logo has left the rail).
  const recordCtx: ActionContext = { platform: mac ? "macos" : "windows", state: { canRecord: !!onRecord, recording: stops, autoRecord: !!callRecording?.on } };
  const recordMenu = menuItems([
    ...(onRecord ? ["file.newRecording"] : []), ...(onVoiceNote ? ["file.newVoiceNote"] : []), ...(onUpload ? ["file.importAudio"] : []),
    "-", ...(callRecording ? ["live.autoRecord"] : []), "-", "go.recordPage",
  ], recordCtx);
  const recordLive = useRef({ pressRecord, onVoiceNote, onUpload, callRecording, onHome, stops, ctx: recordCtx });
  recordLive.current = { pressRecord, onVoiceNote, onUpload, callRecording, onHome, stops, ctx: recordCtx };
  // What Record's actions do here, wherever they are asked for (this menu, the system's, the keys, later the menu bar).
  useEffect(() => {
    const r = recordLive;
    const offs = [
      registerAction("file.newRecording", () => { if (!r.current.stops) r.current.pressRecord(); }),
      registerAction("live.stopRecording", () => { if (r.current.stops) r.current.pressRecord(); }),
      registerAction("file.newVoiceNote", () => r.current.onVoiceNote?.()),
      registerAction("file.importAudio", () => r.current.onUpload?.()),
      registerAction("live.autoRecord", () => r.current.callRecording?.toggle()),
      registerAction("go.recordPage", () => r.current.onHome()),
    ];
    // A pick in a menu the system drew (native_menu.rs, the menu bar) runs from MainApp's one listener (menuState.ts).
    return () => offs.forEach((off) => off());
  }, []);
  // The menu bar's share of the shell (FIX-05): Back and Forward, the places, the sidebar, Find and Search Everything,
  // with the state their words and greying follow.
  const shellLive = useRef({ walk, press, togglePanel, onSearch, find: () => setFindSeq((n) => n + 1), hasSearch: !!title?.search });
  shellLive.current = { walk, press, togglePanel, onSearch, find: () => setFindSeq((n) => n + 1), hasSearch: !!title?.search };
  useEffect(() => {
    const r = shellLive;
    const offs = [
      registerAction("go.back", () => r.current.walk("back")),
      registerAction("go.forward", () => r.current.walk("forward")),
      ...(["library", "projects", "live", "ask", "activity"] as const).map((p) => registerAction(`go.${p}`, () => r.current.press(p))),
      registerAction("view.sidebar", () => r.current.togglePanel()),
      registerAction("edit.find", () => (r.current.hasSearch ? r.current.find() : r.current.onSearch?.())),
      registerAction("edit.searchEverything", () => r.current.onSearch?.()),
      // View › Theme › Compact rail: the same choice as Settings › Appearance's.
      registerAction("view.compactRail", () => changePreferences({ compactRail: !loadPreferences().compactRail })),
    ];
    return () => offs.forEach((off) => off());
  }, []);
  // Record's menu is the system's on a Mac: its picks come back through the window's one listener (MainApp's,
  // menuState.ts) to the actions registered here.
  useMenuPart({ state: {
    canGoBack: canWalk("back"), canGoForward: canWalk("forward"), sidebarHidden: hasPanel && !shown,
    canRecord: !!onRecord, recording: !!recording, autoRecord: !!callRecording?.on, compactRail,
  } });
  // ⊟ in the title row (UX-20, right after the window's buttons) while the panel is off the screen, and in a small window
  // while its flyout is open (expanded: focus goes back to it); in a small window, the pin (UX-02).
  const rowToggle = hasPanel && (narrow || !shown) ? {
    label: `Show the ${PLACE_NAMES[place!]} panel`,
    expanded: narrow ? shown : undefined,
    onToggle: (button: HTMLElement) => {
      if (narrow) {
        opener.current = button;
        setFlyout(true);
      } else togglePanel();
    },
  } : null;
  const pin = panelFlyout && small ? {
    pinned,
    onPin: () => {
      const next = !pinned;
      setPinned(next);
      savePinned(layoutFor, next);
      setFlyout(false);
      if (!next) focusNext.current = "toggle";
      // Unpinned: back to a flyout, closed; pinned: beside the page, shown.
      if (next && place) setHidden((now) => {
        if (!now.has(place)) return now;
        const shownAgain = new Set(now);
        shownAgain.delete(place);
        saveHidden(layoutFor, shownAgain);
        return shownAgain;
      });
    },
  } : undefined;
  // Only Activity shows a number (DES-01, UX-08); the Library has no badge.
  const counts: Partial<Record<Place, number | undefined>> = { activity: activityCount || undefined };
  const icons: Record<Place, Parameters<typeof RailItem>[0]["icon"]> = {
    library: Icons.LibraryIcon, projects: Icons.FolderIcon, live: Icons.WaveIcon, ask: Icons.AskIcon, activity: Icons.BellIcon, settings: Icons.SettingsIcon,
  };
  // A high zoom (UX-07): the places that don't fit whole above Settings and Account go into "⋯ More" (a menu of them),
  // or, when not even More fits whole, into the avatar's menu; none is ever cut. Their room is measured (the places' box
  // takes what the rail has left; an item is as tall as Settings), and again as it changes.
  const placesBox = useRef<HTMLDivElement>(null);
  const [space, setSpace] = useState<{ room: number; each: number } | null>(null);
  useLayoutEffect(() => {
    const el = placesBox.current;
    if (!el) return;
    const measure = () => {
      const item = el.closest(".el-rail")?.querySelector<HTMLElement>('.el-rail__foot [data-place="settings"]') ?? el.querySelector<HTMLElement>(".el-rail__item");
      const next = { room: el.getBoundingClientRect().height, each: item?.getBoundingClientRect().height ?? 0 };
      setSpace((was) => (was && was.room === next.room && was.each === next.each ? was : next));
    };
    measure();
    if (typeof ResizeObserver === "undefined") return;
    const watch = new ResizeObserver(measure);
    watch.observe(el);
    return () => watch.disconnect();
  }, [compactRail, short]);
  const places: Place[] = RAIL_PLACES.filter((p) => p !== "settings");
  const fit = space ? railFit(places.length, space.room, space.each) : { keep: places.length, more: false };
  const moved = places.slice(fit.keep);
  // Not even More fits whole: the places go into the avatar's menu; before the account is read (no avatar yet) they wait
  // for it, never a cut More (⌘1…⌘5 still reach them).
  const toAccount = !fit.more && moved.length > 0;
  const inMore = toAccount ? [] : moved;
  const inAccount = toAccount && me && account ? moved : [];
  const keeps = fit.keep;
  const railPlace = (p: Place) => (
    <RailItem key={p} place={p} icon={icons[p]} {...railItemProps(p)} current={place === p} count={counts[p]} countTone={p === "activity" ? activityTone : undefined} onClick={() => press(p)}
      expanded={narrow && panels[p] ? flyout && place === p : undefined}
      dot={p === "live" ? liveDot : undefined} dotLabel={p === "live" ? liveDotWords : undefined} dotKind={p === "live" ? "live" : undefined} />
  );
  // The avatar's menu (DES-08): in a short window Setup and Help come first in it; when not even More fits (UX-07), the
  // places before them, set apart by a hairline.
  const rest: MenuItem[] = [
    ...(short ? [{ label: "Setup & health", icon: Icons.ReadyIcon, onPick: onSetupHealth }, ...(onHelp ? [{ label: "Help", icon: Icons.HelpIcon, onPick: onHelp }] : [])] : []),
    ...(account ?? []),
  ];
  const accountItems: MenuItem[] = inAccount.length
    ? [...placeMenuItems(inAccount, place, icons, activityCount, press), ...rest.map((item, i) => (i === 0 ? { ...item, separator: true } : item))]
    : rest;
  // Activity's badge and Live's dot go with them onto the avatar, and it is the selected item while the page is one of them.
  const meCount = inAccount.includes("activity") && activityCount ? activityCount : undefined;
  // Red, and said, when something failed (UX-08), as on Activity's own item.
  const meFailed = !!meCount && activityTone === "danger";
  const meLive = !meCount && inAccount.includes("live") && !!liveDot;
  const meHere = !!place && inAccount.includes(place);
  const meName = [`Account, ${me?.name ?? ""}`, inAccount.length ? `with ${placeList(inAccount)}` : null, meCount ? `${PLACE_NAMES.activity} ${meCount}` : null,
    meFailed ? FAILED_WORDS : null,
    inAccount.includes("live") && liveDot && liveDotWords ? `${PLACE_NAMES.live} ${liveDotWords}` : null, short && setupAttention ? "needs setup" : null]
    .filter(Boolean).join(", ");
  const [meTip, meTipShown] = useTip(accountTip(inAccount, meFailed));
  // FIX-06: the rail is one Tab stop, ↑ ↓ Home End go through it.
  const railRef = useRef<HTMLElement>(null);
  const railKeys = useRailKeys(railRef);
  // The flyout over its scrim (v21_panel_flyout): the page behind it can't be reached; the rail can.
  const scrim = shown && narrow && panelFlyout;
  return (
    <div ref={box} className={`el-root el-shell el-shell--nav${shown ? " el-shell--panel" : ""}${narrow ? " el-shell--narrow" : ""}${short ? " el-shell--short" : ""}${panelFlyout ? " el-shell--flyouts" : ""}`} data-shell="v2">
      <nav ref={railRef} className={`el-rail${compactRail ? " el-rail--compact" : ""}`} aria-label="Main" onFocus={railKeys.onFocus} onKeyDownCapture={railKeys.onKeyDownCapture}>
        {/* The rail's top cell of the title row: the Mac's window buttons sit here (tauri.conf.json trafficLightPosition);
            it moves the window. 8 px in full screen; none on Windows under its system title bar, and with Windows' own
            title row (v21_win_titlebar) 48 px with the app's mark. */}
        <div className="el-rail__top" data-tauri-drag-region="deep" aria-hidden="true">
          {/* Where the buttons are (and 4 px round them): no drag region, nothing of ours that takes a press. */}
          <span className="el-rail__lights" data-tauri-drag-region="false" />
          {/* Windows' own title row (v21_win_titlebar): the app's mark, where Windows puts a window's icon. */}
          {winRow && <BrandMark size={20} className="el-rail__mark" />}
        </div>
        <RecordSplit stops={stops} time={status.kind === "recording" ? status.time : undefined} busy={!!pressing} onPress={pressRecord} menu={recordMenu} ctx={recordCtx} />
        <div ref={placesBox} className="el-rail__places">
          {places.slice(0, keeps).map(railPlace)}
          {inMore.length > 0 && (
            <RailMore places={inMore} current={place} icons={icons} activityCount={activityCount} activityTone={activityTone} liveDot={liveDot} liveDotWords={liveDotWords} onPick={press} />
          )}
        </div>
        <div className="el-rail__foot">
          {!short && <RailItem icon={Icons.ReadyIcon} label="Setup" current={false} onClick={onSetupHealth} dot={setupAttention} dotLabel="needs setup" />}
          {!short && onHelp && <RailItem icon={Icons.HelpIcon} label="Help" current={false} onClick={onHelp} />}
          {railPlace("settings")}
          {me && account && (
            <div className="el-rail__me">
              <Menu label={`Account, ${me.name}`} items={accountItems} align="start"
                header={(
                  <div className="el-account-head">
                    <Avatar initials={initials(me.name, me.email)} size="menu" color={me.color} seed={me.email} photo={me.photo} />
                    <div className="el-account-head__who">
                      {/* The spaces between the lines are for assistive tools (the menu's description); a column draws none. */}
                      <span className="el-account-head__name" dir="auto">{me.name}</span>
                      {me.name !== me.email && <>{" "}<span className="el-account-head__line" dir="auto">{me.email}</span></>}
                      {(me.plan || me.usage) && <>{" "}<span className="el-account-head__line" dir="auto">{[me.plan, me.usage].filter(Boolean).join(" · ")}</span></>}
                    </div>
                  </div>
                )}
                button={{ variant: "quiet", className: "el-rail__item el-rail__avatar", "aria-label": meName, "aria-current": meHere ? "page" : undefined, ...meTip,
                  text: <><span className="el-rail__icon"><Avatar initials={initials(me.name, me.email)} size="rail" color={me.color} seed={me.email} photo={me.photo} drawn />
                    {meCount ? <span className={`el-rail__count el-num${meFailed ? " el-rail__count--danger" : ""}`} data-count={railBadgeLabel(meCount) ?? undefined} aria-hidden="true" />
                      : meLive ? <span className="el-rail__dot el-rail__dot--live" aria-hidden="true" />
                        : short && setupAttention && <span className="el-rail__dot" aria-hidden="true" />}</span>
                    <span className="el-rail__label" aria-hidden="true">Account</span></> }} />
              {meTipShown}
            </div>
          )}
        </div>
      </nav>
      {scrim && <div className="el-panel-scrim" data-testid="panel-scrim" aria-hidden="true" />}
      {shown && (
        <PlacePanel place={place!} onHide={togglePanel} flyout={narrow} onClose={closeFlyout} pin={pin} width={widths[place!] ?? null}
          resize={{
            width: widths[place!] ?? panelDefault(panelFlyout),
            onWidth: (w) => setPanelWidth(place!, w),
            onReset: () => setPanelWidth(place!, null),
            // Let go below 160 px: closed, as ⊟ does (the flyout too); its width stays as it was.
            onClose: () => (narrow ? closeFlyout("hide") : togglePanel()),
          }}
          foot={<>
            {feet?.[place!]}
            {uploading && <p className="el-shell__uploads el-caption el-secondary" aria-live="polite" data-testid="sidebar-uploads"><span className="el-spinner" aria-hidden="true" /><span dir="auto">{uploading}</span></p>}
            <div className="el-shell__status el-caption">
              {status.kind === "setup" ? (
                <button type="button" className="el-shell__status-fix" onClick={onSetupHealth}><Status kind={status.kind} live>{status.text}</Status></button>
              ) : <Status kind={status.kind} live>{status.text}</Status>}
              {status.kind === "recording" && status.time && <span className="el-shell__time el-num" dir="ltr">{` · ${status.time}`}</span>}
            </div>
          </>}>
          {panels[place!]}
        </PlacePanel>
      )}
      <main className="el-shell__main" ref={main} tabIndex={-1} inert={scrim || undefined}>
        <TitleRow place={place} title={title} toggle={rowToggle} back={canWalk("back") ? () => walk("back") : null} forward={canWalk("forward") ? () => walk("forward") : null}
          onEverything={(typed) => onSearch?.(typed)} findSeq={findSeq} scrolled={scrolled} end={winRow ? <>{titleEnd}<WindowButtons /></> : titleEnd} />
        {notices}
        {banners}
        {/* The page's own ⊟ (PanelToggle) is the title row's now: none in its header. */}
        <PanelToggleContext.Provider value={null}>
          <PageTitleSlot.Provider value={titleSlot}>
            <PageHistoryContext.Provider value={historySlot}>{children}</PageHistoryContext.Provider>
          </PageTitleSlot.Provider>
        </PanelToggleContext.Provider>
      </main>
    </div>
  );
}
