import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type ClientFolder, type ExportFormat, type LearnOffer, type MeetingPackFormat, type ScreenMoment, type LibraryItem, type SearchResult, type SharedItem, type SmartFolder, type TranscriptDetail, type TranscriptionLanguage, type TranscriptSuggestion, type TranscriptView, type TranscriptViewsState } from "../../lib/ipc";
import { folderLabel, inFolderHere, LIBRARY_VIEWS, type LibraryView as LibraryViewKey } from "../../lib/folders";
import { PageHeader } from "../../components/Page";
import { Menu } from "../../components/Menu";
import { DENSITIES, listKey, loadPreferences, PREFERENCES_EVENT, savePreferences, stepIndex, type Density, type Preferences } from "../../lib/shell";
import { LibraryTable, loadColumns, saveColumns, TABLE_COLUMNS, type TableColumn } from "./LibraryTable";
import { beginRecordingDrag, dragJustEnded, dragLabel } from "../../lib/dragMove";
import { SharedWithMe, sentLine } from "./SharedWithMe";
import { MoveRecordingsDialog, moveRecordings, movedText } from "./ProjectDialogs";
import { playerLength, activeIndex, activeWord, continuesTurn, findMatches, hasUncertainWords, KEY_SECONDS, markParts, newTerms, playerKey, skipTarget, snippetParts, speakerLabels, speakerName, suggestionSpan, uncertainWord } from "../../lib/player";
import { ExportMenu, FindBar, HistoryPanel, LearnOfferBar, PlayerControls, PlayerDock, ReplaceBar, SpeakersPanel, SuggestionPop, VocabularyHint, Waveform } from "./TranscriptTools";
import { ShareDialog } from "./ShareDialog";
import { copiedTranscript, DEFAULT_UNSURE, formatDuration, formatWhen, providerName, timestamp, type UnsureMarkers } from "../../lib/format";
import { bookmarksOf, filterByTag, itemState, LIVE_STATUS_MS, libraryTags, needsLiveStatus, searchItems, SORT_LABELS, sortItems, type Bookmark, type SortKey } from "../../lib/library";
import { GROUP_LABELS, groupBy, loadChoice, loadOpened, rememberOpened, saveChoice, toggleGroup, type GroupKey, type LibraryViewChoice } from "../../lib/libraryGroups";
import { GroupHeader, ViewMenu } from "./LibraryGrouping";
import { SourceAppBadge, SourceDeviceBadge } from "../../components/SourceBadge";
import { AlertIcon, AppTileIcon, appKind, CloseIcon, ColumnsIcon, CopyIcon, DeviceBadgeIcon, DownloadIcon, ImportIcon, LibraryIcon, LinkIcon, ListIcon, MonitorIcon, PinIcon, RefreshIcon, SearchIcon, SparkleIcon, TableIcon, TrashIcon } from "../../components/Icons";
import { NO_AUDIO_HINT } from "../../lib/pending";
import { BookmarkIcon } from "../../components/ControlIcons";
import { LibraryQueue, type PendingFocus } from "./LibraryQueue";
import { filterByClient, filterByLabel, folderBadge, foldersOf, inSmartView, libraryLabels, smartViewCounts } from "../../lib/organise";
import { BinPanel, BulkBar, OrganisePanel, RowMenu, TitleEditor } from "./LibraryOrganise";
import { useWindowShown } from "../../lib/visibility";
import { MotionGroup, openFromRow, snapshotRow, type RowSnapshot } from "../../lib/motion";
import { activeFilterCount, LibraryFilters, NO_FILTERS, type FilterState, type Source } from "./LibraryFilters";
import { MAIN_LANGUAGES, MORE_LANGUAGES, engineLine, englishOnlyRisk, languageLabel, menuLabel, normalizeLanguage, partsText, retryLine, retryReason, scriptWarning, usedBackupEngine } from "../../lib/languages";
import { CallNotesSection } from "./CallNotes";
import { LineScreens, ScreenGallery, ScreenViewer, useSessionScreens } from "./ScreenMoments";
import { screenMarker, screensByLine } from "../../lib/screens";
import { improvingView, stepOf, transcribingLine, viewPercent } from "../../lib/progress";
import { LockedChip, NEEDS_KEY_TEXT } from "./Upgrade";
import { openSettingsSection } from "../../lib/entitlements";
import { ImprovingBar, RecordingSteps } from "./RecordingProgress";

type SourceFilter = "all" | "phone" | "desktop" | "web";

export function filterItems(items: LibraryItem[], source: SourceFilter): LibraryItem[] {
  return items.filter((i) => !i.deleted && (source === "all" || i.source_device === source));
}

/** The filter menu: ticked boxes of one group add up, the groups narrow each other, nothing ticked filters nothing. */
export function applyFilters(items: LibraryItem[], f: FilterState): LibraryItem[] {
  return items.filter((i) => !i.deleted
    && (f.sources.size === 0 || f.sources.has(i.source_device as Source))
    && (f.tags.size === 0 || [...f.tags].some((tag) => filterByTag([i], tag).length > 0))
    && (f.views.size === 0 || [...f.views].some((v) => inSmartView(i, v))));
}

/** Status only when it needs the person (DESIGN_SYSTEM.md): a ready recording shows its second line instead. */
const STATE_LABEL = { ready: "Ready", processing: "Transcribing", failed: "Transcription failed", no_audio: "No audio" } as const;

/** Transcript states in which the provider is still working: never shown as an empty transcript (A6.DU3). */
const IN_FLIGHT = new Set(["pending", "submitted", "processing"]);
const isTranscribing = (status?: string | null): boolean => !!status && IN_FLIGHT.has(status);
/** What a read of a transcript was for: the transcript, the recording as the server last changed it, the re-reads. */
const readKey = (id: string, changed: string | null, recheck: number): string => `${id}|${changed ?? ""}|${recheck}`;

const PLAYBACK_EXPIRED = "Playback link expired. Open the recording again.";
const playbackUrlOf = (detail: Record<string, unknown>): string | null => (typeof detail.playback_url === "string" ? detail.playback_url : null);

/** How often the Library asks whether the Show as views are written yet. */
const VIEWS_POLL_MS = 5_000;
/** Show as: the views in the order the switch shows them (the original words last), and their names. */
const VIEW_ORDER: TranscriptView[] = ["roman", "urdu", "english"];
const VIEW_LABELS: Record<TranscriptView, string> = { roman: "Roman Urdu", english: "English", urdu: "اردو" };

/** A view's line with its "[?word]" marks shown like the original's unsure words: underlined, without brackets (plan A4). */
function withUnsureMarks(text: string): ReactNode {
  const parts = text.split(/(\[\?[^\]]+\])/);
  if (parts.length === 1) return text;
  return parts.map((part, i) => {
    const marked = /^\[\?([^\]]+)\]$/.exec(part);
    return marked ? <span key={i} className="word uncertain" title={UNSURE_WORD}>{marked[1]}</span> : part;
  });
}

/** Tooltips of what the engine was not sure of (kept as heard). */
const UNSURE_WORD = "Not sure of this word. Click to hear it.";
const UNSURE_LINE = "The engine was not sure of this line. Click to hear it.";

/** List or Table, kept on this computer. */
const LAYOUT_KEY = "echoledger-library-layout";

/** The subtitle's words for Waiting, Pinned and Failed uploads (v1.6). */
const VIEW_LINE: Record<LibraryViewKey, string> = {
  waiting: "waiting for Keep or Discard.",
  pinned: "pinned.",
  failed: "that did not upload or transcribe. Retry or delete each.",
};

/** The row's time: the clock for today, the day before that (the day group names the rest). */
export function rowTime(iso?: string): string {
  const when = formatWhen(iso);
  return when.startsWith("Today · ") || when.startsWith("Yesterday · ") ? when.slice(when.indexOf(" · ") + 3) : when.split(" · ")[0];
}

/** The speaker's initial for the avatar of a speaker block. */
const initialOf = (name: string): string => [...name.trim()][0]?.toUpperCase() ?? "?";

/**
 * Unified library: every recording from every device, searchable by title and transcript text, as a list and the open
 * recording beside it. Recordings waiting for a decision and uploads that need the user sit at the top of the list;
 * `focus` brings one of them into view.
 */
