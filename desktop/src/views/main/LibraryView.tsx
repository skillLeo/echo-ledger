import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type ClientFolder, type ExportFormat, type LibraryItem, type SearchResult, type TranscriptDetail, type TranscriptionLanguage, type TranscriptView, type TranscriptViewsState } from "../../lib/ipc";
import { activeIndex, activeWord, continuesTurn, hasUncertainWords, KEY_SECONDS, newTerms, playerKey, skipTarget, snippetParts, speakerLabels, speakerName, uncertainWord } from "../../lib/player";
import { ExportMenu, HistoryPanel, PlayerControls, ReplaceBar, SharePanel, SpeakersPanel, Timeline, VocabularyHint } from "./TranscriptTools";
import { copiedTranscript, DEFAULT_UNSURE, formatDuration, formatWhen, providerName, timestamp, type UnsureMarkers } from "../../lib/format";
import { bookmarksOf, filterByTag, groupItems, itemState, libraryTags, searchItems, SORT_LABELS, sortItems, type Bookmark, type SortKey } from "../../lib/library";
import { SourceAppBadge, SourceDeviceBadge } from "../../components/SourceBadge";
import { CopyIcon, RefreshIcon, SearchIcon, SourceAppIcon, TrashIcon } from "../../components/Icons";
import { NO_AUDIO_HINT } from "../../lib/pending";
import { BookmarkIcon } from "../../components/ControlIcons";
import { LibraryQueue, type PendingFocus } from "./LibraryQueue";
import { filterByClient, filterByLabel, folderBadge, foldersOf, inSmartView, libraryLabels, smartViewCounts } from "../../lib/organise";
import { BinPanel, BulkBar, OrganisePanel } from "./LibraryOrganise";
import { activeFilterCount, LibraryFilters, NO_FILTERS, type FilterState, type Source } from "./LibraryFilters";
import { MAIN_LANGUAGES, MORE_LANGUAGES, engineLine, englishOnlyRisk, languageLabel, menuLabel, normalizeLanguage, partsText, scriptWarning, transcribingText } from "../../lib/languages";

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

const STATE_LABEL = { ready: "Ready", processing: "Processing", failed: "Failed", no_audio: "No audio" } as const;

/** Transcript states in which the provider is still working: never shown as an empty transcript (A6.DU3). */
const IN_FLIGHT = new Set(["pending", "submitted", "processing"]);
const isTranscribing = (status?: string | null): boolean => !!status && IN_FLIGHT.has(status);

const PLAYBACK_EXPIRED = "Playback link expired. Open the recording again.";
const playbackUrlOf = (detail: Record<string, unknown>): string | null => (typeof detail.playback_url === "string" ? detail.playback_url : null);

/**
 * Unified library: every recording from every device, searchable by title and transcript text. Recordings waiting
 * for a decision and uploads that need the user sit at the top of the list; `focus` brings one of them into view.
 */
/** How often the Library asks whether the Show as views are written yet. */
const VIEWS_POLL_MS = 5_000;
/** Show as: the views in the order the switch shows them, and their names. */
const VIEW_ORDER: TranscriptView[] = ["roman", "english", "urdu"];
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

