// The v2 Library (ui_v2.library), as PLAN_LIBRARY_FILTERS lays it out: what it shows is chosen in the sidebar (a
// library view, one of My views, a label, Trash: LibraryNav.tsx); one toolbar row (Search ⌘F, [Filters n],
// [Sort & display ▾]); the filters from the end side (F; 320 px, pushing the list from 1440 px, over it below: FilterPanel.tsx); the pills row
// only while filters are on; then the list, which starts within 120 px of the window's top. Rows have a checkbox on
// hover and the toolbar a Select all, so there is no Select button; picking turns the toolbar into the selection bar.
// A row's source logo or label filters by it. The recordings are the core's (library_list, refreshed from the server,
// reloaded on library:updated); counts come from them here, offline (facets.ts). One feedback system: the Toast, one at
// a time, with Undo after a delete. The row ⋮, a right-click and the selection bar's ⋮ are one menu (organise.tsx).
import { PanelToggle, usePageHistory } from "../shell/NavRail";
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode, type KeyboardEvent as ReactKeyboardEvent, type MouseEvent as ReactMouseEvent, type PointerEvent as ReactPointerEvent } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type BinItem, type BulkAction, type BulkExportFormat, type ClientFolder, type LibraryItem, type ListItem, type SearchResult, type SmartFolder } from "../../lib/ipc";
import { formatDuration, rowDate, timestamp } from "../../lib/format";
import { snippetParts } from "../../lib/player";
import { eraseIn } from "../../views/main/LibraryOrganise";
import { hoursWords, useTrashKeepHours } from "../../lib/trash";
import type { NoticeTarget, Notify } from "../../lib/notice";
import { RECOVERED_CHIP, useRecovered } from "../../lib/recovered";
import {
  Badge, Banner, Button, Card, Dialog, PickBox, useFocusTrap, EmptyState, Icons, List, ListRow, ListSection, Loading, SearchField, SkeletonRow,
  Toast, ToastRegion, BackToTop, scrollRootOf,
} from "../components";
import { binHours, binSentence, isUntranscribed, rowModel, rowState, TIME_ONLY_GROUPS, type RowModel, type SavedView } from "./model";
import { RecordingRow } from "./RecordingRow";
import { useFitRow } from "./fitRow";
import { usePageTitle } from "../pageTitle";
import { GROUP_NAMES, GROUP_ORDER, sections, SORT_NAMES, SORT_ORDER } from "./filters";
import { contextItems, keyMatches, LIST_SPACE, manyTitle, RECORDING_MANY, RECORDING_ONE, TRASH, TRASH_ITEM } from "../actions/contextMenus";
import { confirmAction } from "../../lib/confirm";
import { appWords, currentPlatform, popupNative } from "../actions/popup";
import { useMenuPart } from "../actions/menuState";
import { choiceItems, menuItems, registerAction, runAction, shortcutOf, type ActionContext } from "../actions/registry";
import {
  activeSections, cleanSpec, clearSection, facetCounts, filterFacts, inLibraryView, inTab, LIBRARY_TAB_NAMES, matchesSearch, prepare, searchWords, shownTabs, tabCounts, toggleValue,
  type LibraryTab,
  type FilterSpec, type LibraryViewId, type ProjectNode, type SectionKey,
} from "./facets";
import { FilterPanel, FilterPills } from "./FilterPanel";
import { isView, useMyViews, viewsStore, type ViewSpec } from "./myViews";
import { ALL_SCOPE, fromFolder, fromOldView, scopeKey, scopeName, type LibraryScope } from "./scope";
import { syncLibrary } from "./syncClock";
import { ViewNameDialog } from "./LibraryNav";
import { groupPick, loadChoice, loadOpened, rememberOpened, saveChoice, toggleGroup, type LibraryViewChoice } from "../../lib/libraryGroups";
import { recordingName, type SortKey } from "../../lib/library";
import { beginRecordingDrag, dragJustEnded, dragLabel } from "../../lib/dragMove";
import { changePreferences, DENSITIES, loadPreferences, PREFERENCES_EVENT, type Density, type Preferences } from "../../lib/shell";
import { RecordingPage } from "./RecordingPage";
import { useRowViews } from "../../lib/rowViews";
import { LibraryTop, type LibraryTopProps } from "./LibraryTop";
import { QueueV2 } from "./QueueV2";
import { useLibraryQueue } from "../../views/main/LibraryQueue";
import { addToListMenu, listKey, NewLabelFor, useLists } from "./lists";
import { columnsMenu, LibraryTable, loadColumns, loadLayout, saveColumns, saveLayout, type Column, type Layout } from "./LibraryTable";
import { ContextMenu, Menu, menuPointFor, type MenuItem } from "../components/Menu";
import {
  copyForAiToClipboard, exportMenu, exportRecordings, knownLabels, shownExportView, LabelDialog, MoveDialog, recordingsWord, RenameDialog,
  sayProblem, TranscribeDialog, WhatsAppDialog, ZIP_FORMATS,
} from "./organise";
import { moveRecordings, movedText } from "../../views/main/ProjectDialogs";

/** The recording page's own parts of the menu; `ai` copies for AI in the view on screen (a row copies in its default view). */
export type RecordingMenuParts = { organise?: MenuItem[]; export?: MenuItem; export_more?: MenuItem[]; whatsapp?: () => void; ai?: () => void };

type Organise =
  | { kind: "move" | "label" | "transcribe"; ids: string[]; title?: string | null; current?: string | null; of?: number }
  | { kind: "rename" | "whatsapp"; item: LibraryItem };

/** How often the list syncs while something uploads or is transcribed (classic's)… */
export const RESYNC_MS = 5000;
/** …for this long; something still under way after it (a transcript stuck waiting) is asked about once a minute. */
export const RESYNC_FAST_FOR_MS = 10 * 60_000;
export const RESYNC_SLOW_MS = 60_000;
/** A transcript is being made for it. */
const isUnderWay = (i: LibraryItem) => ["pending", "submitted", "processing"].includes(i.transcript?.final_status ?? "");

/** The core's limits (src-tauri commands.rs MAX_BULK, MAX_TRANSCRIBE): more picked than this and the bar says so. */
const MAX_BULK = 200;
const MAX_TRANSCRIBE = 50;

/** Results follow the panel this long after the last tick (counts and list, instantly enough, never on every key). */
export const FILTER_DEBOUNCE_MS = 150;
/** Under this window width the filters slide over the list; from it they push it: tokens.css `--el-breakpoint-wide` (a
 * test keeps them one). */
export const DRAWER_BELOW = 1440;
const PANEL_KEY = "echoledger-library-filters-open";
/** The menu keys the Library answers through the registry (UX-22); with Open (Enter, the row's own), Move to trash
 * (Delete, ⌘⌫) and Select all (⌘A), the only shortcuts its menus show. */
const KEYED_HERE = ["file.rename", "recording.transcribe", "recording.important", "recording.unread"] as const;
const LIBRARY_KEYS: ReadonlySet<string> = new Set([...KEYED_HERE, "recording.open", "recording.moveToTrash", "edit.selectAll"]);

/**
 * UX-25: where the list was when a recording was opened from it: the place and view, the scroll, the recording and its
 * row's place in the list. Back from the recording the list is there again.
 */
type KeptPlace = { key: string; top: number; rowId: string; index: number };
/** How long the recording you came back from keeps its tint (the owner's "a bit of highlight" that stays). */
export const LAST_OPENED_MS = 60_000;
/** A search is one step of Back and Forward once typing has stopped this long. */
const SEARCH_STEP_MS = 700;
/** How many steps Back keeps. */
const HISTORY_MAX = 50;
/** A Library place as the window keeps it while another place is shown: its filters, search and tab. */
export type LibraryPlaceState = { scope: LibraryScope; spec: FilterSpec; query: string; tab: LibraryTab };
/** A place Back and Forward go to: what the sidebar chose, the filters and the search. */
type Place = { scope: LibraryScope; spec: FilterSpec; query: string };
/** The search box names its place where it has room: from --el-breakpoint-medium, and with the filters pushing the
 * list (the rail and the place's panel take their room too) from this width. */
const WIDE_WITH_PANEL = 1800;
const NARROW = 1024;
/**
 * The search's placeholder (UX-20: it names what it searches): "Search recordings ⌘F"; "Search ⌘F" where its box is
 * narrow (under 1024 px, or with the filters beside it below WIDE_WITH_PANEL).
 */
export function placeholderFor(width: number, filtersBeside: boolean): string {
  if (width >= (filtersBeside ? WIDE_WITH_PANEL : NARROW)) return `Search recordings ${MOD}F`;
  return `Search ${MOD}F`;
}

/** Keys the Library answers anywhere but in a field, a dialog or a menu. */
const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || !!target.closest('[role="dialog"], [role="alertdialog"], [role="menu"]'));
const MOD = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform) ? "⌘" : "Ctrl+";

type Message = { id: number; text: string; action?: { label: string; run: () => void }; error?: boolean };

/**
 * A notice of the queue's (classic's Notify) in the Library's toast. A fix done in place ("Deleted · Undo", its target
 * an action) becomes the toast's own button: "Deleted" with Undo, which runs it; the queue's other targets are places
 * this Library already is.
 */
export function queueNotice(say: (text: string, action?: Message["action"], error?: boolean) => void, text: string, error?: boolean, target?: NoticeTarget): void {
  if (target?.kind !== "action") return say(text, undefined, error);
  const tail = / · ([^·]+)$/.exec(text);
  say(tail ? text.slice(0, tail.index) : text, { label: tail ? tail[1] : "Undo", run: target.run }, error);
}

const readPanel = () => {
  try {
    return window.localStorage.getItem(PANEL_KEY) === "1";
  } catch {
    return false;
  }
};
const savePanel = (open: boolean) => {
  try {
    window.localStorage.setItem(PANEL_KEY, open ? "1" : "0");
  } catch {
    // Remembered for this session only.
  }
};

/** The window's size, kept up to date (the filters slide over the list under 1440 px, and are as tall as the window). */
function useWindowSize(): { width: number; height: number } {
  const [size, setSize] = useState(() => ({ width: window.innerWidth, height: window.innerHeight }));
  useEffect(() => {
    const on = () => setSize({ width: window.innerWidth, height: window.innerHeight });
    window.addEventListener("resize", on);
    return () => window.removeEventListener("resize", on);
  }, []);
  return size;
}

/** What a library view says when nothing is in it. */
const EMPTY_VIEW: Partial<Record<LibraryViewId, { title: string; body: string }>> = {
  needs: { title: "Nothing needs you", body: "Uploads and transcripts that failed, calls waiting for your decision and recordings stuck for a day show here." },
  unread: { title: "You've opened every recording", body: "New recordings show here until you open them." },
  important: { title: "Nothing marked important yet", body: "Recordings you mark important show here." },
  // Since v2.1 one view (Not transcribed merged into it): every recording kept without a transcript.
  audio_only: { title: "No audio-only recordings", body: "Recordings kept as audio, without a transcript, show here to transcribe later." },
};

export type LibraryV2Props = {
  /** Import (the empty Library's button; the sidebar's ● Record ▾ has it too). */
  onImport?: () => void;
  onRecord?: () => void;
  /** Open this recording (a notice, the palette, a link); `seq` makes the same one open again. */
  openRequest?: { id: string; seq: number; ms?: number } | null;
  /** Open the calls waiting for a decision (Activity's Review): Needs you, its list open; `seq` makes it open again. */
  reviewRequest?: { seq: number } | null;
  /** A view from before the redesign (Home's "isn't transcribed", a notice): opened where it lives now. */
  viewRequest?: { view: SavedView; seq: number } | null;
  /** What the sidebar chose: a library view, one of My views, a label or Trash; `seq` makes the same one open again. */
  /** Open a place; `restore` (the rail's per-place memory) brings back its filters, search and tab as left. */
  scopeRequest?: { scope: LibraryScope; seq: number; restore?: LibraryPlaceState } | null;
  /** Where the Library is now, as it would be restored (MainApp keeps it while another place is shown). */
  onPlace?: (place: LibraryPlaceState) => void;
  /** The Library moved somewhere itself (Save as view opens the new view; Needs you from Activity's Review). */
  onScope?: (scope: LibraryScope) => void;
  /** Open the filters (the sidebar's "+ New view"); `seq` makes it open again. */
  filtersRequest?: { seq: number } | null;
  /** The recordings and those opened here since they were read: the sidebar counts from them. */
  onItems?: (items: readonly LibraryItem[], read: ReadonlySet<string>) => void;
  /** A smart folder from before the redesign (the palette, a notice): its Source or Project filter. */
  folder?: SmartFolder | null;
  /** The filters were cleared: the folder asked for is done with. */
  onFolder?: (folder: SmartFolder | null) => void;
  /** Library as the start screen (plan 2): the get-started card on top. */
  top?: Omit<LibraryTopProps, "items"> & { onActivity: () => void };
  /** Opens a project (the recording page's project name). */
  onOpenProject?: (id: string) => void;
  /** The recording that was open when the main window was last closed: opened again once it is known. */
  initialOpen?: string | null;
  /** The open recording changed (null: back to the list), to be kept for the next main window. */
  onOpenChange?: (id: string | null) => void;
};

