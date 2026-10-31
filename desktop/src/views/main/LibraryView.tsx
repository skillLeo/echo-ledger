import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type ClientFolder, type ExportFormat, type LearnOffer, type LibraryItem, type SearchResult, type TranscriptDetail, type TranscriptionLanguage, type TranscriptSuggestion, type TranscriptView, type TranscriptViewsState } from "../../lib/ipc";
import { activeIndex, activeWord, continuesTurn, findMatches, hasUncertainWords, KEY_SECONDS, markParts, newTerms, playerKey, skipTarget, snippetParts, speakerLabels, speakerName, suggestionSpan, uncertainWord } from "../../lib/player";
import { ExportMenu, FindBar, HistoryPanel, LearnOfferBar, PlayerControls, PlayerDock, ReplaceBar, SharePanel, SpeakersPanel, SuggestionPop, VocabularyHint, Waveform } from "./TranscriptTools";
import { copiedTranscript, DEFAULT_UNSURE, formatDuration, formatWhen, providerName, timestamp, type UnsureMarkers } from "../../lib/format";
import { bookmarksOf, filterByTag, groupItems, itemState, LIVE_STATUS_MS, libraryTags, needsLiveStatus, searchItems, SORT_LABELS, sortItems, type Bookmark, type SortKey } from "../../lib/library";
import { SourceAppBadge, SourceDeviceBadge } from "../../components/SourceBadge";
import { AlertIcon, AppTileIcon, appKind, CopyIcon, DeviceBadgeIcon, DownloadIcon, ImportIcon, LibraryIcon, PinIcon, RefreshIcon, SearchIcon, ShareIcon, SparkleIcon, TrashIcon } from "../../components/Icons";
import { NO_AUDIO_HINT } from "../../lib/pending";
import { BookmarkIcon } from "../../components/ControlIcons";
import { LibraryQueue, type PendingFocus } from "./LibraryQueue";
import { filterByClient, filterByLabel, folderBadge, foldersOf, inSmartView, libraryLabels, smartViewCounts } from "../../lib/organise";
import { BinPanel, BulkBar, OrganisePanel, RowMenu, TitleEditor } from "./LibraryOrganise";
import { useWindowShown } from "../../lib/visibility";
import { activeFilterCount, LibraryFilters, NO_FILTERS, type FilterState, type Source } from "./LibraryFilters";
import { MAIN_LANGUAGES, MORE_LANGUAGES, engineLine, englishOnlyRisk, languageLabel, menuLabel, normalizeLanguage, partsText, scriptWarning, transcribingText } from "../../lib/languages";
import { CallNotesSection } from "./CallNotes";

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
export function LibraryView({ notify, focus = null, onFocusHandled, openRequest = null, initialSelected = null, onSelectedChange, onImport, onOpenClient }: {
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
}) {
  const [items, setItems] = useState<LibraryItem[]>([]);
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
  const [sort, setSort] = useState<SortKey>("newest");
  const [selected, setSelected] = useState<string | null>(initialSelected);
  useEffect(() => onSelectedChange?.(selected), [selected, onSelectedChange]);
  const [transcript, setTranscript] = useState<TranscriptDetail | null>(null);
  // How unsure words are marked in copied text (the console's choice, plan A4).
  const [unsure, setUnsure] = useState<UnsureMarkers>(DEFAULT_UNSURE);
  useEffect(() => {
    api.unsureMarkers().then((m) => { if (m) setUnsure({ ...DEFAULT_UNSURE, ...m }); }).catch(() => undefined);
  }, []);
  const [playbackUrl, setPlaybackUrl] = useState<string | null>(null);
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
  const folders = useMemo(() => foldersOf(items, clients), [items, clients]);
  const addClient = (client: ClientFolder) => setClients((list) => [...list.filter((c) => c.id !== client.id), client]);
  const filtered = useMemo(() => sortItems(searchItems(filterByClient(filterByLabel(applyFilters(items, filters), label), folder), query), sort), [items, filters, label, folder, query, sort]);
  const groups = useMemo(() => groupItems(filtered, sort), [filtered, sort]);
  // A recording opened from a transcript match stays open even when its title does not match the typed words.
  const [hitSelected, setHitSelected] = useState<string | null>(null);
  const current = filtered.find((i) => i.id === selected) ?? (hitSelected !== null && hitSelected === selected ? items.find((i) => i.id === selected && !i.deleted) : undefined) ?? null;
  // The full client list (folders without recordings too) is fetched once, when a recording is opened or selecting starts.
  const needClients = !!current || selecting;
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
  useEffect(() => {
    setShowAs("original");
    setViews(null);
    // Open in the default view (console setting) when it is made for this recording.
    const summary = transcript?.views;
    if (summary?.status === "ready" && summary.default && summary.default !== "original" && summary.available.includes(summary.default)) void chooseView(summary.default);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [transcriptId]);
  useEffect(() => {
    if (editing) setShowAs("original");
  }, [editing]);
  // While the server is writing the views, ask again every few seconds.
  useEffect(() => {
    if (!transcriptId || showAs === "original" || views?.status !== "making") return;
    const timer = window.setTimeout(() => {
      api.transcriptViews(transcriptId).then((v) => setViews(v)).catch(() => undefined);
    }, VIEWS_POLL_MS);
    return () => window.clearTimeout(timer);
  }, [transcriptId, showAs, views]);
  const chooseView = async (view: "original" | TranscriptView) => {
    setShowAs(view);
    if (view === "original" || !transcriptId || views?.status === "ready") return;
    try {
      let state = await api.transcriptViews(transcriptId);
      if (state.status === "none" || state.status === "failed") state = await api.makeTranscriptViews(transcriptId);
      setViews(state);
    } catch (e) {
      notify(errorMessage(e), true);
      setShowAs("original");
    }
  };
  const viewText = (i: number): string | null => (showAs !== "original" && views?.status === "ready" ? views[showAs]?.[i] ?? null : null);
  // Unsure words go into the copy as "[?word]" (or the console's style); Copy for AI starts with the note line.
  const copy = async (withTimestamps: boolean, forAi = false) => {
    if (!transcript) return;
    try {
      const segments = transcript.segments.map((s) => ({ ...s, speaker: speakerName(speakerNames, s.speaker) }));
      await navigator.clipboard.writeText(copiedTranscript(segments, viewText, withTimestamps, unsure, forAi));
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
    const action = playerKey(e.key, target.tagName, target.isContentEditable);
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
    // An empty title clears it: the server says what the recording is called then.
    if (title) replaceItem(item.id, (i) => ({ ...i, title }));
    try {
      const answer = await api.organiseSession(item.id, { title });
      if (answer?.data?.id === item.id) replaceItem(item.id, () => answer.data);
      notify(title ? "Renamed" : "Title cleared");
    } catch (e) {
      replaceItem(item.id, (i) => ({ ...i, title: item.title }));
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
    const fixes = openSuggestions.filter((f) => f.index === i).map((f) => ({ f, span: suggestionSpan(s.text, f.from) })).filter((x) => x.span)
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
  const durationMs = current && transcript ? Math.max(current.duration_ms ?? 0, ...transcript.segments.map((s) => s.end_ms)) : current?.duration_ms ?? 0;
  const pendingFix = openSuggestions.find((f) => f.id === openSuggestion) ?? null;
  return (
    <>
      <div className="page-head">
        <div>
          <h1 className="page-title">Library</h1>
          <p className="page-sub">{total === 0 ? "Recordings from your phone, this computer and the web will appear here." : `${total} recording${total === 1 ? "" : "s"} from your phone, this computer and the web.`}</p>
        </div>
        <div className="spacer" />
        {onImport && (
          <button type="button" className="btn" onClick={onImport} title="Voice notes, audio or video files. You can also drop them anywhere in this window.">
            <ImportIcon size={16} />Import
          </button>
        )}
        <button className="btn ghost" onClick={refresh} disabled={loading}><RefreshIcon size={16} />{loading ? "Syncing…" : "Sync"}</button>
      </div>
      <div className="toolbar">
        <label className="searchbox">
          <SearchIcon size={18} />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search recordings and transcripts" aria-label="Search recordings" />
          {query && <button className="clear" onClick={() => setQuery("")} aria-label="Clear search">×</button>}
        </label>
      </div>
      <div className="chips library-chips">
        <LibraryFilters state={filters} onChange={setFilters} counts={viewCounts} tags={tags} showBookmarks={anyBookmarks || filters.tags.size > 0} />
        {(folders.length > 0 || folder !== null) && (
          <select className={`chip ${folder !== null ? "active" : ""}`} value={folder ?? "*"} onChange={(e) => setFolder(e.target.value === "*" ? null : e.target.value)} aria-label="Client folder">
            <option value="*">All clients</option>
            <option value="">No client</option>
            {folders.map((cl) => <option key={cl.id} value={cl.id}>{`${folderBadge(cl)} · ${cl.name}`}</option>)}
          </select>
        )}
        {(labels.length > 0 || label !== null) && (
          <select className={`chip ${label !== null ? "active" : ""}`} value={label ?? ""} onChange={(e) => setLabel(e.target.value || null)} aria-label="Label">
            <option value="">Any label</option>
            {labels.map((l) => <option key={l} value={l}>{l}</option>)}
          </select>
        )}
        <select className="chip" value={sort} onChange={(e) => setSort(e.target.value as SortKey)} aria-label="Sort recordings">
          {(Object.keys(SORT_LABELS) as SortKey[]).map((k) => <option key={k} value={k}>{SORT_LABELS[k]}</option>)}
        </select>
        <span className="spacer" />
        <button type="button" className="chip" aria-pressed={selecting} onClick={() => (selecting ? endSelecting() : setSelecting(true))}>{selecting ? "Cancel selection" : "Select"}</button>
        <button type="button" className="chip" aria-pressed={binOpen} onClick={() => setBinOpen(!binOpen)}>Recently deleted</button>
      </div>
      <div className="library">
        <div className="list" data-testid="library-list">
          {binOpen && <BinPanel notify={notify} onClose={() => setBinOpen(false)} />}
          {selecting && <BulkBar ids={pickedIds} clients={folders} knownLabels={labels} notify={notify} onDone={endSelecting} onClientCreated={addClient} onSelectAll={() => setPicked(new Set(filtered.map((i) => i.id)))} />}
          {syncError && (
            <div className="notice error sync-error" role="alert">
              <span>Couldn't sync with the server. Showing what is saved on this computer.</span>
              <button type="button" className="btn compact" onClick={refresh} disabled={loading}>Retry</button>
            </div>
          )}
          <LibraryQueue notify={notify} focus={focus} onFocusHandled={onFocusHandled} />
          {found.length > 0 && (
            <div className="group search-hits" role="region" aria-label="Matches in transcripts">
              <div className="group-label">In transcripts</div>
              {found.map((r) => (
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
          {!loaded && items.length === 0 && (
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
          {loaded && filtered.length === 0 && (
            total === 0 && !query && activeFilterCount(filters) === 0 && label === null && folder === null ? (
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
          {groups.map((group) => (
            <div key={group.label ?? "all"} className="group">
              {group.label && <div className="group-label">{group.label}</div>}
              {group.items.map((item) => {
                const state = itemState(item);
                // Like the phone (v1.4): length, client and bookmarks; the language is a filter and lives on the recording page.
                const sub = [formatDuration(item.duration_ms) || null, item.client?.name,
                  item.marker_count ? `${item.marker_count} bookmark${item.marker_count === 1 ? "" : "s"}` : null].filter(Boolean).join(" · ");
                const avatar = (
                  <span className={`avatar ${item.source_device ?? "web"} app-${appKind(item.source_app)}`}>
                    <AppTileIcon app={item.source_app} />
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
                    <button type="button" className={`item ${item.id === selected ? "selected" : ""} ${selecting && picked.has(item.id) ? "picked" : ""}`}
                      aria-current={!selecting && item.id === selected ? "true" : undefined} aria-pressed={selecting ? picked.has(item.id) : undefined}
                      onClick={() => (selecting ? togglePicked(item.id) : setSelected(item.id))}>
                      <div className="row">
                        {avatar}
                        <div className="item-main">
                          <div className="item-line">
                            <span className="item-title">{item.title}</span>
                            {item.pinned && <span className="pin-star" aria-label="Pinned"><PinIcon size={14} /></span>}
                            <span className="item-time">{rowTime(item.started_at)}</span>
                          </div>
                          <div className="item-sub">
                            {state === "ready" ? <span>{sub}</span> : (
                              <span className={`pill ${state}`}>
                                {state === "processing" ? <span className="spinner" aria-hidden="true" /> : state === "failed" ? <AlertIcon size={14} /> : null}
                                {STATE_LABEL[state]}
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
                        onPin={() => void togglePin(item)}
                        onDelete={() => void remove(item.id)} />
                    )}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
        <div className="detail card">
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
                      <SourceAppBadge app={current.source_app} label={current.source_app_label} />
                      <SourceDeviceBadge source={current.source_device} label={current.source_device_label} />
                    </div>
                    <span className="spacer" />
                    {/* As on the phone's recording page: quiet icon buttons, each named for screen readers and the tooltip. */}
                    {transcript && (
                      <button type="button" className="btn ghost icon-only" aria-label="Find in this transcript" aria-pressed={findOpen} title="Find in this transcript (Ctrl+F)" onClick={() => setFindOpen(!findOpen)}><SearchIcon size={18} /></button>
                    )}
                    {playbackUrl && <button type="button" className="btn ghost icon-only" aria-label="Save audio" onClick={() => void saveAudio()} title="Save the recording's audio file on this computer"><DownloadIcon size={18} /></button>}
                    <button className="btn ghost icon-only" aria-label="Share" title="Share a view-only link" aria-pressed={panel === "share"} onClick={() => setPanel(panel === "share" ? null : "share")}><ShareIcon size={18} /></button>
                    <button className="btn danger ghost icon-only" aria-label="Delete" title="Delete this recording" onClick={() => remove(current.id)}><TrashIcon size={18} /></button>
                  </div>
                  {renaming?.id === current.id && renaming.where === "detail" ? (
                    <TitleEditor className="detail-title-input" initial={current.title ?? ""} onSave={(t) => void rename(current, t)} onCancel={() => setRenaming(null)} />
                  ) : (
                    <h2 className="detail-title">
                      <button type="button" className="title-button" title="Click to rename" onClick={() => setRenaming({ id: current.id, where: "detail" })}>{current.title}</button>
                    </h2>
                  )}
                  <div className="detail-meta">
                    {formatWhen(current.started_at)} · {formatDuration(current.duration_ms)}
                    {current.client ? <> · {onOpenClient ? <button type="button" className="btn text compact" style={{ height: 22, padding: "0 4px" }} onClick={() => onOpenClient(current.client!.id)}>{current.client.name}</button> : current.client.name}</> : null}
                  </div>
                  {engineLine(current.transcript) && <div className="small muted" data-testid="engine-line">Transcribed in {engineLine(current.transcript)}</div>}
                  <OrganisePanel item={current} clients={folders} knownLabels={labels} notify={notify} onClientCreated={addClient}>
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
                  </OrganisePanel>
                  {englishRisk && englishWarned && (
                    <div className="notice" role="alert" data-testid="english-only-warning">
                      This recording has Urdu in it. English only makes the engine translate the Urdu into English. Choose Urdu (with English words) to keep every word, or click Re-transcribe anyway.
                    </div>
                  )}
                  {scriptWarning(current.transcript?.script_warning) && <div className="notice" role="status">{scriptWarning(current.transcript?.script_warning)}</div>}
                </div>
                {panel === "share" && <SharePanel sessionId={current.id} hasAudio={!!playbackUrl} notify={notify} onClose={() => setPanel(null)} />}
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
                    </div>
                  ) : isTranscribing(transcript.status) ? (
                    // The library's progress is the fresher one: live status re-reads it every few seconds.
                    <div className="empty-state"><span className="spinner" aria-hidden="true" /><p>{transcribingText(current.transcript?.final_progress ?? transcript.progress)}</p></div>
                  ) : (
                    <>
                      <div className="transcript-bar">
                        <span className="small muted engine">{transcript.kind === "final" ? `Final transcript · ${providerName(transcript.provider)}${transcript.model ? ` · ${transcript.model}` : ""}${transcript.edited_at ? " · edited" : ""}` : `Live captions (final transcript pending${partsText(current.transcript?.final_progress) ? ` · ${partsText(current.transcript?.final_progress)}` : ""})`}</span>
                        {(transcript.views?.available.length ?? 0) > 0 && transcript.kind === "final" && transcript.status === "completed" && transcript.segments.length > 0 && (
                          <span className="segmented view-switch" role="group" aria-label="Show as">
                            {VIEW_ORDER.filter((v) => transcript.views!.available.includes(v)).map((v) => (
                              <button key={v} type="button" lang={v === "urdu" ? "ur" : undefined} aria-pressed={showAs === v} onClick={() => void chooseView(v)}>{VIEW_LABELS[v]}</button>
                            ))}
                            <button type="button" aria-pressed={showAs === "original"} onClick={() => void chooseView("original")}>Original</button>
                          </span>
                        )}
                      </div>
                      <div className="copy-row">
                        <button className="btn compact" onClick={() => copy(false)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy text</button>
                        <button className="btn compact" onClick={() => copy(true)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy with timestamps</button>
                        <button className="btn text compact" onClick={() => copy(false, true)} disabled={transcript.segments.length === 0}
                          title="Copies the text with unsure words marked [?word] and a first line that tells an AI what the marks mean"><SparkleIcon size={14} />Copy for AI</button>
                      </div>
                      {showAs !== "original" && (
                        <div className="small muted" role="status" data-testid="view-status" style={{ marginTop: 6 }}>
                          {views?.status === "ready"
                            ? `${VIEW_LABELS[showAs]} view${views.model ? ` by ${views.model}` : ""}. The transcript itself is unchanged.`
                            : views?.status === "failed"
                              ? `The view could not be made: ${views.error ?? "try again later."}`
                              : `Writing the ${VIEW_LABELS[showAs]} view… this takes a minute or two.`}
                        </div>
                      )}
                      {transcript.kind === "final" && transcript.status === "completed" && transcript.segments.length > 0 && (
                        <div className="row small transcript-tools" role="toolbar" aria-label="Transcript tools">
                          <button className="btn ghost" aria-pressed={editing} onClick={() => setEditing(!editing)}>{editing ? "Done editing" : "Edit text"}</button>
                          <button className="btn ghost" aria-pressed={panel === "replace"} onClick={() => setPanel(panel === "replace" ? null : "replace")}>Find and replace</button>
                          <button className="btn ghost" aria-pressed={panel === "speakers"} onClick={() => setPanel(panel === "speakers" ? null : "speakers")}>Speakers</button>
                          <button className="btn ghost" aria-pressed={panel === "history"} onClick={() => setPanel(panel === "history" ? null : "history")}>History</button>
                          <ExportMenu onExport={(format) => void exportAs(format)} viewLabel={exportView ? VIEW_LABELS[exportView] : null} />
                        </div>
                      )}
                      {panel === "replace" && <ReplaceBar onReplace={replaceAll} onClose={() => setPanel(null)} />}
                      {panel === "speakers" && <SpeakersPanel labels={speakerLabels(transcript.segments)} names={speakerNames} hasClient={!!current.client} onSave={saveSpeakers} onClose={() => setPanel(null)} />}
                      {panel === "history" && <HistoryPanel transcriptId={transcript.id} edited={!!transcript.edited_at} onRestore={restore} onClose={() => setPanel(null)} />}
                      <LearnOfferBar offer={learn[0] ?? null} onAnswer={(yes) => void answerLearn(yes)} />
                      {learn.length === 0 && <VocabularyHint terms={vocabTerms} clientName={current.client?.name ?? null} onAdd={(t) => void addToVocabulary(t)} onDismiss={() => setVocabTerms([])} />}
                      {openSuggestions.length > 0 && showAs === "original" && !editing && (
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
                          return fix ? [line, fix] : line;
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
                    <div className="empty">{isTranscribing(finalStatus) ? transcribingText(current.transcript?.final_progress) : finalStatus ? `Transcript ${finalStatus.replace("_", " ")}` : "Transcript not available yet."}</div>
                  )
                )}
                {current.client && <CallNotesSection key={current.id} sessionId={current.id} clientName={current.client.name} onSeek={seek} notify={notify} />}
              </div>
              {playbackUrl && (
                <PlayerDock playing={playing} nowMs={nowMs} durationMs={durationMs} onToggle={togglePlay} onSkip={skipBy}
                  below={<PlayerControls rate={rate} onRate={changeRate} skipSilence={skipSilence} onSkipSilence={setSkipSilence} follow={follow} onFollow={setFollow} />}>
                  <audio ref={audioRef} className="player" preload="metadata" src={playbackUrl} onTimeUpdate={onTimeUpdate} onSeeked={onTimeUpdate} onError={onPlaybackError}
                    onLoadedMetadata={onLoaded} onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)} onEnded={() => setPlaying(false)} data-testid="player" />
                  <Waveform segments={transcript?.segments ?? []} bookmarks={bookmarks} durationMs={durationMs} nowMs={nowMs} onSeek={seek} />
                </PlayerDock>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
}