export function LibraryView({ notify, focus = null, onFocusHandled, openRequest = null, initialSelected = null, onSelectedChange, onImport, onOpenClient, smartFolder = null, onSmartFolder, sharedFocus = null, projects = null, smartView = null, crumb = null, aside = null }: {
  notify: (t: string, e?: boolean) => void;
  focus?: PendingFocus | null;
  onFocusHandled?: () => void;
  /** Open a recording at a moment (from the command palette, a client's notes or an Ask source). */
  openRequest?: { id: string; ms: number; seq: number } | null;
  /** The recording that was open when the last main window was closed (C9). */
  initialSelected?: string | null;
  onSelectedChange?: (id: string | null) => void;
  /** The Import button: picks files and opens the import sheet. */
  onImport?: () => void;
  /** Opens a client's workspace. */
  onOpenClient?: (id: string) => void;
  /** A smart folder the Library shows (since v1.5; the sidebar picks it): null shows every recording. */
  smartFolder?: SmartFolder | null;
  onSmartFolder?: (folder: SmartFolder | null) => void;
  /** An item of Shared with me to open (an echoledger link to it). */
  sharedFocus?: string | null;
  /** Every project, as the sidebar has them (since v1.5); without them the Library asks for its own. */
  projects?: ClientFolder[] | null;
  /** Waiting, Pinned or Failed uploads (v1.6, P4.2): a view of the whole Library. */
  smartView?: LibraryViewKey | null;
  /** The place shown, after "Library" in the breadcrumbs. */
  crumb?: string | null;
  /** The nested column (v1.6, P4.2): the Library's places with their counts. */
  aside?: ReactNode;
}) {
  // The Move dialog of one recording (its row menu).
  const [moveFor, setMoveFor] = useState<{ ids: string[]; title: string | null; current: string | null } | null>(null);
  const [items, setItems] = useState<LibraryItem[]>([]);
  // The smart folder's recordings as the server lists them; null until it answered (or when it cannot be reached).
  const [folderItems, setFolderItems] = useState<LibraryItem[] | null>(null);
  const [folderLoading, setFolderLoading] = useState(false);
  // A recording a teammate sent, open read-only beside Shared with me.
  const [sharedOpen, setSharedOpen] = useState<SharedItem | null>(null);
  const folderRead = useRef(0);
  const [filters, setFilters] = useState<FilterState>(NO_FILTERS);
  const [label, setLabel] = useState<string | null>(null);
  const [folder, setFolder] = useState<string | null>(null);
  const [clients, setClients] = useState<ClientFolder[]>([]);
  const [clientsLoaded, setClientsLoaded] = useState(false);
  const [selecting, setSelecting] = useState(false);
  const [binOpen, setBinOpen] = useState(false);
  const [picked, setPicked] = useState<Set<string>>(new Set());
  const [bookmarks, setBookmarks] = useState<Bookmark[]>([]);
  const [query, setQuery] = useState("");
  // Group by and Sort by (Phase 1C), remembered per view: the whole Library and each smart folder.
  const viewKey = smartFolder ?? "all";
  const [choice, setChoiceState] = useState<LibraryViewChoice>(() => loadChoice(viewKey));
  useEffect(() => setChoiceState(loadChoice(viewKey)), [viewKey]);
  const setChoice = (next: Partial<LibraryViewChoice>) => setChoiceState((c) => {
    const merged = { ...c, ...next };
    saveChoice(viewKey, merged);
    return merged;
  });
  const sort = choice.sort;
  const [collapsedGroups, setCollapsedGroups] = useState<Set<string>>(new Set());
  const [opened, setOpened] = useState<Record<string, number>>(loadOpened);
  const [selected, setSelected] = useState<string | null>(initialSelected);
  // The page pattern (v1.6, P4.4): List or Table, the table's columns, the row the keys are on, and the density
  // (Account → Preferences, here too as on the phone's View sheet).
  const searchRef = useRef<HTMLInputElement | null>(null);
  const [layout, setLayout] = useState<"list" | "table">(() => {
    try {
      return window.localStorage.getItem(LAYOUT_KEY) === "table" ? "table" : "list";
    } catch {
      return "list";
    }
  });
  const chooseLayout = (next: "list" | "table") => {
    setLayout(next);
    try {
      window.localStorage.setItem(LAYOUT_KEY, next);
    } catch {
      // Storage can be unavailable; the choice then lasts until the app restarts.
    }
  };
  const [columns, setColumns] = useState<Set<TableColumn>>(loadColumns);
  const toggleColumn = (id: TableColumn) => setColumns((c) => {
    const next = new Set(c);
    if (!next.delete(id)) next.add(id);
    saveColumns(next);
    return next;
  });
  const [cursor, setCursor] = useState<string | null>(null);
  const [density, setDensity] = useState<Density>(() => loadPreferences().density);
  useEffect(() => {
    const follow = (e: Event) => setDensity((e as CustomEvent<Preferences>).detail?.density ?? loadPreferences().density);
    window.addEventListener(PREFERENCES_EVENT, follow);
    return () => window.removeEventListener(PREFERENCES_EVENT, follow);
  }, []);
  const chooseDensity = (next: Density) => {
    const prefs = { ...loadPreferences(), density: next };
    savePreferences(prefs);
    setDensity(next);
    window.dispatchEvent(new CustomEvent(PREFERENCES_EVENT, { detail: prefs }));
  };
  useEffect(() => onSelectedChange?.(selected), [selected, onSelectedChange]);
  useEffect(() => {
    if (selected) setOpened(rememberOpened(selected));
  }, [selected]);
  const [transcript, setTranscript] = useState<TranscriptDetail | null>(null);
  // How unsure words are marked in copied text (the console's choice, plan A4).
  const [unsure, setUnsure] = useState<UnsureMarkers>(DEFAULT_UNSURE);
  useEffect(() => {
    api.unsureMarkers().then((m) => { if (m) setUnsure({ ...DEFAULT_UNSURE, ...m }); }).catch(() => undefined);
  }, []);
  const [playbackUrl, setPlaybackUrl] = useState<string | null>(null);
  // What the audio file says its length is (E1): the player's length when the server has none yet.
  const [mediaSeconds, setMediaSeconds] = useState<number | null>(null);
  useEffect(() => setMediaSeconds(null), [playbackUrl]);
  const [activeSegment, setActiveSegment] = useState(-1);
  const [activeWordIndex, setActiveWordIndex] = useState(-1);
  const [nowMs, setNowMs] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [speakerNames, setSpeakerNames] = useState<Record<string, string>>({});
  const [rate, setRate] = useState(1);
  const [skipSilence, setSkipSilence] = useState(false);
  const [follow, setFollow] = useState(true);
  const [editing, setEditing] = useState(false);
  const [panel, setPanel] = useState<"replace" | "speakers" | "history" | "share" | null>(null);
  const [vocabTerms, setVocabTerms] = useState<string[]>([]);
  // "Always write X as Y for Acme?" offers from the last edit (since v1.4), asked one at a time.
  const [learn, setLearn] = useState<LearnOffer[]>([]);
  // The suggested fix opened from its mark, and whether a decision is on its way.
  const [openSuggestion, setOpenSuggestion] = useState<number | null>(null);
  const [suggestBusy, setSuggestBusy] = useState(false);
  // Find in the transcript on screen.
  const [findOpen, setFindOpen] = useState(false);
  const [findQuery, setFindQuery] = useState("");
  const [findIndex, setFindIndex] = useState(0);
  const [found, setFound] = useState<SearchResult["data"]>([]);
  const segmentsRef = useRef<HTMLDivElement | null>(null);
  /** A moment to play once the recording opened from a search hit has loaded. */
  const pendingSeek = useRef<number | null>(null);
  const [playbackError, setPlaybackError] = useState<string | null>(null);
  const audioRef = useRef<HTMLAudioElement | null>(null);
  /** Bumped on every selection change; an answer that belongs to an older selection is dropped (A6.DU2). */
  const selection = useRef(0);
  const playbackRefreshed = useRef(false);
  const [loading, setLoading] = useState(false);
  // C6: the first local read (skeleton until then), the last sync error (Retry) and a transcript that did not load.
  const [loaded, setLoaded] = useState(false);
  const [syncError, setSyncError] = useState<string | null>(null);
  const [transcriptError, setTranscriptError] = useState(false);
  const [transcriptAttempt, setTranscriptAttempt] = useState(0);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      setItems(await api.refreshLibrary());
      setSyncError(null);
    } catch (e) {
      notify(errorMessage(e), true);
      setSyncError(errorMessage(e));
      setItems(await api.library().catch(() => []));
    } finally {
      setLoading(false);
    }
  }, [notify]);

  useEffect(() => {
    api.library().then(setItems).catch(() => undefined).finally(() => setLoaded(true));
    refresh();
    const un = listen("library:updated", () => api.library().then(setItems).catch(() => undefined));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // A smart folder is read from the server when it opens and again shortly after the library changed; the saved
  // recordings stand in until it answers, or while it cannot be reached. Shared with me reads its own list.
  useEffect(() => {
    setFolderItems(null);
    setSharedOpen(null);
    const token = ++folderRead.current;
    if (!smartFolder || smartFolder === "shared") return;
    const read = () => {
      setFolderLoading(true);
      api.libraryFolder(smartFolder)
        .then((list) => { if (token === folderRead.current) setFolderItems(list); }, () => undefined)
        .finally(() => { if (token === folderRead.current) setFolderLoading(false); });
    };
    read();
    let timer = 0;
    const un = listen("library:updated", () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(read, 400);
    });
    return () => {
      window.clearTimeout(timer);
      un.then((f) => f()).catch(() => undefined);
    };
  }, [smartFolder]);

  // Live status: while a recording uploads or is transcribed, the list and the open recording sync every few seconds
  // on their own (quietly: a failed try just waits for the next one) and stop once nothing is moving. Only while the
  // window is on screen (C9).
  const shown = useWindowShown();
  const live = useMemo(() => needsLiveStatus(items), [items]);
  useEffect(() => {
    if (!live || !shown) return;
    let alive = true;
    let timer = 0;
    const next = () => {
      timer = window.setTimeout(() => {
        api.refreshLibrary()
          .then((fresh) => { if (alive) setItems(fresh); })
          .catch(() => undefined)
          .finally(() => { if (alive) next(); });
      }, LIVE_STATUS_MS);
    };
    next();
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [live, shown]);

  const tags = useMemo(() => libraryTags(items), [items]);
  const anyBookmarks = useMemo(() => items.some((i) => !i.deleted && (i.marker_count ?? 0) > 0), [items]);
  const labels = useMemo(() => libraryLabels(items), [items]);
  const viewCounts = useMemo(() => smartViewCounts(items), [items]);
  const pickedIds = useMemo(() => [...picked].filter((id) => items.some((i) => i.id === id && !i.deleted)), [picked, items]);
  const togglePicked = (id: string) => setPicked((p) => { const next = new Set(p); if (next.has(id)) next.delete(id); else next.add(id); return next; });
  const endSelecting = () => { setSelecting(false); setPicked(new Set()); };
  const folders = useMemo(() => foldersOf(items, projects ? [...projects, ...clients.filter((c) => !projects.some((p) => p.id === c.id))] : clients), [items, clients, projects]);
  const addClient = (client: ClientFolder) => setClients((list) => [...list.filter((c) => c.id !== client.id), client]);
  /** Recordings dropped on a project or the Inbox in the sidebar (since v1.5); the library then syncs on its own. */
  const dropMove = async (ids: string[], projectId: string | null) => {
    try {
      await moveRecordings(ids, projectId);
      notify(movedText(ids.length, items.find((i) => i.id === ids[0])?.title, folders, projectId));
      if (ids.length > 1) endSelecting();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  /** A row pressed and moved: the recording, or every selected one when it is selected, follows the pointer. */
  const startDrag = (e: React.PointerEvent, item: LibraryItem) => {
    const ids = selecting && picked.has(item.id) ? pickedIds : [item.id];
    beginRecordingDrag(e, () => ({ ids, label: dragLabel(ids.map((id) => items.find((i) => i.id === id)?.title)) }), (moved, projectId) => void dropMove(moved, projectId));
  };
  // A smart folder's recordings: the server's list (each shown as saved here when it is), else what this computer can tell.
  const shared = smartFolder === "shared";
  const folderBase = useMemo(() => {
    if (smartView) return items.filter((i) => inSmartView(i, smartView));
    if (!smartFolder || smartFolder === "shared") return items;
    if (folderItems === null) return items.filter((i) => inFolderHere(i, smartFolder) === true);
    const saved = new Map(items.map((i) => [i.id, i]));
    return folderItems.map((i) => saved.get(i.id) ?? i).filter((i) => !i.deleted);
  }, [items, smartFolder, folderItems, smartView]);
  // Recently opened reads the times kept when the list was shown, so opening a recording does not move it under the pointer.
  const openedAtSort = useMemo(() => opened, [sort]); // eslint-disable-line react-hooks/exhaustive-deps
  const filtered = useMemo(() => sortItems(searchItems(filterByClient(filterByLabel(applyFilters(folderBase, filters), label), folder), query), sort, openedAtSort), [folderBase, filters, label, folder, query, sort, openedAtSort]);
  const groups = useMemo(() => groupBy(filtered, choice.group, sort), [filtered, choice.group, sort]);
  // Shared with me keeps its own list; everything else may be a table.
  const tableView = layout === "table" && smartFolder !== "shared";
  // The rows the keys move through: the shown ones, in order, not those of a folded group.
  const rows = useMemo(() => groups.flatMap((g) => (collapsedGroups.has(g.key) ? [] : g.items)), [groups, collapsedGroups]);
  const toggleCollapsed = (key: string) => setCollapsedGroups((c) => {
    const next = new Set(c);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    return next;
  });
  // A recording opened from a transcript match stays open even when its title does not match the typed words.
  const [hitSelected, setHitSelected] = useState<string | null>(null);
  const sharedSession = sharedOpen?.session && sharedOpen.session.id === selected ? sharedOpen.session : null;
  // In Shared with me only a recording a teammate sent is open beside the list.
  const current = shared ? sharedSession
    : filtered.find((i) => i.id === selected) ?? (hitSelected !== null && hitSelected === selected ? items.find((i) => i.id === selected && !i.deleted) : undefined) ?? null;
  // A recording a teammate sent can be played and read, not changed.
  const readOnly = !!current && current === sharedSession;
  // The recording page opening from its row (since v1.5): the row clicked last, until the page shows it.
  const detailRef = useRef<HTMLDivElement>(null);
  const opening = useRef<RowSnapshot | null>(null);
  const pageMotion = useMemo(() => new MotionGroup(), []);
  useLayoutEffect(() => {
    const from = opening.current;
    if (!from || !current || current.id !== from.id || !detailRef.current) return;
    opening.current = null;
    openFromRow(pageMotion, detailRef.current, from);
  }, [current, pageMotion]);
  useEffect(() => () => pageMotion.stop(), [pageMotion]);
  // Screen moments (since v1.5): read when the recording has any; each sits after the line of its moment.
  const { screens, reload: reloadScreens } = useSessionScreens(current && !readOnly ? current.id : null, current?.screen_count);
  const [screenOpen, setScreenOpen] = useState<number | null>(null);
  const [galleryOpen, setGalleryOpen] = useState(false);
  // The server has meeting packs: its config says so, or the recording carries `screen_count` (a recording synced
  // before the server was updated keeps its old shape, so its item alone cannot say).
  const [packServer, setPackServer] = useState(false);
  useEffect(() => {
    setScreenOpen(null);
    setGalleryOpen(false);
    if (current?.id) api.screensStatus().then((s) => setPackServer(s.onServer)).catch(() => undefined);
  }, [current?.id]);
  // Transcript matches of a smart folder's own recordings only.
  const shownHits = useMemo(() => (smartFolder ? found.filter((r) => folderBase.some((i) => i.id === r.session.id)) : found), [smartFolder, found, folderBase]);
  // The full client list (folders without recordings too) is fetched once, when a recording is opened or selecting starts.
  const needClients = (!!current || selecting || moveFor !== null) && !projects;
  useEffect(() => {
    if (!needClients || clientsLoaded) return;
    setClientsLoaded(true);
    api.clients().then((r) => setClients(r.data ?? [])).catch(() => undefined); // offline: the folders of synced recordings still show
  }, [needClients, clientsLoaded]);

  const finalStatus = current?.transcript?.final_status ?? null;
  // Which version is shown: the transcript (newest) or the other one (the previous transcript after Re-transcribe as).
  const [version, setVersion] = useState<"final" | "alternate">("final");
  const alternateId = current?.transcript?.alternate_status === "completed" ? current?.transcript?.alternate_transcript_id ?? null : null;
  const finalId = version === "alternate" && alternateId ? alternateId : current?.transcript?.final_transcript_id ?? null;
  const liveId = current?.transcript?.live_transcript_id ?? null;
  const [retranscribeLanguage, setRetranscribeLanguage] = useState<string>("");
  // "English only" on a recording with Urdu in it is said once (the engine would translate the Urdu).
  const [englishWarned, setEnglishWarned] = useState(false);
  useEffect(() => {
    setVersion("final");
    setRetranscribeLanguage(current?.language ?? "");
    setEnglishWarned(false);
  }, [current?.id]);
  const englishRisk = retranscribeLanguage === "en" && englishOnlyRisk(current?.transcript?.final_language, current?.transcript?.preview);
  const retranscribe = async () => {
    if (!current) return;
    if (englishRisk && !englishWarned) {
      setEnglishWarned(true);
      return;
    }
    try {
      await api.retranscribe(current.id, (retranscribeLanguage || null) as TranscriptionLanguage | null);
      notify(`Re-transcribing in ${languageLabel(retranscribeLanguage || null)}. The current transcript stays until the new one is ready.`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  /** "Backup engine — Re-transcribe?" (v1.5): the same language again, so the preferred engine gets another try. */
  const retranscribeSame = async () => {
    if (!current) return;
    const language = (current.language ?? null) as TranscriptionLanguage | null;
    try {
      await api.retranscribe(current.id, language);
      notify(`Re-transcribing in ${languageLabel(language)}. The current transcript stays until the new one is ready.`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  // The final transcript while it is still being made (v1.5): an Urdu or Auto one that waits for its preferred engine
  // says when it tries again. When it is the transcript shown (no live captions) its detail says so and a fresh read
  // replaces it; behind the live captions it is read on its own. Either way it is read again whenever the recording
  // changes on the server (every new retry time touches it), shortly after the retry time, and every minute once that
  // time has passed.
  const transcribingFinalId = isTranscribing(finalStatus) ? current?.transcript?.final_transcript_id ?? null : null;
  const finalShown = transcribingFinalId !== null && ((isTranscribing(finalStatus) && liveId) || finalId || liveId) === transcribingFinalId;
  const sessionChanged = current?.updated_at ?? null;
  const [finalRead, setFinalRead] = useState<{ id: string; detail: TranscriptDetail } | null>(null);
  // The reads after a retry time, counted per transcript, and what the transcript on screen was last read for.
  const [rechecks, setRechecks] = useState<{ id: string | null; n: number }>({ id: null, n: 0 });
  const recheck = rechecks.id === transcribingFinalId ? rechecks.n : 0;
  const shownRead = useRef<string | null>(null);
  useEffect(() => {
    const token = ++selection.current;
    const latest = () => token === selection.current;
    setTranscript(null);
    setTranscriptError(false);
    setPlaybackUrl(null);
    setBookmarks([]);
    setSpeakerNames({});
    setPlaybackError(null);
    setActiveSegment(-1);
    setActiveWordIndex(-1);
    setNowMs(0);
    setPlaying(false);
    setEditing(false);
    setPanel(null);
    setVocabTerms([]);
    setLearn([]);
    setOpenSuggestion(null);
    playbackRefreshed.current = false;
    // While the final transcript is still being made, the live captions (when there are any) are worth reading.
    const id = (isTranscribing(finalStatus) && liveId) || finalId || liveId;
    shownRead.current = id ? readKey(id, sessionChanged, recheck) : null;
    if (id) {
      api.transcript(id).then((t) => latest() && setTranscript(t)).catch(() => {
        if (!latest()) return;
        setTranscript(null);
        setTranscriptError(true);
      });
    }
    // Temporary signed URL to the decrypted recording; the server streams it with Range support.
    if (current) {
      api
        .session(current.id)
        .then((d) => {
          if (!latest()) return;
          setPlaybackUrl(playbackUrlOf(d));
          setBookmarks(bookmarksOf(d));
          const names = d.speaker_names;
          setSpeakerNames(names && typeof names === "object" && !Array.isArray(names) ? (names as Record<string, string>) : {});
        })
        .catch(() => latest() && setPlaybackUrl(null));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [current?.id, finalId, liveId, finalStatus, transcriptAttempt]);
  useEffect(() => {
    if (!transcribingFinalId) return;
    // The transcript on screen was read for this very state of the recording a moment ago.
    if (finalShown && shownRead.current === readKey(transcribingFinalId, sessionChanged, recheck)) return;
    let alive = true;
    const token = selection.current;
    api.transcript(transcribingFinalId).then((t) => {
      if (!alive) return;
      if (!finalShown) setFinalRead({ id: transcribingFinalId, detail: t });
      else if (token === selection.current) setTranscript((shown) => (shown?.id === t.id ? t : shown));
    }).catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [transcribingFinalId, finalShown, recheck, sessionChanged]);
  const waitingFinal = !transcribingFinalId ? null
    : finalShown ? (transcript?.id === transcribingFinalId ? transcript : null)
      : finalRead?.id === transcribingFinalId ? finalRead.detail : null;
  const retryAt = waitingFinal?.retry_at ? Date.parse(waitingFinal.retry_at) : NaN;
  useEffect(() => {
    if (!transcribingFinalId || Number.isNaN(retryAt)) return;
    // A little after the retry time (at least every 6 hours, the longest wait); once it has passed, every minute until
    // the transcript says what came of it.
    const due = retryAt + 15_000 - Date.now();
    const timer = window.setTimeout(
      () => setRechecks((r) => ({ id: transcribingFinalId, n: (r.id === transcribingFinalId ? r.n : 0) + 1 })),
      due > 0 ? Math.min(due, 6 * 3_600_000) : 60_000,
    );
    return () => window.clearTimeout(timer);
  }, [transcribingFinalId, retryAt, recheck]);
  const retrying = retryLine(waitingFinal);
  // Find starts over for every recording.
  useEffect(() => {
    setFindOpen(false);
    setFindQuery("");
    setFindIndex(0);
  }, [current?.id]);

  /** The signed playback URL lives 30 minutes (docs/API.md); a failed load asks for a fresh one once (A6.DU8). */
  const onPlaybackError = () => {
    if (!current) return;
    const token = selection.current;
    const failedUrl = playbackUrl;
    const expired = () => {
      if (token !== selection.current) return;
      setPlaybackUrl(null);
      setPlaybackError(PLAYBACK_EXPIRED);
    };
    if (playbackRefreshed.current) return expired();
    playbackRefreshed.current = true;
    api
      .session(current.id)
      .then((d) => {
        if (token !== selection.current) return;
        const next = playbackUrlOf(d);
        if (next && next !== failedUrl) setPlaybackUrl(next);
        else expired();
      })
      .catch(expired);
  };

  // Server search of every transcript once two characters are typed (the list below filters titles locally).
  useEffect(() => {
    const q = query.trim();
    if (q.length < 2) {
      setFound([]);
      return;
    }
    let alive = true;
    const timer = window.setTimeout(() => {
      api.searchLibrary({ q }).then((r) => alive && setFound(r.data)).catch(() => alive && setFound([]));
    }, 350);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [query]);

  useEffect(() => {
    if (openRequest) openHit(openRequest.id, openRequest.ms);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openRequest?.seq]);

  // Show as (since v1.2): the Roman Urdu, Urdu or English view instead of the original words, once the server made it.
  const [showAs, setShowAs] = useState<"original" | TranscriptView>("original");
  const [views, setViews] = useState<TranscriptViewsState | null>(null);
  const transcriptId = transcript?.id ?? null;
  // The person picked a view (or Original) on this recording: views that become ready never switch it for them.
  const viewPicked = useRef(false);
  useEffect(() => {
    setShowAs("original");
    setViews(null);
    viewPicked.current = false;
    // Open in the default view (console setting) when it is made for this recording.
    const summary = transcript?.views;
    if (summary?.status === "ready" && summary.default && summary.default !== "original" && summary.available.includes(summary.default)) void chooseView(summary.default);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [transcriptId]);
  useEffect(() => {
    if (editing) setShowAs("original");
  }, [editing]);
  // While the server is writing the views, ask again every few seconds: for the chosen view, and (Phase 1C) for the slim
  // "Improving Roman Urdu… 60%" bar over the transcript, which is readable meanwhile.
  const viewsMaking = views ? views.status === "making" : transcript?.views?.status === "making";
  const viewsProgress = views?.progress ?? transcript?.views?.progress;
  const barView = viewsMaking ? improvingView(viewsProgress, showAs, transcript?.views?.default) : null;
  const barPercent = barView ? viewPercent(viewsProgress, barView) : null;
  useEffect(() => {
    if (!transcriptId || !viewsMaking || (showAs === "original" && readOnly)) return;
    const timer = window.setTimeout(() => {
      api.transcriptViews(transcriptId).then((v) => setViews(v)).catch(() => undefined);
    }, views ? VIEWS_POLL_MS : 0);
    return () => window.clearTimeout(timer);
  }, [transcriptId, showAs, views, viewsMaking, readOnly]);
  // Views that become ready swap in where the reader is (C3): the default view, unless the person chose one.
  const anchor = useRef<{ index: number; top: number } | null>(null);
  const keepPlace = () => {
    const rows = detailRef.current?.querySelectorAll<HTMLElement>(".segments .segment");
    const box = detailRef.current?.getBoundingClientRect();
    if (!rows || !box) return;
    const index = Array.from(rows).findIndex((r) => r.getBoundingClientRect().bottom > box.top);
    if (index >= 0) anchor.current = { index, top: rows[index].getBoundingClientRect().top };
  };
  useLayoutEffect(() => {
    const kept = anchor.current;
    anchor.current = null;
    const row = kept && detailRef.current?.querySelectorAll<HTMLElement>(".segments .segment")[kept.index];
    if (kept && row && detailRef.current) detailRef.current.scrollTop += row.getBoundingClientRect().top - kept.top;
  }, [showAs, views]);
  const wasMaking = useRef(false);
  useEffect(() => {
    const making = views?.status === "making";
    const becameReady = wasMaking.current && views?.status === "ready";
    wasMaking.current = making;
    const preferred = transcript?.views?.default;
    if (becameReady && !viewPicked.current && showAs === "original" && preferred && preferred !== "original" && views?.available.includes(preferred)) {
      keepPlace();
      setShowAs(preferred);
    } else if (becameReady) {
      keepPlace();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [views]);
  const chooseView = async (view: "original" | TranscriptView) => {
    setShowAs(view);
    if (view === "original" || !transcriptId || views?.status === "ready") return;
    try {
      let state = await api.transcriptViews(transcriptId);
      // A recording a teammate sent is only read: its views show once its owner has them made.
      if (readOnly && (state.status === "none" || state.status === "failed")) {
        setShowAs("original");
        return;
      }
      if (state.status === "none" || state.status === "failed") state = await api.makeTranscriptViews(transcriptId);
      setViews(state);
    } catch (e) {
      notify(errorMessage(e), true);
      setShowAs("original");
    }
  };
  const viewText = (i: number): string | null => (showAs !== "original" && views?.status === "ready" ? views[showAs]?.[i] ?? null : null);
  const screensAfter = useMemo(() => screensByLine(transcript?.segments ?? [], screens ?? []), [transcript, screens]);
  const openScreen = (screen: ScreenMoment) => setScreenOpen(Math.max(0, (screens ?? []).findIndex((s) => s.id === screen.id)));
  const deleteScreen = async (screen: ScreenMoment) => {
    if (!current || !window.confirm(`Delete screen ${screen.index}? The recording and its transcript stay.`)) return;
    try {
      await api.deleteScreen(current.id, screen.id);
      setScreenOpen(null);
      reloadScreens();
      notify(`Deleted screen ${screen.index}`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  // Unsure words go into the copy as "[?word]" (or the console's style); Copy for AI starts with the note line and puts
  // each screen ("[Screen 7 at 14:32: …]") after the line of its moment.
  const copy = async (withTimestamps: boolean, forAi = false) => {
    if (!transcript) return;
    try {
      const segments = transcript.segments.map((s) => ({ ...s, speaker: speakerName(speakerNames, s.speaker) }));
      const after = (i: number) => (screensAfter.get(i) ?? []).map(screenMarker);
      await navigator.clipboard.writeText(copiedTranscript(segments, viewText, withTimestamps, unsure, forAi, after));
      notify(forAi ? "Copied for AI, with the note on unsure words" : withTimestamps ? "Copied with timestamps" : "Copied");
    } catch {
      notify("Could not access the clipboard", true);
    }
  };

  // Find: the lines as shown (a view's words when one is on), every match, and the one in focus.
  const shownLines = useMemo(() => (transcript ? transcript.segments.map((s, i) => viewText(i) ?? s.text) : []),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [transcript, showAs, views]);
  const matches = useMemo(() => (findOpen ? findMatches(shownLines, findQuery) : []), [findOpen, shownLines, findQuery]);
  const currentMatch = matches.length > 0 ? Math.min(findIndex, matches.length - 1) : -1;
  useEffect(() => setFindIndex(0), [findQuery]);
  useEffect(() => {
    if (currentMatch < 0) return;
    segmentsRef.current?.querySelector<HTMLElement>("mark.find-hit.current")?.scrollIntoView?.({ block: "center", behavior: "smooth" });
  }, [currentMatch, findQuery]);
  const stepFind = (delta: 1 | -1) => {
    if (matches.length === 0) return;
    setFindIndex((i) => (Math.min(i, matches.length - 1) + delta + matches.length) % matches.length);
  };

  const seek = (ms: number) => {
    const audio = audioRef.current;
    if (!audio) return;
    audio.currentTime = ms / 1000;
    void audio.play().catch(() => undefined);
  };

  const onTimeUpdate = () => {
    const audio = audioRef.current;
    if (!audio || !transcript) return;
    const t = audio.currentTime * 1000;
    if (skipSilence && !audio.paused) {
      const target = skipTarget(transcript.segments, t);
      if (target !== null) {
        audio.currentTime = target / 1000;
        return;
      }
    }
    const index = activeIndex(transcript.segments, t);
    setNowMs(t);
    setActiveWordIndex(index >= 0 ? activeWord(transcript.segments[index].words, t) : -1);
    if (index !== activeSegment && index >= 0 && follow && !editing) {
      const row = segmentsRef.current?.querySelector<HTMLElement>(`[data-seg="${index}"]`);
      row?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
    }
    setActiveSegment(index);
  };

  const onLoaded = () => {
    const audio = audioRef.current;
    if (audio) audio.playbackRate = rate;
    if (audio) setMediaSeconds(audio.duration);
    if (pendingSeek.current !== null && audio) {
      audio.currentTime = pendingSeek.current / 1000;
      pendingSeek.current = null;
      onTimeUpdate();
    }
  };

  const changeRate = (next: number) => {
    setRate(next);
    if (audioRef.current) audioRef.current.playbackRate = next;
  };

  const togglePlay = () => {
    const audio = audioRef.current;
    if (!audio) return;
    if (audio.paused) void audio.play().catch(() => undefined);
    else audio.pause();
  };
  const skipBy = (seconds: number) => {
    const audio = audioRef.current;
    if (!audio) return;
    audio.currentTime = Math.max(0, audio.currentTime + seconds);
    onTimeUpdate();
  };

  /** Space / K / J / L / arrows anywhere in the window while a recording is open (never while typing). */
  const onPlayerKey = (e: KeyboardEvent) => {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === "f" && transcript) {
      e.preventDefault();
      setFindOpen(true);
      return;
    }
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey || document.querySelector(".palette-backdrop, .sheet-backdrop")) return;
    const audio = audioRef.current;
    const target = e.target instanceof HTMLElement ? e.target : document.body;
    const action = playerKey(e.key, target.tagName, target.isContentEditable, e.shiftKey);
    if (!audio || !action) return;
    e.preventDefault();
    if (action === "toggle") togglePlay();
    else skipBy(KEY_SECONDS[action]);
  };
  const playerKeyRef = useRef(onPlayerKey);
  playerKeyRef.current = onPlayerKey;
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => playerKeyRef.current(e);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  // J / K move through the list, Enter opens, X selects, Esc closes, "/" searches (v1.6, P4.4). Never while typing,
  // with a dialog or menu open, or with a modifier held.
  const listKeyRef = useRef<(e: KeyboardEvent) => void>(() => undefined);
  listKeyRef.current = (e: KeyboardEvent) => {
    if (e.defaultPrevented || e.repeat || shared) return;
    const target = e.target instanceof HTMLElement ? e.target : null;
    if (target && (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName))) return;
    if (document.querySelector(".palette-backdrop, .sheet-backdrop, .menu-pop, .row-menu-pop, details[open] > .bulk-pop, .suggest-pop, .filter-panel")) return;
    if (e.key === "/" && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();
      searchRef.current?.focus();
      return;
    }
    const key = listKey(e);
    if (!key) return;
    if (key === "next" || key === "previous") {
      const at = rows.findIndex((i) => i.id === (cursor ?? selected));
      const next = rows[stepIndex(at, rows.length, key === "next" ? 1 : -1)];
      if (!next) return;
      e.preventDefault();
      setCursor(next.id);
      document.querySelector(`[data-row="${CSS.escape(next.id)}"]`)?.scrollIntoView?.({ block: "nearest" });
    } else if (key === "open") {
      // Enter on a button is the button's own.
      if (!cursor || target?.tagName === "BUTTON" || target?.tagName === "A") return;
      e.preventDefault();
      setSelected(cursor);
    } else if (key === "pick") {
      const id = cursor ?? selected;
      if (!id) return;
      e.preventDefault();
      setSelecting(true);
      togglePicked(id);
    } else if (key === "close") {
      if (selected) {
        e.preventDefault();
        setSelected(null);
      } else if (selecting) {
        e.preventDefault();
        endSelecting();
      }
    }
  };
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => listKeyRef.current(e);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  const openHit = (id: string, ms: number) => {
    pendingSeek.current = ms;
    setHitSelected(id);
    if (id === selected && audioRef.current) onLoaded();
    setSelected(id);
  };

  const saveLine = async (index: number, text: string) => {
    if (!transcript || text.trim() === transcript.segments[index].text) return;
    const before = transcript.segments[index].text;
    try {
      const { learn: offers, ...edited } = await api.editTranscript(transcript.id, index, text);
      setTranscript(edited);
      // For a client's recording the server names the words worth a rule ("always write X as Y"); that offer takes
      // the place of the keyterm hint.
      const rules = Array.isArray(offers) ? offers : [];
      setLearn(rules);
      setVocabTerms(rules.length > 0 ? [] : newTerms(before, text));
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const answerLearn = async (yes: boolean) => {
    const offer = learn[0];
    if (!offer) return;
    setLearn((list) => list.slice(1));
    if (!yes) return;
    try {
      await api.addReplacement(offer.from, offer.to, offer.client_id);
      notify(`${offer.client_name}'s transcripts will write “${offer.to}” from now on`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const openSuggestions = useMemo(() => (transcript?.suggestions ?? []).filter((s) => s.status === "open"), [transcript]);
  const decideSuggestion = async (suggestion: TranscriptSuggestion, accept: boolean) => {
    if (!transcript) return;
    setSuggestBusy(true);
    try {
      const next = await api.decideSuggestion(transcript.id, suggestion.id, accept);
      setTranscript(next);
      // The next open fix (if any) stays one click away; the decided one closes.
      setOpenSuggestion(null);
      notify(accept ? `Changed to “${suggestion.to}”` : "Suggestion dismissed");
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setSuggestBusy(false);
    }
  };

  const replaceAll = async (find: string, replace: string, matchCase: boolean, wholeWord: boolean): Promise<number | null> => {
    if (!transcript) return null;
    try {
      const result = await api.replaceInTranscript(transcript.id, find, replace, matchCase, wholeWord);
      setTranscript(result.transcript);
      return result.replaced;
    } catch (e) {
      notify(errorMessage(e), true);
      return null;
    }
  };

  const restore = async () => {
    if (!transcript) return;
    try {
      setTranscript(await api.restoreTranscript(transcript.id));
      setPanel(null);
      notify("The original transcript is back");
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const saveSpeakers = async (names: Record<string, string | null>, remember: boolean): Promise<boolean> => {
    if (!current) return false;
    try {
      setSpeakerNames((await api.renameSpeakers(current.id, names, remember)).speaker_names ?? {});
      notify("Speaker names saved");
      return true;
    } catch (e) {
      notify(errorMessage(e), true);
      return false;
    }
  };

  // Exports follow the view on screen (Roman Urdu, اردو, English) once it is made, else the original words.
  const exportView = showAs !== "original" && views?.status === "ready" ? showAs : null;
  const exportAs = async (format: ExportFormat) => {
    if (!transcript) return;
    try {
      const path = await api.exportTranscript(transcript.id, format, true, true, exportView);
      // null: the save dialog was cancelled, nothing to say.
      if (format === "html") notify("Opened the print page in your browser: choose Save as PDF there");
      else if (path) notify(`Saved to ${path}`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  // Export → Meeting pack (since v1.5): the transcript with its screens at their moments, in the view on screen.
  const packAs = async (format: MeetingPackFormat, withAudio: boolean) => {
    if (!current) return;
    try {
      const path = await api.meetingPack(current.id, format, exportView, withAudio);
      if (format === "pdf") notify("Opened the meeting pack in your browser: choose Save as PDF there");
      else if (path) notify(`Saved to ${path}`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const addToVocabulary = async (term: string) => {
    try {
      await api.addToVocabulary(term, current?.client?.id ?? null);
      setVocabTerms((terms) => terms.filter((t) => t !== term));
      notify(`Added “${term}” to the vocabulary`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const remove = async (id: string) => {
    if (!window.confirm("Delete this recording and transcript on every device? You can restore it from Recently deleted for 48 hours.")) return;
    try {
      await api.deleteSession(id);
      // Deleting another row from its menu leaves the open recording open.
      setSelected((open) => (open === id ? null : open));
      setItems(await api.library());
      notify("Deleted");
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  // Rename from the row menu (in the list) or by clicking the title of the open recording.
  const [renaming, setRenaming] = useState<{ id: string; where: "list" | "detail" } | null>(null);
  const replaceItem = (id: string, change: (item: LibraryItem) => LibraryItem) => setItems((list) => list.map((i) => (i.id === id ? change(i) : i)));
  /** The new name shows at once; the server's answer (and the next sync on other devices) confirms it. */
  const rename = async (item: LibraryItem, text: string) => {
    setRenaming(null);
    const title = text.trim();
    if (title === (item.title ?? "").trim()) return;
    // An empty title asks the server for its automatic one again (Phase 1C, `title: null`); a title the person types is
    // theirs, and the server never replaces it.
    if (title) replaceItem(item.id, (i) => ({ ...i, title, title_auto: false }));
    try {
      const answer = await api.organiseSession(item.id, { title: title || null });
      if (answer?.data?.id === item.id) replaceItem(item.id, () => answer.data);
      notify(title ? "Renamed" : "The automatic title will be used");
    } catch (e) {
      replaceItem(item.id, (i) => ({ ...i, title: item.title, title_auto: item.title_auto }));
      notify(errorMessage(e), true);
    }
  };
  const togglePin = async (item: LibraryItem) => {
    try {
      await api.organiseSession(item.id, { pinned: !item.pinned });
      replaceItem(item.id, (i) => ({ ...i, pinned: !item.pinned }));
      notify(item.pinned ? "Unpinned" : "Pinned");
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const saveAudio = async () => {
    if (!current) return;
    try {
      const path = await api.saveAudio(current.id);
      if (path) notify(`Saved to ${path}`);
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  /** The words of a line as shown: find marks, a view's words, suggested-fix marks, the played word, or plain text. */
  const lineContent = (i: number): ReactNode => {
    const s = transcript!.segments[i];
    const inView = viewText(i);
    const lineMatches = matches.map((m, index) => ({ ...m, index })).filter((m) => m.seg === i);
    if (lineMatches.length > 0) {
      return markParts(shownLines[i], lineMatches).map((p, k) => (p.match === null ? <span key={k}>{p.text}</span> : (
        <mark key={k} className={`find-hit ${p.match === currentMatch ? "current" : ""}`}>{p.text}</mark>
      )));
    }
    if (inView !== null) return withUnsureMarks(inView);
    // Suggested fixes are decided by whoever may change the transcript, never on a recording a teammate sent.
    const fixes = (readOnly ? [] : openSuggestions).filter((f) => f.index === i).map((f) => ({ f, span: suggestionSpan(s.text, f.from) })).filter((x) => x.span)
      .sort((a, b) => a.span![0] - b.span![0]);
    if (fixes.length > 0) {
      const out: ReactNode[] = [];
      let pos = 0;
      for (const { f, span } of fixes) {
        const [from, to] = span!;
        if (from < pos) continue;
        out.push(s.text.slice(pos, from));
        out.push(
          <span key={f.id} className="suggest-mark" title={`Suggested: ${f.to}${f.reason ? ` (${f.reason})` : ""}`} aria-expanded={openSuggestion === f.id}
            onClick={(e) => { e.stopPropagation(); setOpenSuggestion(openSuggestion === f.id ? null : f.id); }}>{s.text.slice(from, to)}</span>,
        );
        pos = to;
      }
      out.push(s.text.slice(pos));
      return out;
    }
    // Words the engine was not sure of are underlined (a click plays from that word); the text stays as heard.
    if (s.words && s.words.length > 0 && (i === activeSegment || hasUncertainWords(s))) {
      return s.words.map((w, wi) => {
        const doubt = uncertainWord(w);
        const className = ["word", i === activeSegment && wi === activeWordIndex ? "on" : "", doubt ? "uncertain" : ""].filter(Boolean).join(" ");
        return (
          <span key={wi}>
            <span className={className} title={doubt ? UNSURE_WORD : undefined}
              onClick={doubt && playbackUrl ? (e) => { e.stopPropagation(); seek(w.start_ms); } : undefined}>{w.text}</span>
            {wi < s.words!.length - 1 ? " " : ""}
          </span>
        );
      });
    }
    return s.text;
  };

  const total = items.filter((i) => !i.deleted).length;
  // The place shown after "Library" (the shell names it; on its own the Library names it itself).
  const place = crumb ?? (smartView ? LIBRARY_VIEWS.find(([v]) => v === smartView)?.[1] ?? null : smartFolder ? folderLabel(smartFolder) : null);
  const durationMs = current ? playerLength(current.duration_ms, transcript?.segments, mediaSeconds) : 0;
  const pendingFix = readOnly ? null : openSuggestions.find((f) => f.id === openSuggestion) ?? null;
  return (
    <>
      <PageHeader crumbs={place ? [{ label: "Library", onOpen: onSmartFolder ? () => onSmartFolder(null) : undefined }, { label: place }] : []}
        title={place ?? "Library"}
        subtitle={smartView ? `${filtered.length} recording${filtered.length === 1 ? "" : "s"} ${VIEW_LINE[smartView]}`
          : smartFolder ? (shared ? "Recordings and projects teammates sent you." : `${filtered.length} recording${filtered.length === 1 ? "" : "s"} in this smart folder of your Library.`)
            : total === 0 ? "Recordings from your phone, this computer and the web will appear here." : `${total} recording${total === 1 ? "" : "s"} from your phone, this computer and the web.`}
        actions={<>
          {!shared && <button type="button" className="btn ghost" aria-pressed={binOpen} onClick={() => setBinOpen(!binOpen)}><TrashIcon size={16} />Recently deleted</button>}
          <button className="btn ghost" onClick={refresh} disabled={loading}><RefreshIcon size={16} />{loading ? "Syncing…" : "Sync"}</button>
          {onImport && (
            <button type="button" className="btn primary" onClick={onImport} title="Voice notes, audio or video files. You can also drop them anywhere in this window.">
              <ImportIcon size={16} />Upload
            </button>
          )}
        </>} />
      <div className="page-split">
      {aside}
      <div className="page-main library-main">
      {!shared && (
      <div className="toolbar library-toolbar" role="toolbar" aria-label="Library tools">
        <label className="searchbox compact library-search">
          <SearchIcon size={16} />
          <input ref={searchRef} value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search recordings and transcripts" aria-label="Search recordings"
            onKeyDown={(e) => { if (e.key === "Escape" && query) { e.stopPropagation(); setQuery(""); } }} />
          {query ? <button className="clear" onClick={() => setQuery("")} aria-label="Clear search"><CloseIcon size={16} /></button> : <span className="kbd" aria-hidden="true">/</span>}
        </label>
        <LibraryFilters state={filters} onChange={setFilters} counts={viewCounts} tags={tags} showBookmarks={anyBookmarks || filters.tags.size > 0} />
        {(folders.length > 0 || folder !== null) && (
          <select className={`chip ${folder !== null ? "active" : ""}`} value={folder ?? "*"} onChange={(e) => setFolder(e.target.value === "*" ? null : e.target.value)} aria-label="Filter by project">
            <option value="*">All projects</option>
            <option value="">No project</option>
            {folders.map((cl) => <option key={cl.id} value={cl.id}>{`${folderBadge(cl)} · ${cl.name}`}</option>)}
          </select>
        )}
        {(labels.length > 0 || label !== null) && (
          <select className={`chip ${label !== null ? "active" : ""}`} value={label ?? ""} onChange={(e) => setLabel(e.target.value || null)} aria-label="Label">
            <option value="">Any label</option>
            {labels.map((l) => <option key={l} value={l}>{l}</option>)}
          </select>
        )}
        <ViewMenu sections={[
          { label: "Group by", value: choice.group, options: (Object.keys(GROUP_LABELS) as GroupKey[]).map((k) => [k, GROUP_LABELS[k]] as const), onChange: (group) => setChoice({ group: group as GroupKey }) },
          { label: "Sort by", value: sort, options: (Object.keys(SORT_LABELS) as SortKey[]).map((k) => [k, SORT_LABELS[k]] as const), onChange: (next) => setChoice({ sort: next as SortKey }) },
          { label: "Density", value: density, options: DENSITIES, onChange: (next) => chooseDensity(next as Density) },
        ]} />
        <span className="segmented layout-switch" role="group" aria-label="Show as">
          <button type="button" aria-pressed={layout === "list"} title="List" onClick={() => chooseLayout("list")}><ListIcon size={16} /><span className="sr-only">List</span></button>
          <button type="button" aria-pressed={layout === "table"} title="Table" onClick={() => chooseLayout("table")}><TableIcon size={16} /><span className="sr-only">Table</span></button>
        </span>
        {layout === "table" && (
          <Menu label="Columns" title="Columns" buttonClass="chip columns-btn" button={<ColumnsIcon size={16} />}
            items={TABLE_COLUMNS.map(([id, name]) => ({ id: `col-${id}`, label: name, checked: columns.has(id), onPick: () => toggleColumn(id) }))} />
        )}
        <button type="button" className="chip" aria-pressed={selecting} onClick={() => (selecting ? endSelecting() : setSelecting(true))}>{selecting ? "Cancel selection" : "Select"}</button>
      </div>
      )}
      <div className={`library ${tableView ? "as-table" : ""}`}>
        <div className="list" data-testid="library-list">
          {binOpen && <BinPanel notify={notify} onClose={() => setBinOpen(false)} />}
          {shared && (
            <SharedWithMe notify={notify} openId={sharedOpen?.id ?? null} focusId={sharedFocus}
              onOpen={(item) => { if (!item.session) return; setSharedOpen(item); setSelected(item.session.id); }}
              onSaved={(saved, item) => {
                if (saved.client_id) onOpenClient?.(saved.client_id);
                else if (saved.session_id) { onSmartFolder?.(null); openHit(saved.session_id, 0); }
                else if (item.session) setSelected(item.session.id);
              }} />
          )}
          {selecting && !shared && <BulkBar ids={pickedIds} clients={folders} knownLabels={labels} notify={notify} onDone={endSelecting} onClientCreated={addClient} onSelectAll={() => setPicked(new Set(filtered.map((i) => i.id)))} />}
          {syncError && (
            <div className="notice error sync-error" role="alert">
              <span>Couldn't sync with the server. Showing what is saved on this computer.</span>
              <button type="button" className="btn compact" onClick={refresh} disabled={loading}>Retry</button>
            </div>
          )}
          {!smartFolder && smartView !== "pinned" && <LibraryQueue notify={notify} focus={focus} onFocusHandled={onFocusHandled} />}
          {shownHits.length > 0 && (
            <div className="group search-hits" role="region" aria-label="Matches in transcripts">
              <div className="group-label">In transcripts</div>
              {shownHits.map((r) => (
                <div key={r.session.id} className="hit-card">
                  <div className="item-title">{r.session.title}</div>
                  <div className="small muted">{formatWhen(r.session.started_at)}{r.hit_count > r.hits.length ? ` · ${r.hit_count} matches` : ""}</div>
                  {r.hits.map((h) => (
                    <button key={`${h.transcript_id}-${h.segment_index}`} type="button" className="hit" onClick={() => openHit(r.session.id, h.start_ms)}
                      aria-label={`Play ${r.session.title ?? "recording"} from ${timestamp(h.start_ms)}`}>
                      <span className="seg-time">{timestamp(h.start_ms)}</span>
                      <span className="hit-snippet" dir="auto"><b>{h.speaker}:</b> {snippetParts(h.snippet, h.highlights).map((p, i) => (p.hit ? <mark key={i}>{p.text}</mark> : <span key={i}>{p.text}</span>))}</span>
                    </button>
                  ))}
                </div>
              ))}
            </div>
          )}
          {!shared && ((!loaded && items.length === 0) || (smartFolder && folderItems === null && folderLoading && filtered.length === 0)) && (
            <div className="group" aria-busy="true">
              <span className="sr-only">Loading recordings…</span>
              {[0, 1, 2].map((i) => (
                <div key={i} className="skeleton-row" aria-hidden="true">
                  <span className="skeleton tile" />
                  <span className="skeleton-lines"><span className="skeleton" style={{ width: "62%", height: 14 }} /><span className="skeleton" style={{ width: "38%", height: 10 }} /></span>
                </div>
              ))}
            </div>
          )}
          {loaded && !shared && filtered.length === 0 && !(smartFolder && folderItems === null && folderLoading) && (
            smartFolder && !query && activeFilterCount(filters) === 0 && label === null && folder === null ? (
              <div className="empty-state">
                <span className="empty-icon"><LibraryIcon size={26} /></span>
                <span className="empty-title">{smartFolder === "inbox" ? "The Inbox is empty" : "Nothing here yet"}</span>
                <p>{smartFolder === "inbox" ? "Recordings in no project land here. Every recording is in a project now." : "Recordings show up here as soon as there are some of this kind."}</p>
              </div>
            ) : total === 0 && !query && activeFilterCount(filters) === 0 && label === null && folder === null ? (
              <div className="empty-state">
                <span className="empty-icon"><LibraryIcon size={26} /></span>
                <span className="empty-title">No recordings yet.</span>
                <p>Calls, voice notes and imported files show up here, from every device.</p>
                {onImport && <button type="button" className="btn" onClick={onImport}><ImportIcon size={16} />Import files</button>}
              </div>
            ) : (
              <div className="empty">{filters.views.size === 1 && filters.views.has("waiting") ? "Nothing is waiting here. Recordings waiting for Keep or Discard on this computer are listed at the top." : "Nothing matches. Try a different search or filter."}</div>
            )
          )}
          {!shared && tableView && filtered.length > 0 && (
            <LibraryTable groups={groups} columns={columns} collapsed={collapsedGroups} onToggleGroup={toggleCollapsed} picked={picked} selecting={selecting}
              selected={selected} cursor={cursor} onOpen={(item) => { setCursor(item.id); setSelected(item.id); }} onPick={togglePicked}
              onPickGroup={(items) => { setSelecting(true); setPicked((p) => toggleGroup(items, p)); }} onPointerDown={startDrag}
              menu={(item) => (
                <RowMenu title={item.title ?? "recording"} pinned={!!item.pinned}
                  onRename={() => { setSelected(item.id); setRenaming({ id: item.id, where: "detail" }); }}
                  onMove={() => setMoveFor({ ids: [item.id], title: item.title ?? null, current: item.client?.id ?? null })}
                  onPin={() => void togglePin(item)}
                  onDelete={() => void remove(item.id)} />
              )} />
          )}
          {!shared && !tableView && groups.map((group) => (
            <div key={group.key} className="group" role="group" aria-label={group.label || undefined}>
              {group.label && (
                <GroupHeader label={group.label} items={group.items} picked={picked} collapsed={collapsedGroups.has(group.key)}
                  onToggle={() => toggleCollapsed(group.key)}
                  onPick={() => { setSelecting(true); setPicked((p) => toggleGroup(group.items, p)); }} />
              )}
              {!collapsedGroups.has(group.key) && group.items.map((item) => {
                const state = itemState(item);
                // Like the phone (v1.4): length, client and bookmarks; the language is a filter and lives on the recording page.
                const sub = [formatDuration(item.duration_ms) || null, item.client?.name,
                  item.marker_count ? `${item.marker_count} bookmark${item.marker_count === 1 ? "" : "s"}` : null].filter(Boolean).join(" · ");
                const avatar = (
                  <span className={`avatar ${item.source_device ?? "web"} app-${appKind(item.source_app)}`}>
                    <AppTileIcon app={item.source_app} data-motion="tile" />
                    <span className="avatar-device" title={item.source_device_label}><DeviceBadgeIcon source={item.source_device} /></span>
                  </span>
                );
                // Renaming from the row menu: the row holds the title field instead of being one button.
                if (renaming?.id === item.id && renaming.where === "list") {
                  return (
                    <div key={item.id} className={`item renaming ${item.id === selected ? "selected" : ""}`}>
                      <div className="row">
                        {avatar}
                        <div className="item-main">
                          <TitleEditor className="item-rename" initial={item.title ?? ""} onSave={(t) => void rename(item, t)} onCancel={() => setRenaming(null)} />
                          <div className="item-sub">{sub}</div>
                        </div>
                      </div>
                    </div>
                  );
                }
                return (
                  <div key={item.id} className={`item-wrap ${selecting ? "" : "with-menu"}`}>
                    <button type="button" className={`item ${item.id === selected ? "selected" : ""} ${selecting && picked.has(item.id) ? "picked" : ""} ${item.id === cursor ? "cursor" : ""}`}
                      data-row={item.id}
                      aria-current={!selecting && item.id === selected ? "true" : undefined} aria-pressed={selecting ? picked.has(item.id) : undefined}
                      onPointerDown={(e) => startDrag(e, item)}
                      onClick={(e) => {
                        if (dragJustEnded()) return;
                        if (selecting) togglePicked(item.id);
                        else {
                          // The recording page opens from this row: its title and tile move from here (lib/motion.ts).
                          opening.current = item.id === selected ? null : snapshotRow(item.id, e.currentTarget);
                          setSelected(item.id);
                        }
                      }}>
                      <div className="row">
                        {avatar}
                        <div className="item-main">
                          <div className="item-line">
                            <span className="item-title" data-motion="title">{item.title}</span>
                            {item.pinned && <span className="pin-star" aria-label="Pinned"><PinIcon size={14} /></span>}
                            <span className="item-time">{rowTime(item.started_at)}</span>
                          </div>
                          <div className="item-sub">
                            {state === "ready" ? <span>{sub}</span> : (
                              <span className={`pill ${state}`}>
                                {state === "processing" ? <span className="spinner" aria-hidden="true" /> : state === "failed" ? <AlertIcon size={14} /> : null}
                                {state === "processing" ? stepOf(item).line ?? STATE_LABEL[state] : STATE_LABEL[state]}
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                      {(item.labels ?? []).length > 0 && <div className="item-labels">{item.labels!.map((l) => <span key={l} className="label-chip small">{l}</span>)}</div>}
                    </button>
                    {!selecting && (
                      <RowMenu title={item.title ?? "recording"} pinned={!!item.pinned}
                        onRename={() => setRenaming({ id: item.id, where: "list" })}
                        onMove={() => setMoveFor({ ids: [item.id], title: item.title ?? null, current: item.client?.id ?? null })}
                        onPin={() => void togglePin(item)}
                        onDelete={() => void remove(item.id)} />
                    )}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
        {tableView && current && <div className="drawer-scrim" aria-hidden="true" onMouseDown={() => setSelected(null)} />}
        <div className={`detail card ${tableView ? "drawer" : ""}`} ref={detailRef} hidden={tableView && !current} role={tableView ? "dialog" : undefined}
          aria-label={tableView && current ? current.title ?? "Recording" : undefined}>
          {tableView && current && (
            <button type="button" className="btn ghost icon-only drawer-close" aria-label="Close" title="Close (Esc)" onClick={() => setSelected(null)}><CloseIcon size={18} /></button>
          )}
          {!current && (
            <div className="empty-state">
              <span className="empty-icon"><LibraryIcon size={26} /></span>
              <p>Select a recording to read its transcript.</p>
            </div>
          )}
          {current && (
            <>
              <div className="detail-body">
                {findOpen && transcript && (
                  <FindBar query={findQuery} onQuery={setFindQuery} count={matches.length} current={Math.max(0, currentMatch)} onStep={stepFind}
                    onClose={() => { setFindOpen(false); setFindQuery(""); }} />
                )}
                <div className="detail-head">
                  <div className="detail-actions">
                    <div className="detail-sources">
                      <SourceAppBadge app={current.source_app} label={current.source_app_label} motion="tile" />
                      <SourceDeviceBadge source={current.source_device} label={current.source_device_label} />
                    </div>
                    <span className="spacer" />
                    {/* As on the phone's recording page: quiet icon buttons, each named for screen readers and the tooltip. */}
                    {transcript && (
                      <button type="button" className="btn ghost icon-only" aria-label="Find in this transcript" aria-pressed={findOpen} title="Find in this transcript (Ctrl+F)" onClick={() => setFindOpen(!findOpen)}><SearchIcon size={18} /></button>
                    )}
                    {playbackUrl && !readOnly && <button type="button" className="btn ghost icon-only" aria-label="Save audio" onClick={() => void saveAudio()} title="Save the recording's audio file on this computer"><DownloadIcon size={18} /></button>}
                    {!readOnly && <button className="btn ghost icon-only" aria-label="Share" title="Share a view-only link" aria-pressed={panel === "share"} onClick={() => setPanel(panel === "share" ? null : "share")}><LinkIcon size={18} /></button>}
                    {!readOnly && <button className="btn danger ghost icon-only" aria-label="Delete" title="Delete this recording" onClick={() => remove(current.id)}><TrashIcon size={18} /></button>}
                  </div>
                  {readOnly ? (
                    <h2 className="detail-title" data-motion="title">{current.title}</h2>
                  ) : renaming?.id === current.id && renaming.where === "detail" ? (
                    <TitleEditor className="detail-title-input" initial={current.title ?? ""} onSave={(t) => void rename(current, t)} onCancel={() => setRenaming(null)} />
                  ) : (
                    <h2 className="detail-title" data-motion="title">
                      <button type="button" className="title-button" title="Click to rename" onClick={() => setRenaming({ id: current.id, where: "detail" })}>{current.title}</button>
                      {current.title_auto && (
                        <span className="auto-title small muted" data-testid="auto-title" title="EchoLedger named it from what was said. Click the title to give it your own; yours is never replaced.">
                          <SparkleIcon size={12} />Automatic title
                        </span>
                      )}
                    </h2>
                  )}
                  <div className="detail-meta">
                    {formatWhen(current.started_at)} · {formatDuration(current.duration_ms)}
                    {current.client ? <> · {onOpenClient && !readOnly ? <button type="button" className="btn text compact" style={{ height: 22, padding: "0 4px" }} onClick={() => onOpenClient(current.client!.id)}>{current.client.name}</button> : current.client.name}</> : null}
                  </div>
                  {!readOnly && <RecordingSteps key={current.id} item={current} improving={barView !== null && transcript?.kind === "final"} notify={notify} />}
                  {engineLine(current.transcript) && <div className="small muted" data-testid="engine-line">Transcribed in {engineLine(current.transcript)}</div>}
                  {retrying && (
                    <div className="small muted retry-line" role="status" data-testid="retry-line" title={retryReason(waitingFinal?.retry_reason, providerName(waitingFinal?.provider) || "The engine") ?? undefined}>{retrying}</div>
                  )}
                  {transcript?.kind === "final" && transcript.status === "completed" && usedBackupEngine(transcript) && !isTranscribing(finalStatus) && !readOnly && (
                    <div className="backup-line" role="status" data-testid="backup-engine">
                      <span className="small">Backup engine — Re-transcribe?</span>
                      <button type="button" className="btn ghost compact" title="Tries the preferred engine again, in the same language" onClick={() => void retranscribeSame()}>Re-transcribe</button>
                    </div>
                  )}
                  {readOnly && sharedOpen && (
                    <div className="notice shared-banner" role="note" data-testid="shared-banner">
                      <div>{sentLine(sharedOpen)}{sharedOpen.note ? ` · “${sharedOpen.note}”` : ""}</div>
                      <div className="muted">You can play and read it. Save to my library makes a copy you can change.</div>
                    </div>
                  )}
                  {!readOnly && <OrganisePanel item={current} clients={folders} knownLabels={labels} notify={notify} onClientCreated={addClient}>
                    {current.transcript?.final_error_code !== "no_audio" && current.transcript?.final_status && (
                      <span className="head-chip retranscribe-row">
                        <span className="chip-key" aria-hidden="true">Language</span>
                        <select aria-label="Re-transcribe in" value={retranscribeLanguage} onChange={(e) => setRetranscribeLanguage(e.target.value)}>
                          {MAIN_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{menuLabel(o)}</option>)}
                          <optgroup label="More languages">{MORE_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{menuLabel(o)}</option>)}</optgroup>
                        </select>
                        <button type="button" className="btn ghost compact" onClick={() => void retranscribe()}>
                          {englishRisk && englishWarned ? "Re-transcribe anyway" : "Re-transcribe"}
                        </button>
                      </span>
                    )}
                    {alternateId && (
                      <span className="segmented" role="group" aria-label="Version">
                        <button type="button" aria-pressed={version === "final"} onClick={() => setVersion("final")}>Current</button>
                        <button type="button" aria-pressed={version === "alternate"} onClick={() => setVersion("alternate")}>
                          Previous{current.transcript?.alternate_language ? ` (${languageLabel(current.transcript.alternate_language)})` : ""}
                        </button>
                      </span>
                    )}
                  </OrganisePanel>}
                  {englishRisk && englishWarned && (
                    <div className="notice" role="alert" data-testid="english-only-warning">
                      This recording has Urdu in it. English only makes the engine translate the Urdu into English. Choose Urdu (with English words) to keep every word, or click Re-transcribe anyway.
                    </div>
                  )}
                  {scriptWarning(current.transcript?.script_warning) && <div className="notice" role="status">{scriptWarning(current.transcript?.script_warning)}</div>}
                </div>
                {panel === "share" && !readOnly && (
                  <ShareDialog target={{ kind: "session", id: current.id, title: current.title ?? null, hasAudio: !!playbackUrl }} view={exportView} notify={notify} onClose={() => setPanel(null)} />
                )}
                {playbackError && <div className="notice" style={{ marginTop: 12 }}>{playbackError}</div>}
                {bookmarks.length > 0 && (
                  <div className="chips bookmarks" role="list" aria-label="Bookmarks" style={{ marginTop: 12 }}>
                    {bookmarks.map((b) => {
                      const text = `${timestamp(b.at_ms)}${b.tag ? ` ${b.tag}` : ""}`;
                      return (
                        <span role="listitem" key={b.id}>
                          <button type="button" className="chip small" title={b.note ?? undefined} disabled={!playbackUrl} onClick={() => seek(b.at_ms)}><BookmarkIcon size={12} />{text}</button>
                        </span>
                      );
                    })}
                  </div>
                )}
                {transcript ? (
                  transcript.status === "failed" ? (
                    <div className="notice error" style={{ marginTop: 12 }}>
                      <strong>Transcription failed.</strong> {transcript.error ?? "The speech provider could not process this recording."}
                      {/* The server says what happened; the desktop knows what to check (docs/API.md `error_code`). */}
                      {transcript.error_code === "no_audio" && <div className="notice-hint">{NO_AUDIO_HINT}</div>}
                      {/* One click to try again (v1.6, P6), in the same language; not when the key is missing or nothing was heard. */}
                      {!readOnly && transcript.error_code !== "no_audio" && transcript.error_code !== "needs_transcription_key" && (
                        <div className="notice-hint"><button type="button" className="btn compact" onClick={() => void retranscribeSame()}>Try again</button></div>
                      )}
                      {/* The Free plan (v1.6): transcribed with the person's own Groq key, and none is added yet. */}
                      {transcript.error_code === "needs_transcription_key" && (
                        <div className="notice-hint needs-key" data-testid="needs-key">
                          <span>{NEEDS_KEY_TEXT}</span>
                          <button type="button" className="btn compact" onClick={() => openSettingsSection("transcription-key")}>Add my Groq key</button>
                        </div>
                      )}
                    </div>
                  ) : isTranscribing(transcript.status) ? (
                    // The library's progress is the fresher one: live status re-reads it every few seconds.
                    <div className="empty-state"><span className="spinner" aria-hidden="true" /><p>{transcribingLine(current.transcript?.final_progress ?? transcript.progress)}</p></div>
                  ) : (
                    <>
                      <div className="transcript-bar">
                        <span className="small muted engine">{transcript.kind === "final" ? `Final transcript · ${providerName(transcript.provider)}${transcript.model ? ` · ${transcript.model}` : ""}${transcript.edited_at ? " · edited" : ""}` : `Live captions (final transcript pending${partsText(current.transcript?.final_progress) ? ` · ${partsText(current.transcript?.final_progress)}` : ""})`}</span>
                        {(transcript.views?.available.length ?? 0) > 0 && transcript.kind === "final" && transcript.status === "completed" && transcript.segments.length > 0
                          && (!readOnly || transcript.views?.status === "ready") && (
                          <span className="segmented view-switch" role="group" aria-label="Show as">
                            {VIEW_ORDER.filter((v) => transcript.views!.available.includes(v)).map((v) => (
                              <button key={v} type="button" lang={v === "urdu" ? "ur" : undefined} aria-pressed={showAs === v} onClick={() => { viewPicked.current = true; void chooseView(v); }}>{VIEW_LABELS[v]}</button>
                            ))}
                            <button type="button" aria-pressed={showAs === "original"} onClick={() => { viewPicked.current = true; void chooseView("original"); }}>Original</button>
                          </span>
                        )}
                        {/* The views are a paid feature since v1.6: their badge where the switch would be. */}
                        {transcript.kind === "final" && transcript.status === "completed" && transcript.segments.length > 0 && !readOnly && (
                          <LockedChip feature="views" label="Roman Urdu · English · اردو" />
                        )}
                      </div>
                      {barView !== null && transcript.kind === "final" && <ImprovingBar view={barView} percent={barPercent} />}
                      <div className="copy-row">
                        <button className="btn compact" onClick={() => copy(false)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy text</button>
                        <button className="btn compact" onClick={() => copy(true)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy with timestamps</button>
                        <button className="btn text compact" onClick={() => copy(false, true)} disabled={transcript.segments.length === 0}
                          title={screens?.length ? "Copies the text with unsure words marked [?word], a first line that tells an AI what the marks mean, and each screen at its moment"
                            : "Copies the text with unsure words marked [?word] and a first line that tells an AI what the marks mean"}><SparkleIcon size={14} />Copy for AI</button>
                        {!!screens?.length && (
                          <button type="button" className="btn ghost compact" aria-pressed={galleryOpen} onClick={() => setGalleryOpen(!galleryOpen)}>
                            <MonitorIcon size={14} />{screens.length === 1 ? "1 screen" : `${screens.length} screens`}
                          </button>
                        )}
                      </div>
                      {galleryOpen && !!screens?.length && <ScreenGallery screens={screens} onOpen={openScreen} onClose={() => setGalleryOpen(false)} />}
                      {showAs !== "original" && (
                        <div className="small muted" role="status" data-testid="view-status" style={{ marginTop: 6 }}>
                          {views?.status === "ready"
                            ? `${VIEW_LABELS[showAs]} view${views.model ? ` by ${views.model}` : ""}. The transcript itself is unchanged.`
                            : views?.status === "failed"
                              ? `The view could not be made: ${views.error ?? "try again later."}`
                              : `Writing the ${VIEW_LABELS[showAs]} view… this takes a minute or two.`}
                        </div>
                      )}
                      {transcript.kind === "final" && transcript.status === "completed" && transcript.segments.length > 0 && !readOnly && (
                        <div className="row small transcript-tools" role="toolbar" aria-label="Transcript tools">
                          <button className="btn ghost" aria-pressed={editing} onClick={() => setEditing(!editing)}>{editing ? "Done editing" : "Edit text"}</button>
                          <button className="btn ghost" aria-pressed={panel === "replace"} onClick={() => setPanel(panel === "replace" ? null : "replace")}>Find and replace</button>
                          <button className="btn ghost" aria-pressed={panel === "speakers"} onClick={() => setPanel(panel === "speakers" ? null : "speakers")}>Speakers</button>
                          <button className="btn ghost" aria-pressed={panel === "history"} onClick={() => setPanel(panel === "history" ? null : "history")}>History</button>
                          <ExportMenu onExport={(format) => void exportAs(format)} viewLabel={exportView ? VIEW_LABELS[exportView] : null}
                            onPack={current.screen_count !== undefined || packServer ? (format, withAudio) => void packAs(format, withAudio) : undefined} />
                        </div>
                      )}
                      {panel === "replace" && <ReplaceBar onReplace={replaceAll} onClose={() => setPanel(null)} />}
                      {panel === "speakers" && <SpeakersPanel labels={speakerLabels(transcript.segments)} names={speakerNames} hasClient={!!current.client} onSave={saveSpeakers} onClose={() => setPanel(null)} />}
                      {panel === "history" && <HistoryPanel transcriptId={transcript.id} edited={!!transcript.edited_at} onRestore={restore} onClose={() => setPanel(null)} />}
                      {!readOnly && <LearnOfferBar offer={learn[0] ?? null} onAnswer={(yes) => void answerLearn(yes)} />}
                      {learn.length === 0 && !readOnly && <VocabularyHint terms={vocabTerms} clientName={current.client?.name ?? null} onAdd={(t) => void addToVocabulary(t)} onDismiss={() => setVocabTerms([])} />}
                      {openSuggestions.length > 0 && showAs === "original" && !editing && !readOnly && (
                        <div className="suggestions-note" role="status">
                          <SparkleIcon size={15} />
                          <span>{openSuggestions.length === 1 ? "1 suggested fix" : `${openSuggestions.length} suggested fixes`}: words that may have been misheard are underlined.</span>
                          <button type="button" className="btn text compact" onClick={() => setOpenSuggestion(openSuggestions[0].id)}>Review</button>
                        </div>
                      )}
                      {transcript.segments.length === 0 && <div className="empty">The transcript is empty: no speech was detected.</div>}
                      <div className="segments" ref={segmentsRef} lang={showAs === "original" ? normalizeLanguage(transcript.language) ?? undefined : showAs === "urdu" ? "ur" : undefined}>
                        {transcript.segments.map((s, i) => {
                          const inView = viewText(i);
                          const name = speakerName(speakerNames, s.speaker);
                          // A turn shows its speaker once: following lines of the same speaker keep the label for screen
                          // readers but hide it on screen (.cont), which groups the lines into a speaker block.
                          const cells = (
                            <>
                              <span className="seg-avatar" aria-hidden="true">{initialOf(name)}</span>
                              <span className="seg-time">{timestamp(s.start_ms)}</span>
                              <span className={`seg-spk spk-${s.channel === 2 ? 2 : 1}`}>{name}</span>
                              <span className={s.uncertain && inView === null ? "seg-text uncertain" : "seg-text"} dir="auto" title={s.uncertain && inView === null ? UNSURE_LINE : undefined}>
                                {lineContent(i)}
                                {inView !== null && showAs !== "original" && views?.kept?.[showAs]?.includes(i) && <span className="seg-kept muted" title="This line could not be written in the view, so it shows the words as transcribed."> · original words</span>}
                                {s.check && inView === null && <span className="seg-check" dir="auto"><b>{s.check.label} heard:</b> {s.check.text}</span>}
                              </span>
                            </>
                          );
                          const className = `segment ch-${s.channel === 2 ? 2 : 1} ${i === activeSegment ? "active" : ""} ${continuesTurn(transcript.segments, i) ? "cont" : ""}`.replace(/\s+/g, " ").trim();
                          const playing = i === activeSegment ? "true" : undefined;
                          const fix = pendingFix?.index === i ? (
                            <SuggestionPop key={`fix-${i}`} suggestion={pendingFix} busy={suggestBusy} onDecide={(accept) => void decideSuggestion(pendingFix, accept)} onClose={() => setOpenSuggestion(null)} />
                          ) : null;
                          if (editing) {
                            return (
                              <div key={`${i}-${s.text}`} className={`${className} editing`} data-seg={i}>
                                <span className="seg-avatar" aria-hidden="true">{initialOf(name)}</span>
                                <span className="seg-time">{timestamp(s.start_ms)}</span>
                                <span className={`seg-spk spk-${s.channel === 2 ? 2 : 1}`}>{name}</span>
                                <textarea className="input seg-edit" dir="auto" rows={2} defaultValue={s.text} aria-label={`Line at ${timestamp(s.start_ms)}`}
                                  onBlur={(e) => void saveLine(i, e.target.value)}
                                  onKeyDown={(e) => { if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) (e.target as HTMLTextAreaElement).blur(); }} />
                              </div>
                            );
                          }
                          // A row that seeks is a real button: it takes focus and Enter / Space activate it (A6.DU7).
                          const line = playbackUrl ? (
                            <button key={i} type="button" className={`${className} seekable`} aria-current={playing} data-seg={i} onClick={() => seek(s.start_ms)}>{cells}</button>
                          ) : (
                            <div key={i} className={className} data-seg={i}>{cells}</div>
                          );
                          const shots = screensAfter.get(i);
                          const below = shots ? <LineScreens key={`screens-${i}`} screens={shots} onOpen={openScreen} /> : null;
                          return fix || below ? [line, fix, below] : line;
                        })}
                      </div>
                    </>
                  )
                ) : (
                  transcriptError ? (
                    <div className="notice error" role="alert" style={{ marginTop: 12 }}>
                      Couldn't load the transcript. Check the connection and try again.{" "}
                      <button type="button" className="btn compact" onClick={() => setTranscriptAttempt((n) => n + 1)}>Retry</button>
                    </div>
                  ) : (
                    <div className="empty">{isTranscribing(finalStatus) ? transcribingLine(current.transcript?.final_progress) : finalStatus ? `Transcript ${finalStatus.replace("_", " ")}` : "Transcript not available yet."}</div>
                  )
                )}
                {current.client && !readOnly && <CallNotesSection key={current.id} sessionId={current.id} clientName={current.client.name} onSeek={seek} notify={notify} />}
              </div>
              {playbackUrl && (
                <PlayerDock playing={playing} nowMs={nowMs} durationMs={durationMs} onToggle={togglePlay} onSkip={skipBy}
                  below={<PlayerControls rate={rate} onRate={changeRate} skipSilence={skipSilence} onSkipSilence={setSkipSilence} follow={follow} onFollow={setFollow} />}>
                  <audio ref={audioRef} className="player" preload="metadata" src={playbackUrl} onTimeUpdate={onTimeUpdate} onSeeked={onTimeUpdate} onError={onPlaybackError}
                    onLoadedMetadata={onLoaded} onDurationChange={() => setMediaSeconds(audioRef.current?.duration ?? null)} onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)} onEnded={() => setPlaying(false)} data-testid="player" />
                  <Waveform segments={transcript?.segments ?? []} bookmarks={bookmarks} durationMs={durationMs} nowMs={nowMs} onSeek={seek} />
                </PlayerDock>
              )}
            </>
          )}
        </div>
      </div>
      </div>
      </div>
      {screenOpen !== null && screens?.[screenOpen] && (
        <ScreenViewer screens={screens} index={screenOpen} onIndex={setScreenOpen} onClose={() => setScreenOpen(null)} onExpired={reloadScreens}
          onPlay={playbackUrl ? (s) => { setScreenOpen(null); seek(s.at_ms); } : undefined}
          onDelete={(s) => void deleteScreen(s)} />
      )}
      {moveFor && (
        <MoveRecordingsDialog ids={moveFor.ids} title={moveFor.title} current={moveFor.current} projects={folders} notify={notify}
          onClose={() => setMoveFor(null)} onProjectCreated={addClient} />
      )}
    </>
  );
}