export function LibraryV2({ onImport, onRecord, openRequest, viewRequest, reviewRequest, scopeRequest, onScope, onPlace, filtersRequest, onItems, top, onOpenProject, folder = null, onFolder, initialOpen = null, onOpenChange }: LibraryV2Props) {
  const recovered = useRecovered();
  const [items, setItems] = useState<LibraryItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [scope, setScopeNow] = useState<LibraryScope>(ALL_SCOPE);
  const [query, setQuery] = useState("");
  const [tab, setTab] = useState<LibraryTab>("all");
  const [openId, setOpenIdNow] = useState<string | null>(null);
  // Recently opened (Sort by) remembers each recording opened here.
  const [opened, setOpened] = useState<Record<string, number>>(() => loadOpened());
  // Opened here since the Library loaded: read at once (Unread and its count), before the server's word comes back.
  const [readHere, setReadHere] = useState<ReadonlySet<string>>(new Set());
  const setOpenId = useCallback((id: string | null) => {
    if (id) {
      // Opened from the list: keep its place for the way back (UX-25), and the recording starts at its own top.
      const list = !openIdRef.current ? root.current?.querySelector(".el-lib-list") : null;
      if (list) {
        const box = scrollRootOf(root.current);
        const rows = [...list.querySelectorAll<HTMLElement>("[data-row-id]")];
        returning.current = { key: viewKeyRef.current, top: box?.scrollTop ?? 0, rowId: id, index: Math.max(0, rows.findIndex((r) => r.dataset.rowId === id)) };
        scrollTopOnOpen.current = true;
      }
      setLastOpened(null);
      setOpened(rememberOpened(id));
      setReadHere((r) => (r.has(id) ? r : new Set(r).add(id)));
      // Read on every device (POST /api/sessions/{id}/opened). Offline it stays read here; the dot is only a hint.
      void api.markOpened(id).catch(() => undefined);
    }
    openIdRef.current = id;
    setOpenIdNow(id);
  }, []);
  const openIdRef = useRef<string | null>(null);
  openIdRef.current = openId;
  /** The place to come back to from the recording open now (UX-25). */
  const returning = useRef<KeptPlace | null>(null);
  const scrollTopOnOpen = useRef(false);
  /** The recording you came back from: a quiet tint on its row until another is opened or clicked, the view, tab or
   * filters change, or 60 s pass (UX-25). Not the selection's colour. */
  const [lastOpened, setLastOpened] = useState<{ id: string; key: string } | null>(null);
  // The filters: what the panel and the pills show at once (`spec`), and what the list and the counts follow
  // (`applied`), 150 ms after the last tick; a change from elsewhere (a view, Clear all) applies at once.
  const [spec, setSpecNow] = useState<FilterSpec>({});
  const [applied, setApplied] = useState<FilterSpec>({});
  const debounce = useRef(0);
  /** This place and view: what a kept position belongs to (UX-25). */
  const viewKey = JSON.stringify([scopeKey(scope), tab, cleanSpec(applied), query.trim()]);
  const viewKeyRef = useRef(viewKey);
  viewKeyRef.current = viewKey;
  useEffect(() => {
    setLastOpened((l) => (l && l.key !== viewKey ? null : l));
  }, [viewKey]);
  useEffect(() => {
    if (!lastOpened) return;
    const timer = window.setTimeout(() => setLastOpened(null), LAST_OPENED_MS);
    return () => window.clearTimeout(timer);
  }, [lastOpened]);
  const setSpec = useCallback((next: FilterSpec, at: "now" | "soon" = "now") => {
    setSpecNow(next);
    window.clearTimeout(debounce.current);
    if (at === "now") setApplied(next);
    else debounce.current = window.setTimeout(() => setApplied(next), FILTER_DEBOUNCE_MS);
  }, []);
  useEffect(() => () => window.clearTimeout(debounce.current), []);
  const { lists, reload: reloadLists } = useLists();
  const myViews = useMyViews();
  const [newLabel, setNewLabel] = useState<{ ids: string[] } | null>(null);
  const [savingView, setSavingView] = useState(false);
  // The filter panel: open or closed is remembered; under 1440 px it slides over the list and starts closed.
  const { width, height } = useWindowSize();
  const drawer = width < DRAWER_BELOW;
  const [panel, setPanelNow] = useState(() => readPanel() && window.innerWidth >= DRAWER_BELOW);
  const setPanel = useCallback((open: boolean) => {
    setPanelNow(open);
    savePanel(open);
  }, []);
  // Density, shared with Settings › Appearance (classic's View menu has it too): both follow each other at once.
  const [density, setDensityNow] = useState<Density>(() => loadPreferences().density);
  useEffect(() => {
    const follow = (e: Event) => {
      const prefs = (e as CustomEvent<Preferences>).detail;
      setDensityNow(prefs?.density ?? loadPreferences().density);
    };
    window.addEventListener(PREFERENCES_EVENT, follow);
    return () => window.removeEventListener(PREFERENCES_EVENT, follow);
  }, []);
  const setDensity = (next: Density) => {
    changePreferences({ density: next });
    setDensityNow(next);
  };
  // Sort and group, remembered on this computer (classic's View menu); a saved view keeps its own.
  const [choice, setChoiceNow] = useState<LibraryViewChoice>(() => loadChoice("all"));
  const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(new Set());
  const [syncing, setSyncing] = useState(false);
  // The server didn't answer a sync: the list shown is what this computer saved (classic's banner, with Retry).
  const [syncError, setSyncError] = useState<string | null>(null);
  // Show as List or Table, and the table's columns, kept on this computer (classic's).
  const [layout, setLayoutNow] = useState<Layout>(loadLayout);
  const [columns, setColumnsNow] = useState<Column[]>(loadColumns);
  const setLayout = (l: Layout) => { setLayoutNow(l); saveLayout(l); };
  const setColumns = (c: Column[]) => { setColumnsNow(c); saveColumns(c); };
  const fold = (key: string) => setCollapsed((c) => { const n = new Set(c); if (n.has(key)) n.delete(key); else n.add(key); return n; });
  const [bin, setBin] = useState<BinItem[] | null>(null);
  const [binError, setBinError] = useState<string | null>(null);
  const [erase, setErase] = useState<BinItem | null>(null);
  const [message, setMessage] = useState<Message | null>(null);
  const seq = useRef(0);
  // Keyboard focus after a change that takes away the focused control: the next row after a delete, the recording
  // again after Undo, else the search field. Applied once, when the list has been drawn, and only if focus was lost
  // (on nothing or the page): never taken from where the person has moved it since.
  const [focusAfter, setFocusAfter] = useState<{ rowId: string | null; seq: number } | null>(null);
  const root = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!focusAfter) return;
    setFocusAfter(null);
    const el = root.current;
    const rowOf = (id: string | null) => (id ? el?.querySelector<HTMLElement>(`[data-row-id="${CSS.escape(id)}"]`) ?? null : null);
    // Back from a recording opened here (UX-25): the list where it was; the recording's row tinted, or (gone since) the
    // row now nearest its place.
    const back = returning.current;
    returning.current = null;
    const here = back && back.key === viewKeyRef.current && !!el?.querySelector(".el-lib-list") ? back : null;
    const box = here ? scrollRootOf(el) : null;
    if (box && here) box.scrollTop = here.top;
    const active = document.activeElement;
    if (active && active !== document.body) return;
    let row = rowOf(focusAfter.rowId);
    if (here) {
      if (row && focusAfter.rowId === here.rowId) setLastOpened({ id: here.rowId, key: here.key });
      else if (!row) {
        const rows = [...(el?.querySelectorAll<HTMLElement>(".el-lib-list [data-row-id]") ?? [])];
        row = rows[Math.min(here.index, rows.length - 1)] ?? null;
      }
    }
    if (row && here && box) {
      row.focus({ preventScroll: true });
      const r = row.getBoundingClientRect();
      const b = box.getBoundingClientRect();
      if (r.height && (r.top < b.top || r.bottom > b.bottom)) row.scrollIntoView?.({ block: "nearest" });
      return;
    }
    (row ?? el?.querySelector<HTMLElement>('input[type="search"]'))?.focus();
  }, [focusAfter]);
  const keepHours = useTrashKeepHours();
  const [selecting, setSelecting] = useState(false);
  const [picked, setPicked] = useState<ReadonlySet<string>>(new Set());
  const anchor = useRef<string | null>(null);
  /** Our right-click menu (Windows; a Mac shows the system's): a recording's or the picked ones', or the list's space. */
  const [context, setContext] = useState<{ at: { x: number; y: number }; ids: string[]; space?: true; trash?: true } | null>(null);
  const [organise, setOrganise] = useState<Organise | null>(null);
  const endSelection = useCallback(() => {
    setSelecting(false);
    setPicked(new Set());
    anchor.current = null;
  }, []);

  const say = useCallback((text: string, action?: Message["action"], error?: boolean) => setMessage({ id: ++seq.current, text, action, error }), []);
  // The queue at the top of the list (waiting calls, uploads), kept here rather than in the list: it stays loaded while
  // the list is away (Trash, a selection, a recording open).
  const queue = useLibraryQueue(useCallback<Notify>((text, error, target) => queueNotice(say, text, error, target), [say]));

  const load = useCallback(async () => {
    try {
      setItems(await api.library());
      setError(null);
    } catch (e) {
      setError(errorMessage(e));
    }
  }, []);
  const loadBin = useCallback(async () => {
    try {
      setBin((await api.bin())?.data ?? []);
      setBinError(null);
    } catch (e) {
      setBinError(errorMessage(e));
    }
  }, []);
  // Imported (a Source choice) is the server's word: recordings carry it in their facets since 2.0.0-p; for an older
  // server this computer asks which recordings it files there, once and on each change.
  const [imported, setImported] = useState<ReadonlySet<string> | undefined>(undefined);
  // The person's projects as a tree: Project offers them all, and "Project is Acme" keeps Acme's subfolders too.
  const [projects, setProjects] = useState<readonly ProjectNode[] | undefined>(undefined);
  const loadImported = useCallback(() => {
    api.libraryFolder("imported").then((list) => setImported(new Set((list ?? []).filter((i) => !i.deleted).map((i) => i.id)))).catch(() => undefined);
    api.clients().then((r) => setProjects((r?.data ?? []).map((p) => ({ id: p.id, name: p.name, parent_id: p.parent_id ?? null })))).catch(() => undefined);
  }, []);

  // The rows' "Converting to Roman Urdu… 60%" (U19): asked about while written; once one is ready or failed the
  // Library syncs, for the title the server may have written in Roman Urdu meanwhile.
  const quietSync = useCallback(() => {
    syncLibrary().then(setItems).catch(() => undefined);
  }, []);
  const rowViews = useRowViews(items, quietSync);
  useEffect(() => {
    void load().then(() => syncLibrary().then((list) => { setItems(list); setSyncError(null); }).catch((e) => setSyncError(errorMessage(e))));
    loadImported();
    const un = listen("library:updated", () => {
      void load();
      loadImported();
    });
    return () => {
      void un.then((f) => f());
    };
  }, [load, loadImported]);
  // The sidebar counts from the same recordings.
  const onItemsRef = useRef(onItems);
  onItemsRef.current = onItems;
  useEffect(() => {
    if (items) onItemsRef.current?.(items, readHere);
  }, [items, readHere]);
  // While something uploads or is transcribed the list syncs every 5 s (classic's), until nothing is under way.
  const busyItems = (items ?? []).some((i) => !i.deleted && (i.status === "uploading" || isUnderWay(i)));
  useEffect(() => {
    if (!busyItems) return;
    const started = Date.now();
    let timer = 0;
    const next = () => {
      timer = window.setTimeout(() => {
        syncLibrary().then((list) => { setItems(list); setSyncError(null); }).catch((e) => setSyncError(errorMessage(e)));
        next();
      }, Date.now() - started < RESYNC_FAST_FOR_MS ? RESYNC_MS : RESYNC_SLOW_MS);
    };
    next();
    return () => window.clearTimeout(timer);
  }, [busyItems]);
  const trash = scope.kind === "trash";
  useEffect(() => {
    if (trash) void loadBin();
    endSelection();
  }, [trash, loadBin, endSelection]);
  const savedView = scope.kind === "saved" ? myViews.find((v) => isView(v, scope.id)) ?? null : null;
  const setChoice = (next: LibraryViewChoice) => {
    setChoiceNow(next);
    if (!savedView && !trash) saveChoice("all", next);
  };
  const sync = async () => {
    setSyncing(true);
    try {
      setItems(await syncLibrary());
      setError(null);
      setSyncError(null);
    } catch (e) {
      setSyncError(errorMessage(e));
    } finally {
      setSyncing(false);
    }
  };

  // The scope asked for last (set at once, before the state follows): what the checks below look at.
  const scopeNow = useRef<LibraryScope>(ALL_SCOPE);
  /**
   * Shows a scope (from the sidebar, a request, or here): its filters, search and sort (a saved view's own), or none.
   * `report`: say so to the sidebar (when it came from here).
   */
  const show = useCallback((next: LibraryScope, filters: FilterSpec = {}, report = false, exact?: { query: string }) => {
    scopeNow.current = next;
    setScopeNow(next);
    setOpenIdNow(null);
    setCollapsed(new Set());
    setTab("all");
    const view = next.kind === "saved" ? viewsStore.list().find((v) => isView(v, next.id)) : null;
    if (exact) {
      // Back or Forward: the place as it was left, its filters and search exactly.
      setSpec(filters);
      setQuery(exact.query);
      if (view) setChoiceNow({ sort: view.spec.sort, group: view.spec.group });
      else setChoiceNow(loadChoice("all"));
    } else if (view) {
      setSpec(view.spec.filters);
      setQuery(view.spec.search ?? "");
      setChoiceNow({ sort: view.spec.sort, group: view.spec.group });
    } else {
      setSpec(filters);
      setQuery("");
      setChoiceNow(loadChoice("all"));
    }
    if (report) onScope?.(next);
  }, [onScope, setSpec]);
  useEffect(() => {
    if (!scopeRequest) return;
    const r = scopeRequest.restore;
    show(scopeRequest.scope, r?.spec ?? {}, false, r ? { query: r.query } : undefined);
    setTab(r?.tab ?? "all");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scopeRequest]);
  // A view from before the redesign opens where it lives now (Today: All with Date: Today; Waiting: Needs you…).
  useEffect(() => {
    if (!viewRequest) return;
    const { scope: to, filters } = fromOldView(viewRequest.view);
    show(to, filters, true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewRequest]);
  // Activity's Review: Needs you, the waiting calls' list open (QueueV2 opens it for the request).
  useEffect(() => {
    if (reviewRequest) show({ kind: "view", view: "needs" }, {}, true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reviewRequest]);
  // A smart folder from before the redesign: All with its Source or Project chosen.
  useEffect(() => {
    if (!folder) return;
    const filters = fromFolder(folder);
    if (filters) show(ALL_SCOPE, filters, true);
    // Taken: the same folder can be asked for again.
    onFolder?.(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [folder]);
  // The sidebar's "+ New view": the filters open, to choose some and Save as view.
  useEffect(() => {
    if (filtersRequest) setPanel(true);
  }, [filtersRequest, setPanel]);
  // The label shown was deleted, or the view: back to All (the scope asked for last: a rename asks for the new name).
  useEffect(() => {
    const now = scopeNow.current;
    if (now.kind === "label" && lists && !lists.some((l) => l.key === now.key)) show(ALL_SCOPE, {}, true);
    if (now.kind === "saved" && !myViews.some((v) => v.id === now.id)) {
      // A view saved here has the server's id once it is sent: the same view, shown as it is.
      const sent = myViews.find((v) => isView(v, now.id));
      if (!sent) return show(ALL_SCOPE, {}, true);
      const next: LibraryScope = { kind: "saved", id: sent.id };
      scopeNow.current = next;
      setScopeNow(next);
      onScope?.(next);
    }
  }, [scope, lists, myViews, show, onScope]);

  // Back and Forward (⌘[ and ⌘], the mouse's back and forward buttons): through the places shown, each with its
  // filters and search as they were left. A search is one step once typing stops.
  const [searchStep, setSearchStep] = useState("");
  useEffect(() => {
    const timer = window.setTimeout(() => setSearchStep(query), SEARCH_STEP_MS);
    return () => window.clearTimeout(timer);
  }, [query]);
  // `settling`: a Back or Forward is under way; whatever the Library settles on meanwhile (a view deleted since falls
  // back to All recordings, a view saved offline has the server's id now) is where it went, never a new step.
  const steps = useRef<{ back: Place[]; forward: Place[]; now: Place | null; settling: number }>({ back: [], forward: [], now: null, settling: 0 });
  /** How many steps each way, as state: the title row's ← → (usePageHistory) are drawn from it, lit when there are some. */
  const [stepCounts, setStepCounts] = useState({ back: 0, forward: 0 });
  const countSteps = useCallback(() => {
    const h = steps.current;
    setStepCounts((c) => (c.back === h.back.length && c.forward === h.forward.length ? c : { back: h.back.length, forward: h.forward.length }));
  }, []);
  // A place that can't be shown any more: a view or a label deleted since.
  const goneFrom = (p: Place) => (p.scope.kind === "saved" && !viewsStore.list().some((v) => isView(v, p.scope.kind === "saved" ? p.scope.id : "")))
    || (p.scope.kind === "label" && !!lists && !lists.some((l) => l.key === (p.scope.kind === "label" ? p.scope.key : "")));
  // A view is the same place under the id it had here and the server's (its client_ref).
  const placeKey = (p: Place) => {
    const view = p.scope.kind === "saved" ? viewsStore.list().find((v) => isView(v, p.scope.kind === "saved" ? p.scope.id : "")) : undefined;
    return JSON.stringify([view?.client_ref ? `saved-ref:${view.client_ref}` : scopeKey(p.scope), cleanSpec(p.spec), p.query.trim()]);
  };
  useEffect(() => {
    const here: Place = { scope, spec: applied, query: searchStep };
    const h = steps.current;
    const key = placeKey(here);
    // Never two steps alike in a row, nor a step that is where the Library is now: every Back changes the place.
    const tidy = () => {
      while (h.back.length && placeKey(h.back[h.back.length - 1]) === key) h.back = h.back.slice(0, -1);
    };
    if (h.settling > 0) {
      h.now = here;
      tidy();
      countSteps();
      return;
    }
    if (h.now && placeKey(h.now) === key) return;
    // The place left is a step, unless it is gone (a view or label deleted since: the Library fell back from it) or
    // the same as the last step already.
    if (h.now && !goneFrom(h.now) && (!h.back.length || placeKey(h.back[h.back.length - 1]) !== placeKey(h.now))) {
      h.back = [...h.back, h.now].slice(-HISTORY_MAX);
      h.forward = [];
    }
    h.now = here;
    tidy();
    countSteps();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scope, applied, searchStep]);
  const walk = useCallback((way: "back" | "forward") => {
    const h = steps.current;
    const to = way === "back" ? h.back[h.back.length - 1] : h.forward[h.forward.length - 1];
    if (!to || !h.now) return false;
    if (way === "back") {
      h.back = h.back.slice(0, -1);
      h.forward = [...h.forward, h.now];
    } else {
      h.forward = h.forward.slice(0, -1);
      h.back = [...h.back, h.now];
    }
    // Where it goes is the place now, at once: a second press before the Library has settled steps on from there.
    h.now = to;
    h.settling++;
    window.setTimeout(() => {
      h.settling = Math.max(0, h.settling - 1);
    }, 0);
    show(to.scope, to.spec, true, { query: to.query });
    setSearchStep(to.query);
    countSteps();
    return true;
  }, [show, countSteps]);

  // The recording open when the main window closed opens again (classic's), once; what is opened after is kept.
  const restored = useRef(false);
  const restoredId = useRef<string | null>(null);
  useEffect(() => {
    if (!initialOpen || restored.current) return;
    restored.current = true;
    restoredId.current = initialOpen;
    setOpenIdNow((now) => now ?? initialOpen);
  }, [initialOpen]);
  // The kept recording was deleted (or is gone) since: once the list has loaded it isn't open, and isn't kept either.
  useEffect(() => {
    const id = restoredId.current;
    if (!id || !items) return;
    restoredId.current = null;
    if (!items.some((i) => i.id === id && !i.deleted)) setOpenIdNow((now) => (now === id ? null : now));
  }, [items, initialOpen]);
  /** Marks what is stuck again (set by the effect below; a change that moves things without a scroll calls it). */
  const remark = useRef<() => void>(() => undefined);
  // A stuck group heading also covers the scrolling box's top padding (UX-25 review): mark the stuck ones, and give
  // the cover the padding's height, as the list scrolls.
  useEffect(() => {
    const box = scrollRootOf(root.current);
    if (!box) return;
    let frame = 0;
    const mark = () => {
      frame = 0;
      const el = root.current;
      if (!el) return;
      const pad = parseFloat(getComputedStyle(box).paddingTop) || 0;
      // On the Library's root: the stuck headings and the stuck selection bar both cover the padding band.
      el.style.setProperty("--el-sticky-cover", `${pad}px`);
      const top = box.getBoundingClientRect().top + pad;
      const stuck = (h: HTMLElement) => box.scrollTop > 0 && Math.abs(h.getBoundingClientRect().top - top) < 1;
      for (const h of el.querySelectorAll<HTMLElement>(".el-lib-list > .el-list-section, .el-selectbar")) h.toggleAttribute("data-stuck", stuck(h));
    };
    const soon = () => {
      if (!frame) frame = requestAnimationFrame(mark);
    };
    remark.current = soon;
    box.addEventListener("scroll", soon, { passive: true });
    // A tab, a filter, a sort, a delete or a resize moves the headings without a scroll: marked again then too.
    window.addEventListener("resize", soon);
    const resized = typeof ResizeObserver === "function" ? new ResizeObserver(soon) : null;
    if (resized && root.current) resized.observe(root.current);
    return () => {
      box.removeEventListener("scroll", soon);
      window.removeEventListener("resize", soon);
      resized?.disconnect();
      cancelAnimationFrame(frame);
      remark.current = () => undefined;
    };
  }, []);
  // A recording opened from the list starts at its own top (UX-25 keeps the list's place for the way back). In the
  // Table it opens in a drawer over the list, which stays as it is.
  useEffect(() => {
    if (!openId || !scrollTopOnOpen.current) return;
    scrollTopOnOpen.current = false;
    if (layout !== "table" || trash) {
      const box = scrollRootOf(root.current);
      if (box) box.scrollTop = 0;
    }
    // Only when a recording opens: the layout and the place are read as they are then.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openId]);
  // The recording opened from the list is gone since (deleted elsewhere, or no longer here): back to the list, where it was.
  useEffect(() => {
    if (!openId || !items || returning.current?.rowId !== openId) return;
    if (items.some((i) => i.id === openId && !i.deleted)) return;
    setOpenIdNow(null);
    setFocusAfter({ rowId: null, seq: ++seq.current });
  }, [items, openId]);
  const reported = useRef<string | null | undefined>(undefined);
  const onOpenChangeRef = useRef(onOpenChange);
  onOpenChangeRef.current = onOpenChange;
  useEffect(() => {
    // Not on the first draw: the kept recording isn't forgotten before it is read back.
    if (reported.current === undefined) {
      reported.current = openId;
      return;
    }
    if (reported.current === openId) return;
    reported.current = openId;
    onOpenChangeRef.current?.(openId);
  }, [openId]);
  useEffect(() => {
    if (openRequest) {
      setOpenAt(openRequest.ms ? { id: openRequest.id, ms: openRequest.ms, seq: openRequest.seq } : null);
      setOpenId(openRequest.id);
    }
  }, [openRequest]);
  // Search inside transcripts (classic's "In transcripts"): from two letters, a moment after typing stops.
  const [found, setFound] = useState<SearchResult["data"]>([]);
  useEffect(() => {
    const q = query.trim();
    if (q.length < 2 || trash) {
      setFound([]);
      return;
    }
    let alive = true;
    const timer = window.setTimeout(() => {
      api.searchLibrary({ q }).then((r) => alive && setFound(r?.data ?? [])).catch(() => alive && setFound([]));
    }, 350);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [query, trash]);
  /** A transcript match: the recording opened at its moment. */
  const [openAt, setOpenAt] = useState<{ id: string; ms: number; seq: number } | null>(null);
  const openHit = (id: string, ms: number) => {
    setOpenAt({ id, ms, seq: ++seq.current });
    setOpenId(id);
  };

  // What the list is made of (facets.ts): every recording's facts once per change; the scope's (a library view, a
  // saved view's starting view, a label); searched; then the counts for the panel and the list itself.
  const prepared = useMemo(() => prepare(items ?? [], { read: readHere, imported, projects, lists: lists ?? undefined, now: Date.now() }),
    [items, readHere, imported, projects, lists]);
  // A saved view starts from All (the contract keeps filters, not a library view).
  const baseView: LibraryViewId = scope.kind === "view" ? scope.view : "all";
  const labelKey = scope.kind === "label" ? scope.key : null;
  // The search (facets.ts, as the contract has it): every word in the title, or every word in the transcript.
  const searched = useMemo(() => {
    if (trash) return [];
    const words = searchWords(query);
    return prepared.facts.filter((f) => inLibraryView(f, baseView) && (!labelKey || f.values.labels.includes(labelKey)) && matchesSearch(f, words));
  }, [prepared, baseView, labelKey, query, trash]);
  // Counted among what the view, the search and the tab keep (all apps: an option's count is what the list would show
  // with it, so the panel and the list never disagree); every option of the whole library offered. Worked out only
  // when asked for (the panel open, or a pill being edited), once per change: typing never counts on every key.
  const counts = useMemo(() => {
    let made: ReturnType<typeof facetCounts> | null = null;
    return () => (made ??= facetCounts(searched.filter((f) => inTab(f, tab)), applied, prepared.names, Date.now(), undefined, prepared.facts));
  }, [searched, applied, prepared, tab]);
  // Needs you lists the server's waiting recordings too (a call waiting for Keep or Discard: session `recording`,
  // which no other view has), whatever the filters and the search, as the server's Needs you does.
  const waitingHere = useMemo(() => (baseView === "needs" && !trash ? (items ?? []).filter((i) => !i.deleted && i.status === "recording") : []), [items, baseView, trash]);
  // The page's tabs (All · Calls · Meetings · Voice notes), by Source, ANDed with the filters; counted with the
  // place, the filters and the search.
  const filtered = useMemo(() => filterFacts(searched, applied), [searched, applied]);
  const tabCount = useMemo(() => tabCounts(filtered), [filtered]);
  // Other only while it has recordings, or is open (contract 2.0.0-q: the tabs add up to All).
  const tabsShown = useMemo(() => shownTabs(tabCount, tab), [tabCount, tab]);
  const shownItems = useMemo(() => [...waitingHere, ...filtered.filter((f) => inTab(f, tab)).map((f) => f.item)], [waitingHere, filtered, tab]);
  // Kept by the window while another place is shown, to come back to as it was.
  const placeNow = useRef(onPlace);
  placeNow.current = onPlace;
  useEffect(() => {
    placeNow.current?.({ scope, spec: applied, query, tab });
  }, [scope, applied, query, tab]);
  // Only pinned recordings on screen already (Marked is Pinned alone): no Pinned section of its own.
  const onlyPinned = applied.marked?.values.length === 1 && applied.marked.values[0] === "pinned" && applied.marked.op !== "is_not";
  const groups = useMemo(() => sections(shownItems, choice.group, choice.sort, opened, !onlyPinned),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [shownItems, choice, opened, onlyPinned]);
  const shown = useMemo(() => groups.filter((g) => !collapsed.has(g.key)).flatMap((g) => g.items), [groups, collapsed]);
  const matching = shownItems.length;
  const filtersOn = activeSections(spec).length;
  // Row 2 never cuts a label: the end's words go first, then the tabs scroll (fitRow.ts).
  const row2 = useRef<HTMLDivElement>(null);
  const row2Tabs = useRef<HTMLDivElement>(null);
  const row2End = useRef<HTMLDivElement>(null);
  const row2Fit = useFitRow(row2, row2Tabs, row2End);
  /** The list as drawn (for a delete's next row): the rows on screen, folded sections left out. */
  const listOf = (_all: readonly LibraryItem[] | null) => ({ rows: shown });

  const remove = async (item: LibraryItem) => {
    const title = item.title?.trim() || "Recording";
    try {
      await api.deleteSession(item.id);
      const order = listOf(items).rows;
      const at = order.findIndex((i) => i.id === item.id);
      const next = order[at + 1] ?? order[at - 1] ?? null;
      setItems((list) => list?.filter((i) => i.id !== item.id) ?? list);
      setOpenId(null);
      setFocusAfter({ rowId: next?.id ?? null, seq: ++seq.current });
      say(`"${title}" moved to Trash`, {
        label: "Undo",
        run: () => void api.restoreSession(item.id).then(async () => {
          await load();
          setFocusAfter({ rowId: item.id, seq: ++seq.current });
        }).catch((e) => sayProblem(say, errorMessage(e))),
      });
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };
  /**
   * Brings recordings back from Trash one after another (the core has no bulk restore), then reloads once and says
   * how many came back and how many are still in Trash.
   */
  const restoreMany = async (ids: readonly string[]) => {
    let back = 0;
    let firstError: string | null = null;
    for (const id of ids) {
      try {
        await api.restoreSession(id);
        back++;
      } catch (e) {
        firstError ??= errorMessage(e);
      }
    }
    await load();
    const left = ids.length - back;
    if (left === 0) say(`${recordingsWord(back)} back`);
    else sayProblem(say, `${back} back · ${left} still in Trash${firstError ? `: ${firstError}` : ""}`);
  };
  /** Delete a whole view or more than this many: asked first. */
  const ASK_ABOVE = 20;
  const [askDelete, setAskDelete] = useState<string[] | null>(null);
  const deleteOrAsk = (ids: readonly string[]) => {
    const everything = ids.length > 1 && ids.length >= shownCount.current;
    if (ids.length > ASK_ABOVE || everything) setAskDelete([...ids]);
    else void removeMany(ids);
  };
  const shownCount = useRef(0);
  /** Several to Trash at once, with one Undo that brings them all back. */
  const removeMany = async (ids: readonly string[]) => {
    setAskDelete(null);
    if (ids.length === 1) {
      const one = items?.find((i) => i.id === ids[0]);
      if (one) await remove(one);
      endSelection();
      return;
    }
    try {
      const gone = await api.bulkSessions([...ids], "delete");
      endSelection();
      if (!gone) {
        sayProblem(say, "Nothing was moved to Trash. Try again, or reload the Library.");
        void load();
        return;
      }
      const kept = `kept there for ${hoursWords(keepHours)}`;
      if (gone !== ids.length) {
        // Only some went: the server's list says which. Undo brings back only those that are gone from it.
        // When the list can't be read, Undo tries every picked one (those still in the Library just stay).
        const text = `${gone} of ${ids.length} moved to Trash · ${kept}`;
        const now = await api.library().catch(() => null);
        // Some stayed: a problem, said as one (never pushed off by a newer message), with Undo for those that went.
        if (!now) {
          say(text, { label: "Undo", run: () => void restoreMany(ids) }, true);
          return;
        }
        const still = new Set(now.filter((i) => !i.deleted).map((i) => i.id));
        const moved = ids.filter((id) => !still.has(id));
        const order = listOf(items).rows;
        const next = order.find((i) => still.has(i.id) && !moved.includes(i.id) && !ids.includes(i.id))
          ?? order.find((i) => still.has(i.id)) ?? null;
        setItems(now);
        setFocusAfter({ rowId: next?.id ?? null, seq: ++seq.current });
        say(text, moved.length ? { label: "Undo", run: () => void restoreMany(moved) } : undefined, true);
        return;
      }
      const order = listOf(items).rows;
      const next = order.find((i) => !ids.includes(i.id)) ?? null;
      setItems((list) => list?.filter((i) => !ids.includes(i.id)) ?? list);
      setFocusAfter({ rowId: next?.id ?? null, seq: ++seq.current });
      say(`${recordingsWord(gone)} moved to Trash · ${kept}`, { label: "Undo", run: () => void restoreMany(ids) });
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };
  /**
   * Add to list: the chosen recordings go in the list or come out of it, with Undo. Undo changes only the recordings
   * this changed: one already in the list stays in it, one already out of it stays out.
   */
  const putInList = async (ids: readonly string[], list: ListItem, on: boolean) => {
    const inList = (id: string) => (items?.find((i) => i.id === id)?.labels ?? []).some((l) => listKey(l) === list.key);
    const changed = ids.filter((id) => inList(id) !== on);
    try {
      await api.bulkSessions([...ids], on ? "label" : "unlabel", null, list.name);
      say(on ? `Added to ${list.name}` : `Removed from ${list.name}`, changed.length ? {
        label: "Undo",
        run: () => void api.bulkSessions(changed, on ? "unlabel" : "label", null, list.name).then(() => Promise.all([load(), reloadLists()])).catch((e) => sayProblem(say, errorMessage(e))),
      } : undefined);
      await Promise.all([load(), reloadLists()]);
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };
  /**
   * Mark as unread (contract 2.0.0-t, DELETE /api/sessions/{id}/opened) or, when every one is unread, Mark as read: here
   * at once (the dot, Unread and its count), then on every device; what the server refuses is put back, and said.
   */
  const setUnread = async (ids: readonly string[], unread: boolean) => {
    const chosen = (items ?? []).filter((i) => ids.includes(i.id));
    if (!chosen.length) return;
    if (chosen.length > MAX_BULK) {
      say(`Up to ${MAX_BULK} at a time: unselect ${chosen.length - MAX_BULK}`);
      return;
    }
    const was = new Map(chosen.map((i) => [i.id, { opened: i.opened, read: readHere.has(i.id) }]));
    const put = (next: (id: string) => { opened: boolean | undefined; read: boolean }) => {
      setItems((list) => list?.map((i) => (was.has(i.id) ? { ...i, opened: next(i.id).opened } : i)) ?? list);
      setReadHere((r) => {
        const out = new Set(r);
        for (const id of was.keys()) if (next(id).read) out.add(id); else out.delete(id);
        return out;
      });
    };
    const marked = { opened: !unread, read: !unread };
    put(() => marked);
    // One at a time; what the server took is kept whatever happens after.
    const done = new Set<string>();
    try {
      for (const id of was.keys()) {
        await (unread ? api.markUnopened(id) : api.markOpened(id));
        done.add(id);
      }
      // Once more: a sync answered meanwhile from the copy saved before the marks undid them.
      put(() => marked);
      say(`Marked ${recordingsWord(was.size)} as ${unread ? "unread" : "read"}`);
    } catch (e) {
      // Only what was not done goes back; the rest stays marked, and the problem says how many went.
      put((id) => (done.has(id) ? marked : was.get(id)!));
      sayProblem(say, done.size ? `${done.size} of ${was.size} marked as ${unread ? "unread" : "read"} · ${errorMessage(e)}` : errorMessage(e));
    }
  };

  const bulk = async (ids: readonly string[], action: BulkAction, done: string) => {
    try {
      const changed = await api.bulkSessions([...ids], action);
      say(ids.length === 1 ? done : `${done} · ${recordingsWord(changed)}`);
      await load();
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };

  /**
   * The one menu for a recording or for the picked ones: Organise, Export, then Delete, last and red. `without` leaves
   * out what the selection bar already shows as buttons.
   */
  const menuFor = (ids: readonly string[], without: ReadonlySet<string> = new Set(), own: RecordingMenuParts = {}): MenuItem[] => {
    // More than the core takes at once: the menu says so and does nothing else.
    if (ids.length > MAX_BULK) return [{ label: `Up to ${MAX_BULK} at a time: unselect ${ids.length - MAX_BULK}`, disabled: true }];
    const chosen = (items ?? []).filter((i) => ids.includes(i.id));
    const one = chosen.length === 1 ? chosen[0] : null;
    const all = (test: (i: LibraryItem) => boolean) => chosen.length > 0 && chosen.every(test);
    const none = (test: (i: LibraryItem) => boolean) => !chosen.some(test);
    const list: (MenuItem & { key: string })[] = [];
    const add = (key: string, item: MenuItem) => { if (!without.has(key)) list.push({ key, ...item }); };
    if (one) add("open", { label: "Open", icon: Icons.TranscriptIcon, onPick: () => setOpenId(one.id) });
    // The same selection mode X and Select start, with this recording ticked.
    if (one && !selecting) add("select", { label: "Select", icon: Icons.SelectIcon, shortcut: "X", onPick: () => startSelecting(one.id) });
    if (one) add("rename", { label: "Rename…", icon: Icons.RenameIcon, group: "Organise", onPick: () => setOrganise({ kind: "rename", item: one }) });
    add("move", { label: "Move to project…", icon: Icons.MoveIcon, group: "Organise",
      onPick: () => setOrganise({ kind: "move", ids: [...ids], title: one?.title, current: one ? one.client?.id ?? null : undefined }) });
    // Lists (D14): tick the lists the recordings go in, or make a new one for them. Add to list takes Label…'s place;
    // a server without lists keeps Label….
    if (!lists) add("label", { label: "Label…", icon: Icons.LabelIcon, group: "Organise", onPick: () => setOrganise({ kind: "label", ids: [...ids] }) });
    if (lists) {
      add("lists", addToListMenu({ lists, chosen, onToggle: (list, on) => void putInList(ids, list, on), onNew: () => setNewLabel({ ids: [...ids] }) }));
    }
    if (!all((i) => !!i.pinned)) add("pin", { label: "Pin", icon: Icons.PinIcon, group: "Organise", onPick: () => void bulk(ids, "pin", "Pinned") });
    if (!none((i) => !!i.pinned)) add("unpin", { label: "Unpin", icon: Icons.PinIcon, group: "Organise", onPick: () => void bulk(ids, "unpin", "Unpinned") });
    if (!all((i) => !!i.keep)) add("keep", { label: "Keep forever", icon: Icons.KeepIcon, group: "Organise", onPick: () => void bulk(ids, "keep", "Kept forever") });
    if (!none((i) => !!i.keep)) add("unkeep", { label: "Allow auto clean-up", icon: Icons.KeepIcon, group: "Organise", onPick: () => void bulk(ids, "unkeep", "Auto clean-up allowed") });
    // Mark as important (UX-22): actions.json's item, the same as a right-click's (the selection bar's ⋮ and the
    // recording page's menu have it too).
    for (const item of menuItems(["recording.important"], recordingContext(ids))) add("important", { ...item, group: "Organise" });
    if (!one || isUntranscribed(one)) {
      add("transcribe", ids.length > MAX_TRANSCRIBE
        ? { label: `Transcribe (${MAX_TRANSCRIBE} at a time)`, icon: Icons.TranscriptIcon, group: "Organise", disabled: true }
        : { label: "Transcribe…", icon: Icons.TranscriptIcon, group: "Organise", onPick: () => setOrganise({ kind: "transcribe", ids: [...ids] }) });
    }
    for (const item of own.organise ?? []) add(item.label, { group: "Organise", ...item });
    add("export", own.export ?? exportMenu((format) => void exportRecordings(ids, format, say, shownExportView(chosen))));
    for (const item of own.export_more ?? []) add(item.label, { group: "Export", ...item });
    add("ai", { label: "Copy for AI", icon: Icons.SparkleIcon, group: "Export", onPick: own.ai ?? (() => void copyForAiToClipboard(chosen, say)) });
    if (one) add("whatsapp", { label: "Send to WhatsApp…", icon: Icons.SendIcon, group: "Export", onPick: own.whatsapp ?? (() => setOrganise({ kind: "whatsapp", item: one })) });
    add("delete", { label: ids.length === 1 ? "Delete" : `Delete ${ids.length}`, icon: Icons.TrashIcon, group: "Danger", danger: true, shortcut: "Del", onPick: () => deleteOrAsk(ids) });
    return list.map(({ key: _key, ...item }) => item);
  };

  /**
   * A row's actions on hover and keyboard focus (UX-06), in place of when and how long: Transcribe for Audio only, Pin or
   * Unpin, Label, then ⋯ (the whole menu, as a right-click).
   */
  const rowActions = (item: LibraryItem, r: RowModel): ReactNode => (
    <>
      {r.state?.key === "audio_only" && (
        <Button size="sm" variant="quiet" icon={Icons.TranscriptIcon} aria-label={`Transcribe ${r.title}`} title="Transcribe"
          onClick={() => setOrganise({ kind: "transcribe", ids: [item.id] })}>Transcribe</Button>
      )}
      <Button size="sm" variant="quiet" icon={Icons.PinIcon} aria-label={`${item.pinned ? "Unpin" : "Pin"} ${r.title}`} title={item.pinned ? "Unpin" : "Pin"}
        onClick={() => void bulk([item.id], item.pinned ? "unpin" : "pin", item.pinned ? "Unpinned" : "Pinned")} />
      {lists ? (
        <Menu label={`Label ${r.title}`} button={{ variant: "quiet", size: "sm", icon: Icons.LabelIcon, title: "Label" }}
          items={() => addToListMenu({ lists, chosen: [item], onToggle: (list, on) => void putInList([item.id], list, on), onNew: () => setNewLabel({ ids: [item.id] }) }).items ?? []} />
      ) : (
        <Button size="sm" variant="quiet" icon={Icons.LabelIcon} aria-label={`Label ${r.title}`} title="Label" onClick={() => setOrganise({ kind: "label", ids: [item.id] })} />
      )}
      {/* The same menu as a right-click (UX-22): the system's on a Mac, ours elsewhere. */}
      {currentPlatform() === "macos" ? (
        <Button size="sm" variant="quiet" icon={Icons.MoreIcon} aria-label={`More for ${r.title}`} aria-haspopup="menu"
          onClick={(e) => showMenu({ at: menuPointFor(e.currentTarget), ids: [item.id] })} />
      ) : (
        <Menu label={`More for ${r.title}`} items={() => recordingMenu([item.id]).items} button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />
      )}
    </>
  );

  const restore = async (b: BinItem) => {
    try {
      await api.restoreSession(b.id);
      setBin((list) => list?.filter((x) => x.id !== b.id) ?? list);
      await load();
      say(`"${b.title || "Recording"}" restored`, { label: "Open", run: () => { show(ALL_SCOPE, {}, true); setOpenId(b.id); } });
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };
  const eraseNow = async (b: BinItem) => {
    setErase(null);
    try {
      await api.eraseFromBin(b.id);
      setBin((list) => list?.filter((x) => x.id !== b.id) ?? list);
      say(`"${b.title || "Recording"}" deleted forever`);
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };

  const open = openId ? items?.find((i) => i.id === openId) ?? null : null;
  const shownIds = useMemo(() => shown.map((i) => i.id), [shown]);
  // A row that leaves the screen (a filter, the search, a folded section, another view) leaves the selection too.
  useEffect(() => {
    const visible = new Set(shownIds);
    setPicked((p) => ([...p].every((id) => visible.has(id)) ? p : new Set([...p].filter((id) => visible.has(id)))));
  }, [shownIds]);
  const pickedIds = shownIds.filter((id) => picked.has(id));
  const allPicked = shownIds.length > 0 && pickedIds.length === shownIds.length;
  shownCount.current = shownIds.length;
  // Picking turns the toolbar into the selection bar; it stays until Esc, Cancel, or nothing is picked.
  const choosing = selecting || pickedIds.length > 0;
  // Picking (the selection bar takes the tabs row's place), a tab or the filters change what is stuck without a scroll.
  useEffect(() => {
    remark.current();
  }, [choosing, tab, applied]);
  // More picked than the core takes at once: the actions wait and the bar says why.
  const tooMany = pickedIds.length > MAX_BULK;
  const toggle = (id: string) => {
    setSelecting(true);
    anchor.current = id;
    setPicked((now) => {
      const next = new Set(now);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };
  /** A range from the last ticked row to this one, added to what is picked. */
  const pickRange = (id: string) => {
    if (!anchor.current || !shownIds.includes(anchor.current)) return toggle(id);
    const [a, b] = [shownIds.indexOf(anchor.current), shownIds.indexOf(id)].sort((x, y) => x - y);
    setSelecting(true);
    setPicked((now) => new Set([...now, ...shownIds.slice(a, b + 1)]));
    anchor.current = id;
  };
  const startSelecting = (id: string) => {
    setSelecting(true);
    anchor.current = id;
    setPicked(new Set([id]));
  };
  const pickAll = () => {
    setSelecting(true);
    setPicked(new Set(shownIds));
  };
  /**
   * Dragging rows onto a project in the sidebar (classic's, lib/dragMove.ts): the row pressed, or every picked row when
   * it is one of them; the same move as Move to project….
   */
  const rowDrag = (item: LibraryItem, e: ReactPointerEvent<HTMLButtonElement>) => {
    if (trash) return;
    beginRecordingDrag(e, () => {
      const ids = choosing && picked.has(item.id) ? pickedIds : [item.id];
      const titles = (items ?? []).filter((i) => ids.includes(i.id)).map((i) => i.title);
      return { ids, label: dragLabel(titles) };
    }, (ids, projectId) => void dropMove(ids, projectId));
  };
  const dropMove = async (ids: string[], projectId: string | null) => {
    if (ids.length > MAX_BULK) return say(`Up to ${MAX_BULK} at a time: unselect ${ids.length - MAX_BULK}`);
    try {
      const changed = await api.bulkSessions(ids, "move", projectId);
      if (!projectId) {
        // No project (today's Inbox): out of every project.
        say(ids.length === 1 ? "Taken out of its project" : `${recordingsWord(changed)} taken out of their projects`);
      } else {
        const name = (await api.clients().catch(() => null))?.data.find((c) => c.id === projectId)?.name ?? "the project";
        say(ids.length === 1 ? `Moved to ${name}` : `${recordingsWord(changed)} moved to ${name}`);
      }
      endSelection();
      await load();
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };
  const rowClick = (item: LibraryItem, e: ReactMouseEvent<HTMLButtonElement>) => {
    // The press that ended a drag never opens or ticks the row.
    if (dragJustEnded()) return;
    // Another row pressed: the tint of the one you came back from goes (UX-25).
    if (lastOpened?.id !== item.id) setLastOpened(null);
    if (e.shiftKey && anchor.current && shownIds.includes(anchor.current)) pickRange(item.id);
    else if (choosing || e.shiftKey || e.metaKey || e.ctrlKey) toggle(item.id);
    else setOpenId(item.id);
  };
  const rowMenu = (item: LibraryItem, e: ReactMouseEvent<HTMLElement> | ReactKeyboardEvent<HTMLButtonElement>) => {
    const at = "clientX" in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget);
    showMenu({ at, ids: choosing && picked.has(item.id) ? pickedIds : [item.id] });
  };
  /** A row's checkbox: ticks it (Shift: the range from the last one ticked). */
  const pickRow = (item: LibraryItem, e: ReactMouseEvent<HTMLElement>) => (e.shiftKey ? pickRange(item.id) : toggle(item.id));

  /** A row's source logo or label: the list shows only that source, or that label, too (it adds to the section). */
  const quick = (section: Exclude<SectionKey, "date">, value: string) => {
    const now = spec[section];
    if (now?.values.includes(value) && now.op !== "is_not") return;
    const base = now?.op === "is_not" ? { ...spec, [section]: { op: "is" as const, values: [] } } : spec;
    setSpec(toggleValue(base, section, value));
  };
  const clearFilters = () => {
    setSpec({});
    setQuery("");
  };
  /**
   * What Save as view keeps (contract 2.0.0-p: filters, search, sort, group, density). Where the list started from a
   * label, Important or Audio only (Status is not transcribed), that is kept as its filter; Needs you and Unread have
   * none, so a view saved there keeps the filters and the search (the dialog says so).
   */
  const viewSpec = (): ViewSpec => {
    let filters = spec;
    const add = (section: "labels" | "marked" | "status", value: string) => {
      if (!filters[section]?.values.includes(value) || filters[section]?.op === "is_not") filters = toggleValue(filters[section]?.op === "is_not" ? clearSection(filters, section) : filters, section, value);
    };
    if (labelKey) add("labels", labelKey);
    if (baseView === "important") add("marked", "important");
    if (baseView === "audio_only") add("status", "not_transcribed");
    return { filters, search: query.trim() || null, sort: choice.sort, group: choice.group, density };
  };
  const unsaved = baseView === "needs" || baseView === "unread";
  const canSave = filtersOn > 0 || !!query.trim() || (baseView !== "all" && !unsaved) || !!labelKey;
  const changed = !!savedView && JSON.stringify([cleanSpec(spec), query.trim() || null, choice.sort, choice.group])
    !== JSON.stringify([cleanSpec(savedView.spec.filters), savedView.spec.search ?? null, savedView.spec.sort, savedView.spec.group]);

  // Keys for the list (PLAN_LIBRARY_FILTERS §5): F the filters; ⌘F / Ctrl+F (and /) the search; Esc ends a selection,
  // else closes the filters; J and K move between rows, Enter opens one, X ticks it; ⌘A / Ctrl+A picks every row shown;
  // Delete sends the picked (or the focused) ones to Trash with Undo.
  const keys = useRef<(e: KeyboardEvent) => void>(() => undefined);
  /** Inside the shell (its title row has ← → and the one search): set where usePageHistory and usePageTitle answer. */
  const inShellRef = useRef(false);
  const inTitleRowRef = useRef(false);
  keys.current = (e: KeyboardEvent) => {
    if (organise || context || savingView || newLabel) return;
    const mod = e.metaKey || e.ctrlKey;
    // ⌘F / Ctrl+F: this Library's search, before the window's (the palette); an open recording keeps it for Find.
    // In the shell's title row (inTitleRow) ⌘F is the row's: it searches this view and says so ("In: All recordings").
    if (!open && !trash && items && !inTitleRowRef.current && mod && !e.altKey && !e.shiftKey && e.key.toLowerCase() === "f" && !e.defaultPrevented) {
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      e.preventDefault();
      root.current?.querySelector<HTMLInputElement>('input[type="search"]')?.focus();
      return;
    }
    if (typing(e.target)) return;
    // ⌘[ and ⌘] (Ctrl on Windows): Back and Forward; with a recording open, ⌘[ goes back to the list. Never while typing
    // (the search, a field, the pill editor) or with a question open.
    // In the shell the title row answers them (usePageHistory), asking the Library first: nothing moves twice.
    if (!inShellRef.current && mod && !e.altKey && !e.shiftKey && (e.key === "[" || e.key === "]") && !e.defaultPrevented) {
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      e.preventDefault();
      back.current(e.key === "[" ? "back" : "forward");
      return;
    }
    // One-letter keys (F, /, J, K, X) answer only while focus is in the Library (WCAG 2.1.4, as the web): never while
    // it is in the sidebar, the player or anywhere else in the window.
    const inside = e.target instanceof Node && !!root.current?.contains(e.target);
    if (!mod && !e.altKey && ["f", "F", "/", "j", "k", "x"].includes(e.key) && !inside) return;
    // Esc closes the open recording and goes back to the list (its row gets focus again).
    if (open && e.key === "Escape" && !e.defaultPrevented) {
      e.preventDefault();
      setOpenId(null);
      setFocusAfter({ rowId: open.id, seq: ++seq.current });
      return;
    }
    // ⇧⌘U / Ctrl+Shift+U on the recording open now (Mark as unread, or read once it is unread).
    if (open && !e.defaultPrevented && keyMatches(e, shortcutOf("recording.unread", currentPlatform()))) {
      e.preventDefault();
      runAction("recording.unread", recordingContext([open.id]));
      return;
    }
    if (open || trash || !items) return;
    if (e.key === "/" && !mod) {
      e.preventDefault();
      // In the shell the search is the title row's, outside the Library: / opens it as ⌘F (Edit › Find…) does.
      if (inTitleRowRef.current && runAction("edit.find", { platform: currentPlatform() })) return;
      root.current?.querySelector<HTMLInputElement>('input[type="search"]')?.focus();
      return;
    }
    if ((e.key === "f" || e.key === "F") && !mod && !e.altKey) {
      e.preventDefault();
      setPanel(!panel);
      return;
    }
    const focused = (document.activeElement as HTMLElement | null)?.closest<HTMLElement>("[data-row-id]")?.dataset.rowId
      ?? (document.activeElement as HTMLElement | null)?.dataset?.rowId;
    // The menus' keys (actions.json, UX-22): Rename… F2, Transcribe ⌘T / Ctrl+T, Mark as important ⇧⌘L / Ctrl+Shift+L,
    // on the picked recordings or the focused one, through the registry (the menus show only the keys answered here).
    for (const id of KEYED_HERE) {
      if (!keyMatches(e, shortcutOf(id, currentPlatform()))) continue;
      const ids = choosing && pickedIds.length ? pickedIds : focused ? [focused] : [];
      if (!ids.length || e.defaultPrevented) return;
      e.preventDefault();
      runAction(id, recordingContext(ids));
      return;
    }
    if (mod && e.key.toLowerCase() === "a" && !e.shiftKey) {
      e.preventDefault();
      pickAll();
    } else if (e.key === "Delete" || (e.key === "Backspace" && mod)) {
      const ids = choosing && pickedIds.length ? pickedIds : focused ? [focused] : [];
      if (!ids.length) return;
      e.preventDefault();
      if (ids.length > MAX_BULK) {
        say(`Up to ${MAX_BULK} at a time: unselect ${ids.length - MAX_BULK}`);
        return;
      }
      deleteOrAsk(ids);
    } else if (e.key === "Escape" && choosing) {
      e.preventDefault();
      endSelection();
    } else if (e.key === "Escape" && panel && !e.defaultPrevented) {
      e.preventDefault();
      setPanel(false);
    } else if (((e.key === "j" || e.key === "k") || ((e.key === "ArrowDown" || e.key === "ArrowUp") && focused && !e.shiftKey)) && !mod && !e.altKey) {
      // J K, and ↓ ↑ on a row (FIX-06: the list by arrows too), go from row to row.
      const rows = [...(root.current?.querySelectorAll<HTMLElement>("[data-row-id]") ?? [])];
      if (!rows.length) return;
      e.preventDefault();
      const at = rows.findIndex((r) => r.dataset.rowId === focused);
      const down = e.key === "j" || e.key === "ArrowDown";
      const to = at < 0 ? 0 : Math.max(0, Math.min(rows.length - 1, at + (down ? 1 : -1)));
      rows[to].focus();
      rows[to].scrollIntoView?.({ block: "nearest" });
    } else if (e.key === "x" && focused && !mod) {
      e.preventDefault();
      toggle(focused);
    }
  };
  useEffect(() => {
    const on = (e: KeyboardEvent) => keys.current(e);
    document.addEventListener("keydown", on);
    return () => document.removeEventListener("keydown", on);
  }, []);
  // Back and Forward from a key or the mouse's side buttons: an open recording closes first (Back), else the places.
  const back = useRef<(way: "back" | "forward") => void>(() => undefined);
  back.current = (way) => {
    if (open) {
      if (way !== "back") return;
      setOpenId(null);
      setFocusAfter({ rowId: open.id, seq: ++seq.current });
      return;
    }
    walk(way);
  };
  useEffect(() => {
    // The mouse's back (button 3) and forward (4): never the web view's own page history. Heard first (the capture phase),
    // so the Library answers before the shell's title row, which walks the places, however often it is drawn again; a
    // press something else took is left alone.
    // In the shell its title row hears them (usePageHistory) and asks the Library first.
    const side = (e: MouseEvent) => !inShellRef.current && (e.button === 3 || e.button === 4);
    const down = (e: MouseEvent) => {
      if (side(e)) e.preventDefault();
    };
    const up = (e: MouseEvent) => {
      if (!side(e) || e.defaultPrevented) return;
      e.preventDefault();
      // Not behind a question or a dialog.
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      back.current(e.button === 3 ? "back" : "forward");
    };
    window.addEventListener("mousedown", down, true);
    window.addEventListener("mouseup", up, true);
    return () => {
      window.removeEventListener("mousedown", down, true);
      window.removeEventListener("mouseup", up, true);
    };
  }, []);
  useEffect(() => {
    if (openId) endSelection();
  }, [openId, endSelection]);

  const closeOrganise = () => {
    setOrganise(null);
    void load();
  };
  // UX-22 (PLAN_V2_1_POLISH 3b): a recording's right-click menu and its ⋯ are one menu from actions.json; each item
  // sends its id and the registry runs it (the handlers below). Only what fits: what doesn't apply, or can't run here,
  // is left out. Several have the title "3 recordings".
  const recordingContext = (ids: readonly string[]): ActionContext => {
    const chosen = (items ?? []).filter((i) => ids.includes(i.id));
    const every = (test: (i: LibraryItem) => boolean) => chosen.length > 0 && chosen.every(test);
    const one = chosen.length === 1 ? chosen[0] : null;
    return {
      platform: currentPlatform(), targets: [...ids], kind: "recording",
      state: {
        pinned: every((i) => !!i.pinned), important: every((i) => !!i.important), keptForever: every((i) => !!i.keep),
        unread: every((i) => i.opened === false && !readHere.has(i.id)), hasTranscript: !!one && !isUntranscribed(one),
      },
    };
  };
  /** A choice of one (a project, a sort order): a radio item. */
  const oneOf = (item: MenuItem): MenuItem => ({ ...item, radio: true });
  const recordingMenu = (ids: readonly string[]): { title?: string; items: MenuItem[] } => {
    // More than the core takes at once: the menu says so and does nothing else.
    if (ids.length > MAX_BULK) return { title: manyTitle(ids.length), items: [{ label: `Up to ${MAX_BULK} at a time: unselect ${ids.length - MAX_BULK}`, disabled: true }] };
    const ctx = recordingContext(ids);
    const chosen = (items ?? []).filter((i) => ids.includes(i.id));
    const one = chosen.length === 1 ? chosen[0] : null;
    // One recording: Transcribe only while it is Audio only (Ask about this once it has a transcript).
    const list = one ? RECORDING_ONE.filter((id) => id !== "recording.transcribe" || rowState(one)?.key === "audio_only") : RECORDING_MANY;
    const inList = (key: string) => chosen.length > 0 && chosen.every((i) => (i.labels ?? []).some((l) => listKey(l) === key));
    const where = one ? one.client?.id ?? "none" : null;
    return {
      title: manyTitle(ids.length),
      items: contextItems(list, ctx, {
        "recording.labels": [
          ...(lists ? choiceItems("recording.labels", lists.map((l) => ({ value: l.key, label: l.name, checked: inList(l.key) })), ctx) : []),
          ...contextItems(["-", "label.new"], ctx).map((item, at) => (at === 0 && lists?.length ? { ...item, separator: true } : item)),
        ],
        "recording.moveToProject": choiceItems("recording.moveToProject", [
          { value: "none", label: appWords("No project", ctx.platform), checked: where === "none" },
          ...[...(projects ?? [])].sort((a, b) => a.name.localeCompare(b.name)).map((p) => ({ value: p.id, label: p.name, checked: where === p.id })),
        ], ctx).map(oneOf),
        "recording.exportAs": choiceItems("recording.exportAs", ZIP_FORMATS.map((f) => ({ value: f.format, label: appWords(f.label, ctx.platform) })), ctx),
      }, LIBRARY_KEYS),
    };
  };
  /** A recording in Trash: Restore | Delete immediately…; Trash itself (its empty space): Empty trash…. */
  const trashMenu = (ids: readonly string[]): MenuItem[] =>
    contextItems(ids.length ? TRASH_ITEM : TRASH, { platform: currentPlatform(), targets: [...ids], kind: "trash" });
  const listSpaceMenu = (): MenuItem[] => {
    const ctx: ActionContext = { platform: currentPlatform(), kind: "list", state: { layoutList: layout === "list", layoutTable: layout === "table" } };
    return contextItems(LIST_SPACE, ctx, {
      "view.sortBy": choiceItems("view.sortBy", SORT_ORDER.map((k: SortKey) => ({ value: k, label: appWords(SORT_NAMES[k], ctx.platform), checked: choice.sort === k })), ctx).map(oneOf),
      "view.groupBy": choiceItems("view.groupBy", GROUP_ORDER.map((k) => ({ value: k, label: appWords(GROUP_NAMES[k], ctx.platform), checked: choice.group === k })), ctx).map(oneOf),
      "view.density": choiceItems("view.density", DENSITIES.map(([d, label]) => ({ value: d, label: appWords(label, ctx.platform), checked: density === d })), ctx).map(oneOf),
    }, LIBRARY_KEYS);
  };
  /** Shows a menu at a point: the system's on a Mac (ours if it can't), ours elsewhere. */
  const showMenu = (next: { at: { x: number; y: number }; ids: string[]; space?: true; trash?: true }) => {
    if (currentPlatform() !== "macos") {
      setContext(next);
      return;
    }
    const menu = next.trash ? { title: undefined, items: trashMenu(next.ids) } : next.space ? { title: undefined, items: listSpaceMenu() } : recordingMenu(next.ids);
    void popupNative(menu.items, next.at, menu.title).then((shown) => { if (!shown) setContext(next); });
  };
  /** The list's empty space (below or beside the rows, in the box that scrolls; not a row, a field or a menu): its own menu. */
  const spaceMenu = useRef<(e: MouseEvent) => void>(() => undefined);
  spaceMenu.current = (e: MouseEvent) => {
    const target = e.target instanceof HTMLElement ? e.target : null;
    if (!target || e.defaultPrevented || open || (!trash && (layout === "table" || !items))) return;
    if (target.closest("li, a, button, input, textarea, select, header, .el-lib-row2, .el-banner, .el-empty, [role='status'], [role='menu'], [role='dialog'], [role='toolbar'], [role='tablist'], [data-testid='filter-panel'], .el-filter-panel")) return;
    // Only the list's area, under row 2 (or Trash's list): not the title, the count, a banner or an empty state.
    const el = root.current;
    const list = el?.querySelector<HTMLElement>(trash ? '[aria-label="Deleted recordings"]' : ".el-lib-list");
    if (!list) return;
    const from = trash ? list.getBoundingClientRect().top : el?.querySelector(".el-lib-row2")?.getBoundingClientRect().bottom ?? list.getBoundingClientRect().top;
    if (e.clientY < from) return;
    // In Trash its own: Empty trash… (only while it holds something).
    if (trash && !bin?.length) return;
    e.preventDefault();
    showMenu({ at: { x: e.clientX, y: e.clientY }, ids: [], ...(trash ? { trash: true as const } : { space: true as const }) });
  };
  useEffect(() => {
    const box = scrollRootOf(root.current);
    if (!box) return;
    const on = (e: MouseEvent) => spaceMenu.current(e);
    box.addEventListener("contextmenu", on);
    return () => box.removeEventListener("contextmenu", on);
  }, []);
  /** Empty trash…: asks, then erases every recording in Trash (their audio and transcripts), and says how many. */
  const emptyTrash = async () => {
    const all = bin ?? [];
    if (!all.length) return;
    const ok = await confirmAction({ title: "Empty the trash?", body: `${recordingsWord(all.length)} and their transcripts are erased from the server. This can't be undone.`, confirm: "Empty trash", tone: "danger" });
    if (!ok) return;
    let erased = 0;
    for (const b of all) {
      try {
        await api.eraseFromBin(b.id);
        erased++;
      } catch {
        // Counted below: what is left stays in Trash.
      }
    }
    setBin((list) => list?.filter((x) => !all.some((b) => b.id === x.id)) ?? list);
    await loadBin();
    say(erased === all.length ? `Trash emptied · ${recordingsWord(erased)}` : `Erased ${erased} of ${all.length}; the rest stay in Trash`);
  };
  /** Move to project › a project (or No project), as Move to project… does. */
  const moveTo = async (ids: readonly string[], projectId: string | null) => {
    const title = ids.length === 1 ? (items ?? []).find((i) => i.id === ids[0])?.title ?? null : null;
    try {
      await moveRecordings(ids, projectId);
      say(projectId ? movedText(ids.length, title, (projects ?? []) as unknown as ClientFolder[], projectId)
        : ids.length === 1 ? `Took “${title || "the recording"}” out of its project` : `Took ${ids.length} recordings out of their projects`);
      await load();
    } catch (e) {
      sayProblem(say, errorMessage(e));
    }
  };
  // What the Library's menus do (the registry runs these; one code path for every menu that sends these ids).
  const runs: Record<string, ((ctx: ActionContext) => void) | undefined> = {
    "recording.open": (c) => { if (c.targets?.[0]) setOpenId(c.targets[0]); },
    "recording.transcribe": (c) => setOrganise({ kind: "transcribe", ids: [...(c.targets ?? [])] }),
    "recording.pin": (c) => void bulk(c.targets ?? [], c.state?.pinned ? "unpin" : "pin", c.state?.pinned ? "Unpinned" : "Pinned"),
    "recording.important": (c) => void bulk(c.targets ?? [], c.state?.important ? "unimportant" : "important", c.state?.important ? "Not important" : "Marked as important"),
    // Mark as unread, or Mark as read once every one is unread (the menu's word says which); with none named, the
    // recording open now.
    "recording.unread": (c) => {
      const ids = c.targets?.length ? c.targets : openIdRef.current ? [openIdRef.current] : [];
      void setUnread(ids, !(c.state?.unread ?? recordingContext(ids).state?.unread));
    },
    "recording.labels": (c) => {
      const list = lists?.find((l) => l.key === c.value);
      const ids = c.targets ?? [];
      const all = ids.length > 0 && ids.every((id) => ((items ?? []).find((i) => i.id === id)?.labels ?? []).some((l) => listKey(l) === c.value));
      if (list) void putInList(ids, list, !all);
    },
    "label.new": (c) => (lists ? setNewLabel({ ids: [...(c.targets ?? [])] }) : setOrganise({ kind: "label", ids: [...(c.targets ?? [])] })),
    "recording.moveToProject": (c) => void moveTo(c.targets ?? [], c.value === "none" ? null : c.value ?? null),
    "file.rename": (c) => {
      const one = (items ?? []).find((i) => i.id === c.targets?.[0]);
      if (one && c.targets?.length === 1) setOrganise({ kind: "rename", item: one });
    },
    "recording.exportAs": (c) => {
      const chosen = (items ?? []).filter((i) => c.targets?.includes(i.id));
      if (c.value) void exportRecordings(c.targets ?? [], c.value as BulkExportFormat, say, shownExportView(chosen));
    },
    "recording.moveToTrash": (c) => deleteOrAsk(c.targets ?? []),
    "file.newRecording": onRecord ? () => onRecord() : undefined,
    // A voice note from the microphone (3b's New voice note), where this computer can record.
    "file.newVoiceNote": onRecord ? () => void api.toggleMicNote().catch((e) => sayProblem(say, errorMessage(e))) : undefined,
    "file.importAudio": onImport ? () => onImport() : undefined,
    "view.sortBy": (c) => { if (c.value) setChoice({ ...choice, sort: c.value as SortKey }); },
    "view.groupBy": (c) => { if (c.value) setChoice({ ...choice, group: c.value as LibraryViewChoice["group"] }); },
    "view.asList": () => setLayout("list"),
    "view.asTable": () => setLayout("table"),
    "view.density": (c) => { if (c.value) setDensity(c.value as Density); },
    "edit.selectAll": () => pickAll(),
    "trash.restore": (c) => { for (const b of (bin ?? []).filter((x) => c.targets?.includes(x.id))) void restore(b); },
    "recording.deleteNow": (c) => { const b = (bin ?? []).find((x) => x.id === c.targets?.[0]); if (b) setErase(b); },
    "trash.empty": () => void emptyTrash(),
  };
  // A built-in view's menu (the sidebar's, UX-22): Unread's Mark all as read, Audio only's Transcribe all… (it asks with
  // the count and the minutes first, as Transcribe does).
  const placeRuns: Record<string, ((ctx: ActionContext) => void) | undefined> = {
    "view.markAllRead": () => {
      const unread = (items ?? []).filter((i) => !i.deleted && i.opened === false && !readHere.has(i.id)).map((i) => i.id);
      if (!unread.length) return;
      setReadHere((r) => new Set([...r, ...unread]));
      for (const id of unread) void api.markOpened(id).catch(() => undefined);
      say(`Marked ${recordingsWord(unread.length)} as read`);
    },
    "view.transcribeAll": () => {
      const audioOnly = (items ?? []).filter((i) => !i.deleted && rowState(i)?.key === "audio_only").map((i) => i.id);
      if (audioOnly.length) setOrganise({ kind: "transcribe", ids: audioOnly.slice(0, MAX_TRANSCRIBE), of: audioOnly.length });
    },
  };
  const placeRunsRef = useRef(placeRuns);
  placeRunsRef.current = placeRuns;
  useEffect(() => {
    const offs = Object.keys(placeRunsRef.current).map((id) => registerAction(id, (ctx) => placeRunsRef.current[id]?.(ctx), "place"));
    return () => offs.forEach((off) => off());
  }, []);
  const runsRef = useRef(runs);
  runsRef.current = runs;
  const runnable = Object.keys(runs).filter((id) => runs[id]).join(" ");
  useEffect(() => {
    // Each for its kind of thing: the list's space, else a recording (the sidebar's Rename… of a view is its own).
    const kindOf = (id: string) => ((LIST_SPACE as readonly string[]).includes(id) ? "list" : ([...TRASH, ...TRASH_ITEM] as readonly string[]).includes(id) ? "trash" : "recording");
    const offs = runnable.split(" ").filter(Boolean).map((id) => registerAction(id, (ctx) => runsRef.current[id]?.(ctx), kindOf(id)));
    return () => offs.forEach((off) => off());
  }, [runnable]);
  // FIX-05: the Mac menu bar's Recording menu greys and runs for the selection (else the open recording), as a
  // recording's; its View items (As List, As Table…) for the list's space.
  const menuTargets = pickedIds.length ? pickedIds : openId ? [openId] : [];
  useMenuPart({
    targets: menuTargets, kind: ["recording", "list"],
    state: { ...recordingContext(menuTargets).state, layoutList: layout === "list", layoutTable: layout === "table" },
  });

  const dialogs = (
    <>
      {context?.trash && <ContextMenu label={context.ids.length ? "Deleted recording actions" : "Trash actions"} items={trashMenu(context.ids)} at={context.at} onClose={() => setContext(null)} />}
      {context && !context.trash && (context.space
        ? <ContextMenu label="List actions" items={listSpaceMenu()} at={context.at} onClose={() => setContext(null)} />
        : <ContextMenu label={context.ids.length === 1 ? "Recording actions" : `Actions for ${context.ids.length} recordings`} title={manyTitle(context.ids.length)}
          items={recordingMenu(context.ids).items} at={context.at} onClose={() => setContext(null)} />)}
      {organise?.kind === "move" && <MoveDialog ids={organise.ids} title={organise.title} current={organise.current} onClose={closeOrganise} say={say} />}
      {organise?.kind === "label" && <LabelDialog ids={organise.ids} known={knownLabels(items ?? [])} onClose={closeOrganise} say={say} />}
      {organise?.kind === "transcribe" && <TranscribeDialog ids={organise.ids} of={organise.of} onClose={closeOrganise} say={say} />}
      {organise?.kind === "whatsapp" && <WhatsAppDialog item={organise.item} onClose={() => setOrganise(null)} say={say} />}
      {newLabel && <NewLabelFor ids={newLabel.ids} onClose={() => setNewLabel(null)} onMade={(list, ids) => void putInList(ids, list, true)} />}
      {savingView && (
        <ViewNameDialog title="Save as view" save="Save view" onClose={() => setSavingView(false)}
          note={unsaved ? `Saves these filters, the search and the sort as a view in the sidebar, on every device. ${scopeName(scope)} itself stays in the sidebar.`
            : "Saves these filters, the search and the sort as a view in the sidebar, on every device."}
          onSave={async (name) => {
            const made = await viewsStore.create(name, viewSpec());
            setSavingView(false);
            show({ kind: "saved", id: made.id }, {}, true);
            say(`Saved as ${made.name}`);
          }} />
      )}
      <Dialog open={!!askDelete} alert title={`Move ${recordingsWord(askDelete?.length ?? 0)} to Trash?`}
        description={`They stay in Trash for ${hoursWords(keepHours)}, then their audio and transcripts are erased. Undo brings them back.`}
        onClose={() => setAskDelete(null)}
        actions={<><Button onClick={() => setAskDelete(null)}>Cancel</Button><Button variant="danger" onClick={() => askDelete && void removeMany(askDelete)}>Move to Trash</Button></>} />
      {organise?.kind === "rename" && <RenameDialog item={organise.item} onClose={closeOrganise} say={say}
        onRenamed={(r) => setItems((list) => list?.map((i) => (i.id === r.id ? { ...i, ...r } : i)) ?? list)} />}
    </>
  );
  const toasts = (
    <ToastRegion>
      {message && (
        <Toast key={message.id} message={message.text} actionLabel={message.action?.label} onAction={message.action?.run} error={message.error}
          onClose={() => setMessage((m) => (m?.id === message.id ? null : m))} />
      )}
    </ToastRegion>
  );

  const labelName = (key: string) => lists?.find((l) => l.key === key)?.name ?? prepared.names.labels.get(key) ?? key;
  const labelColour = (item: LibraryItem, label: string) => lists?.find((l) => l.key === listKey(label))?.color ?? item.label_colors?.[label] ?? null;
  const title = scopeName(scope, { saved: (id) => myViews.find((v) => isView(v, id))?.name, label: labelName });
  // Needs you lists the calls waiting and the uploads that failed here too (the queue above the recordings).
  const needsHere = baseView === "needs" && !filtersOn && !query.trim() && queue.loaded
    ? queue.pending.length + queue.failed.length + queue.held.reduce((n, [, list]) => n + list.length, 0) : 0;
  const count = trash ? bin?.length ?? null : items ? matching + needsHere : null;
  // The window's title row (UX-20) shows the list's place, its count and the search; a recording opened as its own
  // page shows none of them. Without a title row the page keeps its own header (inTitleRow false).
  const inTitleRow = usePageTitle(open && (layout !== "table" || trash) ? null
    : { title, count, search: trash ? undefined : { placeholder: "Search recordings", scope: title, query, setQuery } });
  /** A toolbar's keys (the selection bar, as the shared SelectionBar): ← → between its controls, Home and End to the ends. */
  const toolbarKeys = (e: ReactKeyboardEvent<HTMLElement>) => {
    const rtl = getComputedStyle(e.currentTarget).direction === "rtl";
    const next = rtl ? "ArrowLeft" : "ArrowRight";
    const prev = rtl ? "ArrowRight" : "ArrowLeft";
    if (![next, prev, "Home", "End"].includes(e.key) || e.altKey || e.metaKey || e.ctrlKey) return;
    const controls = [...e.currentTarget.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), [role="checkbox"]')]
      .filter((c) => !c.closest('[role="menu"]'));
    const at = controls.indexOf(document.activeElement as HTMLElement);
    if (at < 0) return;
    e.preventDefault();
    const to = e.key === "Home" ? 0 : e.key === "End" ? controls.length - 1 : e.key === next ? Math.min(at + 1, controls.length - 1) : Math.max(at - 1, 0);
    controls[to].focus();
  };
  const closeOpen = () => {
    const id = open?.id ?? null;
    setOpenId(null);
    setFocusAfter({ rowId: id, seq: ++seq.current });
  };
  // The title row's ← → (⌘[ ⌘], Alt+← →, the mouse's side buttons) ask the Library first (UX-20): an open recording
  // goes back to the list where it was (UX-25's kept place is this step); else the Library's own steps (a view, a
  // filter, a search); then the places visited. Outside the shell the Library hears those keys itself.
  const inShell = usePageHistory({
    back: open ? closeOpen : stepCounts.back ? () => void walk("back") : null,
    forward: !open && stepCounts.forward ? () => void walk("forward") : null,
  });
  inShellRef.current = inShell;
  inTitleRowRef.current = inTitleRow;
  const recordingPage = open && (
    <RecordingPage key={open.id} item={open} startAt={openAt?.id === open.id ? openAt : null} onBack={closeOpen} onMessage={say} onOpenProject={onOpenProject}
      onItemChanged={(r) => setItems((list) => list?.map((i) => (i.id === r.id ? { ...i, ...r } : i)) ?? list)}
      menu={(own) => menuFor([open.id], new Set(["open", "select"]), own)} onViews={(v) => rowViews.note(open, v)} />
  );
  // In the table a recording opens in a drawer on the end side, over the table, as classic's: Close, Esc or a press on
  // the scrim closes it. The list opens it as its own page.
  if (open && (layout !== "table" || trash)) {
    return (
      <div className="el-root el-embedded" data-v2="library" ref={root}>
        {recordingPage}
        {dialogs}
        {toasts}
      </div>
    );
  }
  const drawerPage = open && (
    <>
      <div className="el-drawer-scrim" aria-hidden="true" onMouseDown={closeOpen} />
      <Drawer label={recordingName(open.title)} onClose={closeOpen}>{recordingPage}</Drawer>
    </>
  );

  // Sort & display ▾: List or Table (and the table's Columns), Sort, Group by, Density.
  const displayMenu: MenuItem[] = [
    { label: "List", group: "Show as", checked: layout === "list", radio: true, onPick: () => setLayout("list") },
    { label: "Table", group: "Show as", checked: layout === "table", radio: true, onPick: () => setLayout("table") },
    ...(layout === "table" ? [{ label: "Columns", group: "Show as", icon: Icons.ColumnsIcon, items: columnsMenu(columns, setColumns) }] : []),
    ...SORT_ORDER.map((k: SortKey) => ({ label: SORT_NAMES[k], group: "Sort", checked: choice.sort === k, radio: true, onPick: () => setChoice({ ...choice, sort: k }) })),
    ...GROUP_ORDER.map((k) => ({ label: GROUP_NAMES[k], group: "Group by", checked: choice.group === k, radio: true, onPick: () => setChoice({ ...choice, group: k }) })),
    ...DENSITIES.map(([d, label]) => ({ label, group: "Density", checked: density === d, radio: true, onPick: () => setDensity(d) })),
  ];
  const viewEmpty = scope.kind === "view" ? EMPTY_VIEW[scope.view] : undefined;
  const panelShown = panel && !trash && !choosing;

  return (
    <div className="el-root el-embedded" data-v2="library" ref={root}>
      <div className={`el-lib${panelShown && !drawer ? " el-lib--panel" : ""}`} style={{ ["--el-lib-room" as string]: `${height}px` }}>
        {panelShown && (
          <FilterPanel spec={spec} onSpec={(next) => setSpec(next, "soon")} counts={counts()} drawer={drawer} onClose={() => setPanel(false)}
            onSaveView={() => setSavingView(true)} canSave={canSave} />
        )}
        <div className="el-page el-page--wide el-lib__main el-back-top-room">
          {/* UX-05/UX-20: the window's title row shows the place, its count and the one search (usePageTitle). Without
              it (no title row in this shell) the page keeps a slim header of its own with the same three. */}
          {!inTitleRow && (
            <header className="el-lib-toolbar">
              <PanelToggle />
              <h1 className="el-title-m el-lib-toolbar__title">
                <span dir="auto">{title}</span>
                {count !== null && <span className="el-lib-toolbar__count el-secondary el-num" aria-label={`, ${recordingsWord(count)}`}>{count}</span>}
              </h1>
              {!trash && (
                <SearchField label="Search recordings" value={query} onChange={setQuery} disabled={!items} aria-keyshortcuts="Meta+F Control+F /"
                  placeholder={placeholderFor(width, panelShown && !drawer)} className="el-lib-toolbar__search" />
              )}
            </header>
          )}
          {choosing ? (
            // Selection mode: the tabs row turns into the selection bar, its box lined up with the rows' (UX-05).
            <div className="el-lib-row2 el-selectbar" role="toolbar" aria-label="Selected recordings" onKeyDown={toolbarKeys}>
              <PickBox className="el-lib-toolbar__all" label={allPicked ? "Unselect all" : "Select all"} keyshortcuts="Meta+A Control+A"
                checked={allPicked} mixed={pickedIds.length > 0} onClick={() => (allPicked ? setPicked(new Set()) : pickAll())} />
              <h2 className="el-body-strong el-lib-row2__selected" aria-live="polite" dir="auto">{pickedIds.length ? `${pickedIds.length} selected` : "Select recordings"}</h2>
              {tooMany && <span className="el-caption el-secondary" role="status">Up to {MAX_BULK} at a time: unselect {pickedIds.length - MAX_BULK}</span>}
              <div className="el-lib-row2__end">
                <Button size="sm" variant="quiet" icon={Icons.TranscriptIcon} disabled={!pickedIds.length || tooMany || pickedIds.length > MAX_TRANSCRIBE}
                  onClick={() => setOrganise({ kind: "transcribe", ids: pickedIds })}>
                  {pickedIds.length > MAX_TRANSCRIBE && !tooMany ? `Transcribe (${MAX_TRANSCRIBE} at a time)` : "Transcribe"}
                </Button>
                <Button size="sm" variant="quiet" icon={Icons.MoveIcon} disabled={!pickedIds.length || tooMany}
                  onClick={() => setOrganise({ kind: "move", ids: pickedIds })}>Move to project</Button>
                <Button size="sm" variant="quiet" icon={Icons.LabelIcon} disabled={!pickedIds.length || tooMany}
                  onClick={() => setOrganise({ kind: "label", ids: pickedIds })}>Label</Button>
                {pickedIds.length > 0 && !tooMany && (
                  <Menu label="Share or export" button={{ variant: "quiet", size: "sm", icon: Icons.ShareIcon, text: "Export", "aria-label": "Share or export", title: "Share or export" }}
                    items={[...exportMenu((format) => void exportRecordings(pickedIds, format, say, shownExportView((items ?? []).filter((i) => pickedIds.includes(i.id))))).items!.map((i) => ({ ...i, group: "Export transcripts" })),
                      { label: "Copy for AI", icon: Icons.SparkleIcon, group: "Copy", onPick: () => void copyForAiToClipboard((items ?? []).filter((i) => pickedIds.includes(i.id)), say) }]} />
                )}
                <Button size="sm" variant="quiet" icon={Icons.TrashIcon} title="Delete (Del)" aria-keyshortcuts="Delete" disabled={!pickedIds.length || tooMany}
                  onClick={() => deleteOrAsk(pickedIds)}>Delete</Button>
                {pickedIds.length > 0 && !tooMany && (
                  <Menu label="More actions" button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }}
                    items={menuFor(pickedIds, new Set(["open", "select", "move", "label", "transcribe", "export", "ai", "delete"]))} />
                )}
                <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Cancel selection" aria-keyshortcuts="Escape" onClick={endSelection} />
              </div>
            </div>
          ) : !trash && (
            // Row 2 (40 px): the kinds on the start side (Other only while it has recordings), the list's controls on the end.
            <div ref={row2} className="el-lib-row2">
              <div ref={row2Tabs} className={`el-lib-tabs${row2Fit.scrolls ? " el-lib-tabs--scrolls" : ""}`} role="tablist" aria-label="Kinds of recording">
                {tabsShown.map((t) => (
                  <button key={t} type="button" role="tab" className="el-lib-tabs__tab" aria-selected={tab === t} tabIndex={tab === t ? 0 : -1}
                    onClick={() => setTab(t)} onKeyDown={(e) => {
                      const at = tabsShown.indexOf(t);
                      const rtl = getComputedStyle(e.currentTarget).direction === "rtl";
                      const step = e.key === (rtl ? "ArrowLeft" : "ArrowRight") ? 1 : e.key === (rtl ? "ArrowRight" : "ArrowLeft") ? -1 : 0;
                      // Home and End: the first and the last tab (Back to top leaves keys in a tab list alone).
                      const to = e.key === "Home" ? 0 : e.key === "End" ? tabsShown.length - 1 : step ? (at + step + tabsShown.length) % tabsShown.length : -1;
                      if (to < 0) return;
                      e.preventDefault();
                      const next = tabsShown[to];
                      setTab(next);
                      (e.currentTarget.parentElement?.querySelectorAll<HTMLElement>('[role="tab"]')[tabsShown.indexOf(next)])?.focus();
                    }}>
                    {LIBRARY_TAB_NAMES[t]}<span className="el-lib-tabs__count el-num" aria-label={`, ${tabCount[t]}`}>{tabCount[t]}</span>
                  </button>
                ))}
              </div>
              <div ref={row2End} className="el-lib-row2__end">
                <Button size="sm" variant={filtersOn ? "secondary" : "quiet"} icon={Icons.FilterIcon} aria-expanded={panelShown} aria-keyshortcuts="F"
                  aria-label={row2Fit.labels ? undefined : filtersOn ? `Filters, ${filtersOn}` : "Filters"}
                  title="Filters (F)" className={filtersOn ? "el-lib-toolbar__filters el-lib-toolbar__filters--on" : "el-lib-toolbar__filters"} onClick={() => setPanel(!panel)}>
                  {row2Fit.labels && "Filters"}{filtersOn > 0 && <span className="el-lib-toolbar__n"><span className="el-num">{filtersOn}</span></span>}
                </Button>
                <Menu label="Sort & display" items={displayMenu}
                  button={{ variant: "quiet", size: "sm", icon: Icons.SortIcon, iconEnd: Icons.ChevronDownIcon, text: row2Fit.labels ? "Sort & display" : undefined,
                    title: `Sort & display: ${SORT_NAMES[choice.sort]} · Group: ${GROUP_NAMES[choice.group]}` }} />
              </div>
            </div>
          )}
          {!trash && !choosing && (filtersOn > 0 || changed) && (
            <FilterPills spec={spec} onSpec={(next) => setSpec(next)} counts={counts} names={prepared.names} onClearAll={clearFilters}
              onSaveView={() => setSavingView(true)} onSaveChanges={changed && savedView ? () => {
                void viewsStore.update(savedView.id, viewSpec()).then(() => say(`Saved ${savedView.name}`)).catch((e) => sayProblem(say, errorMessage(e)));
              } : undefined} />
          )}
          {top && scope.kind === "view" && scope.view === "all" && !filtersOn && !query && !choosing && <LibraryTop {...top} items={items} />}
          {!trash && syncError && items && (
            <Banner tone="danger" icon={Icons.ErrorIcon} testId="library-sync"
              action={<Button size="sm" loading={syncing} loadingLabel="Syncing…" onClick={() => void sync()}>Retry</Button>}>
              Couldn't sync with the server. Showing what is saved on this computer.
            </Banner>
          )}
          {!trash && !choosing && (
            <QueueV2 queue={queue} reviewRequest={reviewRequest} openWaiting={baseView === "needs"} parts={baseView === "needs" ? "needs" : "progress"} />
          )}
          {!trash && found.length > 0 && (
            <section className="el-group el-hits" aria-label="Matches in transcripts">
              <h2 className="el-caption el-body-strong el-group__title">In transcripts</h2>
              <Card>
                <ul className="el-group__rows">
                  {found.map((r) => {
                    const name = recordingName(r.session.title);
                    return (
                      <li key={r.session.id} className="el-hit">
                        <div className="el-hit__head">
                          <span className="el-body-strong" dir="auto">{name}</span>
                          <span className="el-caption el-secondary">{rowDate(r.session.started_at)}{r.hit_count > r.hits.length ? ` · ${r.hit_count} matches` : ""}</span>
                        </div>
                        {r.hits.map((h) => (
                          <button key={`${h.transcript_id}-${h.segment_index}`} type="button" className="el-hit__line" onClick={() => openHit(r.session.id, h.start_ms)}
                            aria-label={`Play ${name} from ${timestamp(h.start_ms)}`}>
                            <span className="el-num el-caption el-secondary">{timestamp(h.start_ms)}</span>
                            <span dir="auto"><b>{h.speaker}:</b> {snippetParts(h.snippet, h.highlights).map((p, k) => (p.hit ? <mark key={k}>{p.text}</mark> : <span key={k}>{p.text}</span>))}</span>
                          </button>
                        ))}
                      </li>
                    );
                  })}
                </ul>
              </Card>
            </section>
          )}
          {trash ? (
            binError ? (
              <EmptyState tone="error" icon={Icons.ErrorIcon} title="Trash didn't load" body={binError} action={<Button variant="primary" onClick={() => void loadBin()}>Try again</Button>} />
            ) : !bin ? (
              <Loading label="Loading Trash">{Array.from({ length: 4 }, (_, i) => <SkeletonRow key={i} />)}</Loading>
            ) : bin.length === 0 ? (
              <EmptyState icon={Icons.TrashIcon} title="Trash is empty" body={binSentence(binHours(bin, keepHours))} />
            ) : (
              <>
                <p className="el-secondary">{binSentence(binHours(bin, keepHours))}</p>
                <List label="Deleted recordings">
                  {bin.map((b) => (
                    <ListRow key={b.id} icon={Icons.TrashIcon} title={b.title || "Recording"}
                      meta={`Deleted ${rowDate(b.deleted_at)} · ${eraseIn(b.erase_at)}`}
                      onContextMenu={(e) => {
                        e.preventDefault();
                        const at = "clientX" in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : menuPointFor(e.currentTarget);
                        showMenu({ at, ids: [b.id], trash: true });
                      }}
                      control={
                        <span className="el-row__buttons">
                          <Button size="sm" onClick={() => void restore(b)}>Restore</Button>
                          <Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label={`Delete "${b.title || "Recording"}" forever`} onClick={() => setErase(b)} />
                        </span>
                      }
                      endTop={b.duration_ms ? <span className="el-num">{formatDuration(b.duration_ms)}</span> : undefined} />
                  ))}
                </List>
              </>
            )
          ) : error && !items ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title="The library didn't load" body={error}
              action={<Button variant="primary" onClick={() => void load()}>Try again</Button>} />
          ) : !items ? (
            <Loading label="Loading recordings">{Array.from({ length: 7 }, (_, i) => <SkeletonRow key={i} />)}</Loading>
          ) : prepared.facts.length === 0 && needsHere === 0 && scope.kind === "view" && scope.view === "all" ? (
            <EmptyState icon={Icons.LibraryIcon} title="No recordings yet"
              body="Calls and meetings you record on this computer or your phone, and files you import, appear here."
              action={onImport ? <Button onClick={onImport}>Import</Button> : undefined} />
          ) : matching === 0 && needsHere > 0 ? null : matching === 0 && filtersOn > 0 ? (
            <EmptyState icon={Icons.FilterIcon} title="No recordings match these filters"
              body={query ? "Take a filter off, or clear the search." : "Take a filter off, or clear them all."}
              action={<Button onClick={clearFilters}>Clear filters</Button>} />
          ) : matching === 0 && query.trim() ? (
            <EmptyState icon={Icons.SearchIcon} title="No recordings match" body="Try another word, or clear the search to see every recording."
              action={<Button onClick={() => setQuery("")}>Clear search</Button>} />
          ) : matching === 0 && labelKey ? (
            <EmptyState icon={Icons.LabelIcon} title={`Nothing in ${labelName(labelKey)} yet`} body="Add recordings from their ⋮ menu: Labels."
              action={<Button onClick={() => show(ALL_SCOPE, {}, true)}>Show all</Button>} />
          ) : matching === 0 ? (
            <EmptyState icon={Icons.LibraryIcon} title={viewEmpty?.title ?? `Nothing in ${title} yet`} body={viewEmpty?.body ?? "Recordings appear here when they fit this view."}
              action={<Button onClick={() => show(ALL_SCOPE, {}, true)}>Show all</Button>} />
          ) : layout === "table" ? (
            <LibraryTable viewsOf={rowViews.summaryFor} groups={groups} columns={columns} collapsed={collapsed} onFold={fold} selecting={choosing} picked={picked}
              onRow={rowClick} onRowMenu={rowMenu} onRowPointerDown={rowDrag} recovered={recovered} onPick={pickRow}
              onPickAll={() => (allPicked ? setPicked(new Set()) : pickAll())}
              rowActions={(item) => <Menu label={`More for ${recordingName(item.title)}`} items={() => menuFor([item.id])} button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />} />
          ) : (
            <List label="Recordings" className="el-lib-list">
              {groups.map((g) => [
                g.label ? (
                  <ListSection key={g.key} title={g.label} count={g.items.length} collapsed={collapsed.has(g.key)}
                    onToggle={() => fold(g.key)}
                    // A folded section's rows can't be picked: its box shows only while it is open.
                    pick={choosing && !collapsed.has(g.key) ? {
                      state: groupPick(g.items, picked), label: groupPick(g.items, picked) === "all" ? `Clear ${g.label}` : `Select all in ${g.label}`,
                      onToggle: () => setPicked((p) => toggleGroup(g.items, p)),
                    } : undefined} />
                ) : null,
                ...(collapsed.has(g.key) ? [] : g.items).map((item) => {
                  // In a date group (Today, Yesterday, This week) a row says only the time: its heading says the day.
                  const r = rowModel(item, undefined, rowViews.summaryFor(item), TIME_ONLY_GROUPS.has(g.key));
                  return (
                    <RecordingRow key={item.id} item={item} r={r} compact={density === "compact"} choosing={choosing} picked={picked.has(item.id)}
                      labels={(item.labels ?? []).map((l) => ({ name: l, key: listKey(l), colour: labelColour(item, l) }))}
                      onLabel={(key) => quick("labels", key)}
                      recovered={recovered.has(item.id) ? <Badge tone="accent">{RECOVERED_CHIP}</Badge> : null}
                      onClick={(e) => rowClick(item, e)} onPointerDown={(e) => rowDrag(item, e)} onContextMenu={(e) => rowMenu(item, e)}
                      onPick={(e) => pickRow(item, e)} last={lastOpened?.id === item.id} unread={item.opened === false && !readHere.has(item.id)}
                      actions={choosing ? undefined : rowActions(item, r)} />
                  );
                }),
              ])}
            </List>
          )}
          {/* UX-25: after one screen down, ↑ at the list's bottom end corner (Home, ⌘↑ and Ctrl+Home too). */}
          {items && <BackToTop />}
        </div>
      </div>
      <Dialog open={!!erase} alert title="Delete forever?"
        description={erase ? `"${erase.title || "Recording"}" and its transcript are erased from the server. This can't be undone.` : undefined}
        onClose={() => setErase(null)}
        actions={<><Button onClick={() => setErase(null)}>Cancel</Button><Button variant="danger" onClick={() => erase && void eraseNow(erase)}>Delete forever</Button></>} />
      {drawerPage}
      {dialogs}
      {toasts}
    </div>
  );
}

/**
 * A recording opened from the table: a modal panel on the end side (role="dialog"); focus moves in, Tab stays inside,
 * Esc or Close closes it, and focus goes back to the row.
 */
function Drawer({ label, onClose, children }: { label: string; onClose: () => void; children: ReactNode }) {
  const panel = useRef<HTMLDivElement>(null);
  const close = useRef<HTMLButtonElement>(null);
  const trap = useFocusTrap(panel, close);
  return (
    <div ref={panel} className="el-drawer" role="dialog" aria-modal="true" aria-label={label} tabIndex={-1}
      onKeyDown={(e) => {
        if (e.key === "Escape" && !e.defaultPrevented) {
          e.preventDefault();
          e.stopPropagation();
          onClose();
          return;
        }
        trap(e);
      }}>
      <Button ref={close} variant="quiet" icon={Icons.CloseIcon} className="el-drawer__close" aria-label="Close" aria-keyshortcuts="Escape" onClick={onClose} />
      {children}
    </div>
  );
}