export function LibraryView({ notify, focus = null, onFocusHandled, openRequest = null, initialSelected = null, onSelectedChange }: {
  notify: (t: string, e?: boolean) => void;
  focus?: PendingFocus | null;
  onFocusHandled?: () => void;
  /** Open a recording at a moment (from the command palette). */
  openRequest?: { id: string; ms: number; seq: number } | null;
  /** The recording that was open when the last main window was closed (C9). */
  initialSelected?: string | null;
  onSelectedChange?: (id: string | null) => void;
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
  const [speakerNames, setSpeakerNames] = useState<Record<string, string>>({});
  const [rate, setRate] = useState(1);
  const [skipSilence, setSkipSilence] = useState(false);
  const [follow, setFollow] = useState(true);
  const [editing, setEditing] = useState(false);
  const [panel, setPanel] = useState<"replace" | "speakers" | "history" | "share" | null>(null);
  const [vocabTerms, setVocabTerms] = useState<string[]>([]);
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
    setEditing(false);
    setPanel(null);
    setVocabTerms([]);
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

  // Show as (since v1.2): the Roman Urdu or English view instead of the original words, once the server has made it.
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

  /** Space / K / J / L / arrows while the transcript area has focus. */
  const onPlayerKey = (e: React.KeyboardEvent) => {
    const audio = audioRef.current;
    const target = e.target as HTMLElement;
    const action = playerKey(e.key, target.tagName, target.isContentEditable);
    if (!audio || !action) return;
    e.preventDefault();
    if (action === "toggle") {
      if (audio.paused) void audio.play().catch(() => undefined);
      else audio.pause();
      return;
    }
    audio.currentTime = Math.max(0, audio.currentTime + KEY_SECONDS[action]);
    onTimeUpdate();
  };

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
      setTranscript(await api.editTranscript(transcript.id, index, text));
      setVocabTerms(newTerms(before, text));
    } catch (e) {
      notify(errorMessage(e), true);
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

  const exportAs = async (format: ExportFormat) => {
    if (!transcript) return;
    try {
      const path = await api.exportTranscript(transcript.id, format, true, true, showAs !== "original" && views?.status === "ready" ? showAs : null);
      notify(format === "html" ? "Opened the print page in your browser: choose Save as PDF there" : `Saved to ${path}`);
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
      setSelected(null);
      setItems(await api.library());
      notify("Deleted");
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };

  const total = items.filter((i) => !i.deleted).length;
  return (
    <>
      <div className="row">
        <div>
          <h1 className="page-title">Library</h1>
          <p className="page-sub">{total === 0 ? "Recordings from your phone, this computer and the web will appear here." : `${total} recording${total === 1 ? "" : "s"} from your phone, this computer and the web.`}</p>
        </div>
        <div className="spacer" />
        <button className="btn" onClick={refresh} disabled={loading}><RefreshIcon size={16} />{loading ? "Syncing…" : "Sync"}</button>
      </div>
      <div className="toolbar">
        <label className="searchbox">
          <SearchIcon size={16} />
          <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search titles and transcripts" aria-label="Search recordings" />
          {query && <button className="clear" onClick={() => setQuery("")} aria-label="Clear search">×</button>}
        </label>
        <select className="select" value={sort} onChange={(e) => setSort(e.target.value as SortKey)} aria-label="Sort recordings">
          {(Object.keys(SORT_LABELS) as SortKey[]).map((k) => <option key={k} value={k}>{SORT_LABELS[k]}</option>)}
        </select>
        {(folders.length > 0 || folder !== null) && (
          <select className="select" value={folder ?? "*"} onChange={(e) => setFolder(e.target.value === "*" ? null : e.target.value)} aria-label="Client folder">
            <option value="*">All clients</option>
            <option value="">No client</option>
            {folders.map((cl) => <option key={cl.id} value={cl.id}>{`${folderBadge(cl)} · ${cl.name}`}</option>)}
          </select>
        )}
        {(labels.length > 0 || label !== null) && (
          <select className="select" value={label ?? ""} onChange={(e) => setLabel(e.target.value || null)} aria-label="Label">
            <option value="">Any label</option>
            {labels.map((l) => <option key={l} value={l}>{l}</option>)}
          </select>
        )}
        <LibraryFilters state={filters} onChange={setFilters} counts={viewCounts} tags={tags} showBookmarks={anyBookmarks || filters.tags.size > 0} />
        <button type="button" className="btn ghost" aria-pressed={selecting} onClick={() => (selecting ? endSelecting() : setSelecting(true))}>{selecting ? "Cancel selection" : "Select"}</button>
        <button type="button" className="btn ghost" aria-pressed={binOpen} onClick={() => setBinOpen(!binOpen)}>Recently deleted</button>
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
                <div key={i} className="item skeleton-item" aria-hidden="true">
                  <span className="skeleton" style={{ width: "62%", height: 14 }} />
                  <span className="skeleton" style={{ width: "38%", height: 10 }} />
                </div>
              ))}
            </div>
          )}
          {loaded && filtered.length === 0 && (
            <div className="empty">{filters.views.size === 1 && filters.views.has("waiting") ? "Nothing is waiting here. Recordings waiting for Keep or Discard on this computer are listed at the top." : query || activeFilterCount(filters) > 0 || label !== null || folder !== null ? "Nothing matches. Try a different search or filter." : "No recordings yet."}</div>
          )}
          {groups.map((group) => (
            <div key={group.label ?? "all"} className="group">
              {group.label && <div className="group-label">{group.label}</div>}
              {group.items.map((item) => {
                const state = itemState(item);
                return (
                  <button key={item.id} type="button" className={`item ${item.id === selected ? "selected" : ""} ${selecting && picked.has(item.id) ? "picked" : ""}`}
                    aria-current={!selecting && item.id === selected ? "true" : undefined} aria-pressed={selecting ? picked.has(item.id) : undefined}
                    onClick={() => (selecting ? togglePicked(item.id) : setSelected(item.id))}>
                    <div className="row">
                      <span className={`avatar ${item.source_device ?? "web"}`}><SourceAppIcon app={item.source_app} size={18} /></span>
                      <div className="item-main">
                        <div className="item-title">{item.pinned && <span className="pin-star" aria-label="Pinned">★ </span>}{item.title}</div>
                        <div className="small muted">{formatWhen(item.started_at)}{item.duration_ms ? ` · ${formatDuration(item.duration_ms)}` : ""}{item.client ? ` · ${item.client.name}` : ""}{item.marker_count ? ` · ${item.marker_count} bookmark${item.marker_count === 1 ? "" : "s"}` : ""}</div>
                      </div>
                      {item.language_chip && item.language_chip !== "Auto" && <span className="lang-chip" title="Language">{item.language_chip}</span>}
                      <span className={`pill ${state}`}>{STATE_LABEL[state]}</span>
                    </div>
                    {item.transcript?.preview && <div className="item-preview">{item.transcript.preview}</div>}
                    {(item.labels ?? []).length > 0 && <div className="item-labels">{item.labels!.map((l) => <span key={l} className="label-chip small">{l}</span>)}</div>}
                  </button>
                );
              })}
            </div>
          ))}
        </div>
        <div className="detail card" onKeyDown={onPlayerKey}>
          {!current && <div className="empty">Select a recording to read its transcript.</div>}
          {current && (
            <>
              <div className="row">
                <SourceDeviceBadge source={current.source_device} label={current.source_device_label} />
                <SourceAppBadge app={current.source_app} label={current.source_app_label} />
                <span className="spacer" />
                <button className="btn ghost" aria-pressed={panel === "share"} onClick={() => setPanel(panel === "share" ? null : "share")}>Share</button>
                <button className="btn danger ghost" onClick={() => remove(current.id)}><TrashIcon size={15} />Delete</button>
              </div>
              <h2 className="detail-title">{current.title}</h2>
              <div className="small muted">{formatWhen(current.started_at)} · {formatDuration(current.duration_ms)}{current.client ? ` · ${current.client.name}` : ""}</div>
              {engineLine(current.transcript) && <div className="small muted" data-testid="engine-line">Transcribed in {engineLine(current.transcript)}</div>}
              {current.transcript?.final_error_code !== "no_audio" && current.transcript?.final_status && (
                <div className="row retranscribe-row" style={{ gap: 6, marginTop: 6 }}>
                  <select className="select compact" aria-label="Re-transcribe in" value={retranscribeLanguage} onChange={(e) => setRetranscribeLanguage(e.target.value)}>
                    {MAIN_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{menuLabel(o)}</option>)}
                    <optgroup label="More languages">{MORE_LANGUAGES.map((o) => <option key={o.chip} value={o.value ?? ""}>{menuLabel(o)}</option>)}</optgroup>
                  </select>
                  <button type="button" className="btn ghost compact" onClick={() => void retranscribe()}>
                    {englishRisk && englishWarned ? "Re-transcribe anyway" : "Re-transcribe"}
                  </button>
                  {alternateId && (
                    <span className="row" style={{ gap: 2 }} role="group" aria-label="Version">
                      <button type="button" className="btn ghost compact" aria-pressed={version === "final"} onClick={() => setVersion("final")}>Current</button>
                      <button type="button" className="btn ghost compact" aria-pressed={version === "alternate"} onClick={() => setVersion("alternate")}>
                        Previous{current.transcript?.alternate_language ? ` (${languageLabel(current.transcript.alternate_language)})` : ""}
                      </button>
                    </span>
                  )}
                </div>
              )}
              {englishRisk && englishWarned && (
                <div className="notice" role="alert" data-testid="english-only-warning" style={{ marginTop: 8 }}>
                  This recording has Urdu in it. English only makes the engine translate the Urdu into English. Choose Urdu (with English words) to keep every word, or click Re-transcribe anyway.
                </div>
              )}
              {scriptWarning(current.transcript?.script_warning) && <div className="notice" role="status" style={{ marginTop: 8 }}>{scriptWarning(current.transcript?.script_warning)}</div>}
              <OrganisePanel item={current} clients={folders} knownLabels={labels} notify={notify} onClientCreated={addClient} />
              {playbackUrl && (
                <audio ref={audioRef} className="player" controls preload="metadata" src={playbackUrl} onTimeUpdate={onTimeUpdate} onSeeked={onTimeUpdate} onError={onPlaybackError} onLoadedMetadata={onLoaded} data-testid="player" />
              )}
              {playbackUrl && <PlayerControls rate={rate} onRate={changeRate} skipSilence={skipSilence} onSkipSilence={setSkipSilence} follow={follow} onFollow={setFollow} />}
              {panel === "share" && <SharePanel sessionId={current.id} hasAudio={!!playbackUrl} notify={notify} onClose={() => setPanel(null)} />}
              {transcript && <Timeline segments={transcript.segments} bookmarks={bookmarks} durationMs={Math.max(current.duration_ms ?? 0, ...transcript.segments.map((s) => s.end_ms))} nowMs={nowMs} onSeek={seek} />}
              {playbackError && <div className="notice" style={{ marginTop: 12 }}>{playbackError}</div>}
              {bookmarks.length > 0 && (
                <div className="chips bookmarks" role="list" aria-label="Bookmarks" style={{ marginTop: 10 }}>
                  {bookmarks.map((b) => {
                    const text = `${timestamp(b.at_ms)}${b.tag ? ` ${b.tag}` : ""}`;
                    return (
                      <span role="listitem" key={b.id}>
                        <button type="button" className="chip" title={b.note ?? undefined} disabled={!playbackUrl} onClick={() => seek(b.at_ms)}><BookmarkIcon size={12} />{text}</button>
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
                  <div className="empty">{transcribingText(transcript.progress ?? current.transcript?.final_progress)}</div>
                ) : (
                  <>
                    <div className="row" style={{ marginTop: 10 }}>
                      <span className="small muted">{transcript.kind === "final" ? `Final transcript · ${providerName(transcript.provider)}${transcript.model ? ` · ${transcript.model}` : ""}${transcript.edited_at ? " · edited" : ""}` : `Live captions (final transcript pending${partsText(current.transcript?.final_progress) ? ` · ${partsText(current.transcript?.final_progress)}` : ""})`}</span>
                      <span className="spacer" />
                      <button className="btn ghost" onClick={() => copy(false)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy text</button>
                      <button className="btn ghost" onClick={() => copy(true)} disabled={transcript.segments.length === 0}><CopyIcon size={14} />Copy with timestamps</button>
                      <button className="btn ghost" onClick={() => copy(false, true)} disabled={transcript.segments.length === 0}
                        title="Copies the text with unsure words marked [?word] and a first line that tells an AI what the marks mean"><CopyIcon size={14} />Copy for AI</button>
                    </div>
                    {transcript.kind === "final" && transcript.status === "completed" && transcript.segments.length > 0 && (
                      <div className="row small transcript-tools" role="toolbar" aria-label="Transcript tools">
                        <button className="btn ghost" aria-pressed={editing} onClick={() => setEditing(!editing)}>{editing ? "Done editing" : "Edit text"}</button>
                        <button className="btn ghost" aria-pressed={panel === "replace"} onClick={() => setPanel(panel === "replace" ? null : "replace")}>Find and replace</button>
                        <button className="btn ghost" aria-pressed={panel === "speakers"} onClick={() => setPanel(panel === "speakers" ? null : "speakers")}>Speakers</button>
                        <button className="btn ghost" aria-pressed={panel === "history"} onClick={() => setPanel(panel === "history" ? null : "history")}>History</button>
                        <ExportMenu onExport={(format) => void exportAs(format)} />
                        {(transcript.views?.available.length ?? 0) > 0 && (
                          <span className="row" style={{ gap: 2 }} role="group" aria-label="Show as">
                            <button type="button" className="btn ghost compact" aria-pressed={showAs === "original"} onClick={() => void chooseView("original")}>Original</button>
                            {VIEW_ORDER.filter((v) => transcript.views!.available.includes(v)).map((v) => (
                              <button key={v} type="button" className="btn ghost compact" aria-pressed={showAs === v} onClick={() => void chooseView(v)}>{VIEW_LABELS[v]}</button>
                            ))}
                          </span>
                        )}
                      </div>
                    )}
                    {showAs !== "original" && (
                      <div className="small muted" role="status" data-testid="view-status">
                        {views?.status === "ready"
                          ? `${VIEW_LABELS[showAs]} view${views.model ? ` by ${views.model}` : ""}. The transcript itself is unchanged.`
                          : views?.status === "failed"
                            ? `The view could not be made: ${views.error ?? "try again later."}`
                            : `Writing the ${VIEW_LABELS[showAs]} view… this takes a minute or two.`}
                      </div>
                    )}
                    {panel === "replace" && <ReplaceBar onReplace={replaceAll} onClose={() => setPanel(null)} />}
                    {panel === "speakers" && <SpeakersPanel labels={speakerLabels(transcript.segments)} names={speakerNames} hasClient={!!current.client} onSave={saveSpeakers} onClose={() => setPanel(null)} />}
                    {panel === "history" && <HistoryPanel transcriptId={transcript.id} edited={!!transcript.edited_at} onRestore={restore} onClose={() => setPanel(null)} />}
                    <VocabularyHint terms={vocabTerms} clientName={current.client?.name ?? null} onAdd={(t) => void addToVocabulary(t)} onDismiss={() => setVocabTerms([])} />
                    {transcript.segments.length === 0 && <div className="empty">The transcript is empty: no speech was detected.</div>}
                    <div className="segments" ref={segmentsRef} lang={showAs === "original" ? normalizeLanguage(transcript.language) ?? undefined : showAs === "urdu" ? "ur" : undefined}>
                      {transcript.segments.map((s, i) => {
                        const inView = viewText(i);
                        // A turn shows its speaker once: following lines of the same speaker keep the label for screen
                        // readers but hide it on screen (.cont), which groups the lines into a speaker block.
                        // Words the engine was not sure of are underlined (a click plays from that word); the text stays as heard.
                        const textCell = inView !== null ? withUnsureMarks(inView) : s.words && s.words.length > 0 && (i === activeSegment || hasUncertainWords(s))
                          ? s.words.map((w, wi) => {
                            const unsure = uncertainWord(w);
                            const className = ["word", i === activeSegment && wi === activeWordIndex ? "on" : "", unsure ? "uncertain" : ""].filter(Boolean).join(" ");
                            return (
                              <span key={wi}>
                                <span className={className} title={unsure ? UNSURE_WORD : undefined}
                                  onClick={unsure && playbackUrl ? (e) => { e.stopPropagation(); seek(w.start_ms); } : undefined}>{w.text}</span>
                                {wi < s.words!.length - 1 ? " " : ""}
                              </span>
                            );
                          })
                          : s.text;
                        const cells = (
                          <>
                            <span className="seg-time">{timestamp(s.start_ms)}</span>
                            <span className={`seg-spk spk-${s.channel === 2 ? 2 : 1}`}>{speakerName(speakerNames, s.speaker)}</span>
                            <span className={s.uncertain && inView === null ? "seg-text uncertain" : "seg-text"} dir="auto" title={s.uncertain && inView === null ? UNSURE_LINE : undefined}>
                              {textCell}
                              {inView !== null && showAs !== "original" && views?.kept?.[showAs]?.includes(i) && <span className="seg-kept muted" title="This line could not be written in the view, so it shows the words as transcribed."> · original words</span>}
                              {s.check && inView === null && <span className="seg-check" dir="auto"><b>{s.check.label} heard:</b> {s.check.text}</span>}
                            </span>
                          </>
                        );
                        const className = `segment ${i === activeSegment ? "active" : ""} ${continuesTurn(transcript.segments, i) ? "cont" : ""}`.replace(/\s+/g, " ").trim();
                        const playing = i === activeSegment ? "true" : undefined;
                        if (editing) {
                          return (
                            <div key={`${i}-${s.text}`} className={`${className} editing`} data-seg={i}>
                              <span className="seg-time">{timestamp(s.start_ms)}</span>
                              <span className={`seg-spk spk-${s.channel === 2 ? 2 : 1}`}>{speakerName(speakerNames, s.speaker)}</span>
                              <textarea className="input seg-edit" dir="auto" rows={2} defaultValue={s.text} aria-label={`Line at ${timestamp(s.start_ms)}`}
                                onBlur={(e) => void saveLine(i, e.target.value)}
                                onKeyDown={(e) => { if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) (e.target as HTMLTextAreaElement).blur(); }} />
                            </div>
                          );
                        }
                        // A row that seeks is a real button: it takes focus and Enter / Space activate it (A6.DU7).
                        return playbackUrl ? (
                          <button key={i} type="button" className={`${className} seekable`} aria-current={playing} data-seg={i} onClick={() => seek(s.start_ms)}>{cells}</button>
                        ) : (
                          <div key={i} className={className} data-seg={i}>{cells}</div>
                        );
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
            </>
          )}
        </div>
      </div>
    </>
  );
}
