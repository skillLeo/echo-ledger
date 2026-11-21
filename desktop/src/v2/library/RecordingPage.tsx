// The v2 recording page (ui_v2.library): title, the one player, the transcript with speakers, and the actions. The
// data are today's: session_detail for the audio and speaker names, transcript_detail for the text. Its ⋮ is the
// Library's one menu (organise.tsx) with this page's parts: Change language, the Export ▸ in the view on screen with
// PDF and the meeting packs, and Save audio. Under the player: Send to WhatsApp · Copy (the text at once, its chevron
// for the other ways) · Export ▾; over the
// transcript: Show as (Roman Urdu · اردو · English · Original), as classic.
import { useEffect, useMemo, useRef, useState } from "react";
import {
  api, errorMessage, type ExportFormat, type LibraryItem, type MeetingPackFormat, type ScreenMoment, type TranscriptDetail, type TranscriptView,
  type TranscriptViewsState,
} from "../../lib/ipc";
import { confirmAction } from "../../lib/confirm";
import { screenSaid, screensByLine } from "../../lib/screens";
import { useSessionScreens } from "../../views/main/ScreenMoments";
import { LineScreens, ScreensStrip, ScreenViewerV2 } from "./screens";
import { itemState } from "../../lib/library";
import { copiedTranscript, DEFAULT_UNSURE, formatDuration, formatWhen, providerName, sourceAppLabel, timestamp, type UnsureMarkers } from "../../lib/format";
import { PLAYER_HINT, activeIndex, findMatches, KEY_SECONDS, markParts, playerKey, playerLength, skipTarget, speakerLabels, speakerName } from "../../lib/player";
import { CallNotesV2, ConvertingLine, FindBar, HistoryDialog, LineEditor, ReplaceDialog, SpeakersDialog } from "./TranscriptToolsV2";
import { defaultViewOf, improvingView, viewPercent } from "../../lib/progress";
import { normalizeLanguage, scriptWarning, transcribedIn } from "../../lib/languages";
import { bookmarksOf, type Bookmark } from "../../lib/library";
import { ShareDialog } from "../../views/main/ShareDialog";
import { PDF_HINT } from "../../views/main/TranscriptTools";
import { screenMarker } from "../../lib/screens";
import { Badge, Button, Card, copyMenuItems, EmptyState, Icons, Loading, Player, Segmented, Select, Skeleton, SplitButton, Tabs, Toggle, Transcript, type Segment } from "../components";
import { Menu, type MenuItem } from "../components/Menu";
import { SourceAvatar } from "./SourceAvatar";
import { aiHeader, CHANGE_LANGUAGE, LanguageDialog, recordingExportItems, TranscribeDialog, WhatsAppDialog } from "./organise";
import type { RecordingMenuParts } from "./LibraryV2";

type Tab = "transcript" | "notes" | "details";
type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; value: T };

export type RecordingPageProps = {
  item: LibraryItem;
  /** Open at a moment (a transcript match, a link): the player goes there and its line is shown. */
  startAt?: { ms: number; seq: number } | null;
  onBack: () => void;
  /** One message at a time, with at most one action (the Library's Toast). */
  onMessage: (message: string) => void;
  /** Opens the recording's project (its name in the meta line). */
  onOpenProject?: (id: string) => void;
  /** The Library's one menu for this recording, with this page's parts in their places (Delete stays last). */
  menu: (own: RecordingMenuParts) => MenuItem[];
  /** What the page read about the transcript's views, for the recording's row in the Library. */
  onViews?: (views: TranscriptViewsState) => void;
};

const SPEEDS = ["0.5", "0.75", "1", "1.25", "1.5", "1.75", "2"];
/** Show as: the views in classic's order, the original words last, and their names. */
const VIEW_ORDER: TranscriptView[] = ["roman", "urdu", "english"];
const VIEW_LABELS: Record<TranscriptView, string> = { roman: "Roman Urdu", english: "English", urdu: "اردو" };
const VIEWS_POLL_MS = 5_000;
type ShowAs = "original" | TranscriptView;

/** Keys the page leaves alone: typing, and anything inside a dialog or a menu. */
const inField = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || !!target.closest('[role="dialog"]:not(.el-drawer), [role="alertdialog"], [role="menu"], [role="listbox"]'));

export function RecordingPage({ item, startAt = null, onBack, onMessage, menu, onOpenProject, onViews }: RecordingPageProps) {
  const [sharing, setSharing] = useState(false);
  const [tab, setTab] = useState<Tab>("transcript");
  const [detail, setDetail] = useState<Load<{ url: string | null; names: Record<string, string>; bookmarks: Bookmark[] }>>({ state: "loading" });
  const [transcript, setTranscript] = useState<Load<TranscriptDetail | null>>({ state: "loading" });
  const [attempt, setAttempt] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [position, setPosition] = useState(0);
  const [speed, setSpeed] = useState("1");
  const [busy, setBusy] = useState(false);
  const [skipSilence, setSkipSilence] = useState(false);
  const [mediaSeconds, setMediaSeconds] = useState<number | null>(null);
  const [transcribeOpen, setTranscribeOpen] = useState(false);
  const [follow, setFollow] = useState(true);
  const [showAs, setShowAs] = useState<ShowAs>("original");
  const [views, setViews] = useState<TranscriptViewsState | null>(null);
  const [languageOpen, setLanguageOpen] = useState(false);
  const [unsure, setUnsure] = useState<UnsureMarkers>(DEFAULT_UNSURE);
  // The transcript tools (classic's): Find, editing a line, Find and replace, Speakers, History.
  const [findOpen, setFindOpen] = useState(false);
  const [findQuery, setFindQuery] = useState("");
  const [findAt, setFindAt] = useState(0);
  const [editing, setEditing] = useState(false);
  const [editLine, setEditLine] = useState<number | null>(null);
  const [tool, setTool] = useState<"replace" | "speakers" | "history" | null>(null);
  const viewPress = useRef(0);
  const viewPicked = useRef(false);
  const page = useRef<HTMLDivElement>(null);
  const audio = useRef<HTMLAudioElement>(null);
  const [screenOpen, setScreenOpen] = useState<number | null>(null);
  const { screens, reload: reloadScreens } = useSessionScreens(item.id, item.screen_count);
  const state = itemState(item);
  const transcriptId = item.transcript?.final_transcript_id ?? item.transcript?.live_transcript_id ?? null;

  useEffect(() => {
    let live = true;
    setDetail({ state: "loading" });
    setTranscript(transcriptId ? { state: "loading" } : { state: "ready", value: null });
    api.session(item.id).then((d) => {
      if (!live) return;
      const names = d.speaker_names;
      setDetail({
        state: "ready",
        value: {
          url: typeof d.playback_url === "string" ? d.playback_url : null,
          names: names && typeof names === "object" && !Array.isArray(names) ? (names as Record<string, string>) : {},
          bookmarks: bookmarksOf(d),
        },
      });
    }).catch((e) => live && setDetail({ state: "error", message: errorMessage(e) }));
    if (transcriptId) {
      api.transcript(transcriptId).then((t) => live && setTranscript({ state: "ready", value: t }))
        .catch((e) => live && setTranscript({ state: "error", message: errorMessage(e) }));
    }
    return () => {
      live = false;
    };
  }, [item.id, transcriptId, attempt]);

  useEffect(() => {
    if (audio.current) audio.current.playbackRate = Number(speed);
  }, [speed]);
  useEffect(() => {
    api.unsureMarkers().then((m) => { if (m) setUnsure({ ...DEFAULT_UNSURE, ...m }); }).catch(() => undefined);
  }, []);

  // Show as: a view is made on the server the first time it is asked for; while it is being written the page asks again
  // every few seconds. A recording that opens with a default view the console chose shows it once it is ready.
  const tDetail = transcript.state === "ready" ? transcript.value : null;
  const viewsInfo = tDetail?.views ?? null;
  useEffect(() => {
    setShowAs("original");
    setViews(null);
    viewPicked.current = false;
  }, [transcriptId]);
  useEffect(() => {
    const preferred = viewsInfo?.default;
    if (!tDetail || viewPicked.current || !preferred || preferred === "original" || viewsInfo?.status !== "ready") return;
    void chooseView(preferred, false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tDetail?.id, viewsInfo?.status]);
  // The default view (Roman Urdu for an Urdu call) is written after the transcript: "making" from the moment it is
  // done, so the page asks at once and then every few seconds, and stops once the views are ready or failed.
  const viewsStatus = views?.status ?? viewsInfo?.status ?? null;
  const viewsMaking = viewsStatus === "making" && tDetail?.kind === "final";
  useEffect(() => {
    const id = tDetail?.id;
    if (!id || !viewsMaking) return;
    let alive = true;
    const timer = window.setTimeout(() => {
      api.transcriptViews(id).then((v) => { if (alive && shownTranscript.current === id) setViews(v); }).catch(() => undefined);
    }, views ? VIEWS_POLL_MS : 0);
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [tDetail?.id, views, viewsMaking]);
  // Made while the page is open: the default view comes in (unless the person chose one), and the Library syncs, for
  // the title the server may have written in Roman Urdu meanwhile. The row hears of every read.
  const lastStatus = useRef<string | null>(null);
  useEffect(() => {
    if (views && tDetail && shownTranscript.current === tDetail.id) onViews?.(views);
    const became = lastStatus.current === "making" && viewsStatus === "ready";
    lastStatus.current = viewsStatus;
    if (!became) return;
    void api.refreshLibrary().catch(() => undefined);
    const preferred = defaultViewOf(viewsInfo);
    if (!viewPicked.current && !editing && preferred && views?.available.includes(preferred)) setShowAs(preferred);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [views, viewsStatus]);
  const barView = viewsMaking ? improvingView(views?.progress ?? viewsInfo?.progress, showAs, viewsInfo?.default) : null;
  const failedView = viewsStatus === "failed" && tDetail?.kind === "final" ? (showAs !== "original" ? showAs : defaultViewOf(viewsInfo)) : null;
  const [retrying, setRetrying] = useState(false);
  const retryViews = async () => {
    const id = tDetail?.id;
    if (!id || retrying) return;
    setRetrying(true);
    try {
      const state = await api.makeTranscriptViews(id);
      if (shownTranscript.current === id) setViews(state);
    } catch (e) {
      onMessage(errorMessage(e));
    } finally {
      if (mounted.current) setRetrying(false);
    }
  };
  const chooseView = async (view: ShowAs, picked = true) => {
    const press = ++viewPress.current;
    if (picked) viewPicked.current = true;
    setShowAs(view);
    const id = tDetail?.id;
    if (view === "original" || !id || views?.status === "ready") return;
    try {
      let state = await api.transcriptViews(id);
      if (state.status === "none" || state.status === "failed") state = await api.makeTranscriptViews(id);
      setViews(state);
    } catch (e) {
      onMessage(errorMessage(e));
      if (viewPress.current === press) setShowAs("original");
    }
  };
  const viewText = (i: number): string | null => (showAs !== "original" && views?.status === "ready" ? views[showAs]?.[i] ?? null : null);
  const exportView = showAs !== "original" && views?.status === "ready" ? showAs : null;

  const names = detail.state === "ready" ? detail.value.names : {};
  const segments: Segment[] = transcript.state === "ready" && transcript.value
    ? transcript.value.segments.map((s, i) => ({ speaker: speakerName(names, s.speaker), start: s.start_ms / 1000, text: viewText(i) ?? s.text }))
    : [];
  const matches = useMemo(() => (findOpen ? findMatches(segments.map((s) => s.text), findQuery) : []),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [findOpen, findQuery, transcript, showAs, views]);
  const findCurrent = matches.length ? Math.min(findAt, matches.length - 1) : -1;
  useEffect(() => setFindAt(0), [findQuery]);
  useEffect(() => {
    if (findCurrent >= 0) page.current?.querySelector("mark.el-find--current")?.scrollIntoView?.({ block: "center" });
  }, [findCurrent, findQuery]);
  const canEdit = transcript.state === "ready" && !!transcript.value && transcript.value.kind === "final" && transcript.value.status === "completed" && transcript.value.segments.length > 0;
  // An answer that comes back once another recording or transcript is on screen is dropped (classic's rule): it never
  // replaces what is shown now. The edit itself is saved on the server either way.
  const shownTranscript = useRef<string | null>(null);
  shownTranscript.current = transcript.state === "ready" ? transcript.value?.id ?? null : null;
  const mounted = useRef(true);
  useEffect(() => () => { mounted.current = false; }, []);
  const stillShown = (transcriptId: string) => mounted.current && shownTranscript.current === transcriptId;
  const showTranscript = (t: TranscriptDetail) => {
    if (stillShown(t.id)) setTranscript({ state: "ready", value: t });
  };
  const saveLine = async (index: number, text: string) => {
    // Another line opened meanwhile stays open: only the saved line closes.
    if (mounted.current) setEditLine((l) => (l === index ? null : l));
    const id = shownTranscript.current;
    if (!id) return;
    try {
      const { learn: _learn, ...edited } = await api.editTranscript(id, index, text);
      if (!stillShown(id)) return;
      setTranscript({ state: "ready", value: edited });
      onMessage("Line saved");
    } catch (e) {
      onMessage(errorMessage(e));
    }
  };
  /** The words a line has in the transcript itself (never a Show as view's): what an edit starts from. */
  const rawText = (i: number) => (transcript.state === "ready" ? transcript.value?.segments[i]?.text ?? segments[i].text : segments[i].text);
  const renderText = (i: number) => {
    if (editing && editLine === i) return <LineEditor text={rawText(i)} onSave={(t) => void saveLine(i, t)} onCancel={() => setEditLine(null)} />;
    if (editing) return <button type="button" className="el-line-edit" aria-label={`Edit line ${i + 1}: ${rawText(i)}`} onClick={(e) => { e.stopPropagation(); setEditLine(i); }}>{rawText(i)}</button>;
    const inLine = matches.map((m, index) => ({ ...m, index })).filter((m) => m.seg === i);
    if (!inLine.length) return undefined;
    return markParts(segments[i].text, inLine).map((p, k) => (p.match === null ? <span key={k}>{p.text}</span>
      : <mark key={k} className={p.match === findCurrent ? "el-find el-find--current" : "el-find"}>{p.text}</mark>));
  };
  // The line being played is marked once playing has started, not on a page just opened.
  const active = transcript.state === "ready" && transcript.value && (playing || position > 0) ? activeIndex(transcript.value.segments, position * 1000) : -1;
  // The screens at their moments: after the line that started at or before each one (the phone's rule).
  const rawSegments = transcript.state === "ready" && transcript.value ? transcript.value.segments : null;
  // The player's length: the server's, else the audio file's own (on loadedmetadata), else the last line's end.
  const durationS = Math.max(1, Math.round(playerLength(item.duration_ms, rawSegments, mediaSeconds) / 1000));
  const screensAfter = useMemo(() => screensByLine(rawSegments ?? [], screens ?? []), [rawSegments, screens]);
  const lines = useMemo(() => (rawSegments ?? []).map((s) => ({ ...s, speaker: speakerName(names, s.speaker) })), [rawSegments, names]);
  const openScreen = (screen: ScreenMoment) => setScreenOpen(Math.max(0, (screens ?? []).findIndex((s) => s.id === screen.id)));
  const deleteScreen = async (screen: ScreenMoment) => {
    if (!(await confirmAction({ title: `Delete screen ${screen.index}?`, body: "The recording and its transcript stay.", confirm: "Delete", tone: "danger" }))) return;
    try {
      await api.deleteScreen(item.id, screen.id);
      setScreenOpen(null);
      reloadScreens();
      onMessage(`Screen ${screen.index} deleted`);
    } catch (e) {
      onMessage(errorMessage(e));
    }
  };
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : null);
  const meta = [source, formatWhen(item.started_at)].filter(Boolean).join(" · ");
  const engine = transcribedIn(item.transcript);
  const warning = scriptWarning(item.transcript?.script_warning);
  const bookmarks = detail.state === "ready" ? detail.value.bookmarks : [];

  // Copy: the lines in the view on screen, with unsure words marked as the console says; Copy for AI starts with a short
  // header (title, date, people, language) and the note on the marks, and puts each screen after the line of its moment.
  const copy = async (withTimestamps: boolean, forAi = false) => {
    if (!lines.length) return;
    try {
      const after = (i: number) => (screensAfter.get(i) ?? []).map(screenMarker);
      const body = copiedTranscript(lines, viewText, withTimestamps, unsure, forAi, after);
      const people = [...new Set(lines.map((l) => l.speaker))];
      // The header's language is the view's when the lines are in one ("Roman Urdu"), as the server writes it.
      await navigator.clipboard.writeText(forAi ? `${aiHeader(item, people, exportView)}\n\n${body}` : body);
      onMessage(forAi ? "Copied for AI, with the note on unsure words" : withTimestamps ? "Copied with timestamps" : "Copied");
    } catch {
      onMessage("Could not access the clipboard");
    }
  };
  const exportAs = async (format: ExportFormat) => {
    if (!tDetail) return;
    try {
      const path = await api.exportTranscript(tDetail.id, format, true, true, exportView);
      if (format === "html") onMessage("Opened the print page in your browser: choose Save as PDF there");
      else if (path) onMessage(`Saved to ${path}`);
    } catch (e) {
      onMessage(errorMessage(e));
    }
  };
  const packAs = async (format: MeetingPackFormat, withAudio: boolean) => {
    try {
      const path = await api.meetingPack(item.id, format, exportView, withAudio);
      if (format === "pdf") onMessage("Opened the meeting pack in your browser: choose Save as PDF there");
      else if (path) onMessage(`Saved to ${path}`);
    } catch (e) {
      onMessage(errorMessage(e));
    }
  };
  const canExport = !!tDetail && tDetail.segments.length > 0;
  // Classic offers Re-transcribe only on a recording that has a final transcript and some audio (LibraryView 1452).
  const canChangeLanguage = item.transcript?.final_error_code !== "no_audio" && !!item.transcript?.final_status;
  const [whatsApp, setWhatsApp] = useState(false);
  // The menus are made once per recording, view and transcript: never on the player's position.
  const exportsRef = useRef({ exportAs, packAs });
  const copyRef = useRef(copy);
  copyRef.current = copy;
  exportsRef.current = { exportAs, packAs };
  const exportItems: MenuItem[] = useMemo(() => recordingExportItems({
    exportAs: (f) => void exportsRef.current.exportAs(f),
    packAs: (item.screen_count ?? 0) > 0 ? (f, a) => void exportsRef.current.packAs(f, a) : undefined,
    view: exportView ? VIEW_LABELS[exportView] : null,
  }), [exportView, item.screen_count]);
  const recordingMenu = useMemo(() => menu({
    organise: canChangeLanguage ? [{ label: CHANGE_LANGUAGE, icon: Icons.LanguagesIcon, onPick: () => setLanguageOpen(true) }] : [],
    export: canExport ? { label: "Export", icon: Icons.DownloadIcon, group: "Export", items: exportItems } : undefined,
    export_more: [{ label: "Save audio", icon: Icons.StorageIcon, onPick: () => void act(async () => { if (await api.saveAudio(item.id)) onMessage("Audio saved"); }) }],
    whatsapp: () => setWhatsApp(true),
    ai: () => void copyRef.current(false, true),
  }).filter((i) => canExport || i.label !== "Export"),
  // eslint-disable-next-line react-hooks/exhaustive-deps
  [menu, canChangeLanguage, canExport, exportItems, item.id]);
  const sendToWhatsApp = () => setWhatsApp(true);

  const toggle = () => {
    const a = audio.current;
    if (!a) return;
    if (a.paused) void a.play().catch(() => onMessage("The audio didn't play. Try again in a moment."));
    else a.pause();
  };
  const seek = (s: number) => {
    const to = Math.max(0, Math.min(durationS, s));
    setPosition(to);
    if (audio.current) audio.current.currentTime = to;
  };
  const playFrom = (i: number) => {
    seek(segments[i]?.start ?? 0);
    void audio.current?.play().catch(() => undefined);
  };
  // Space plays or pauses, the arrows jump 5 s and Shift with an arrow 10 s, as classic; never while typing.
  const keys = useRef<(e: KeyboardEvent) => void>(() => undefined);
  keys.current = (e: KeyboardEvent) => {
    // Nothing to find (no transcript): Cmd/Ctrl+F is left to app search.
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "f" && !e.altKey && !inField(e.target)) {
      if (!segments.length) return;
      e.preventDefault();
      setFindOpen(true);
      return;
    }
    if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey || inField(e.target)) return;
    const target = e.target as HTMLElement | null;
    const key = playerKey(e.key, target?.tagName ?? "", !!target?.isContentEditable, e.shiftKey);
    if (!key || !audio.current) return;
    e.preventDefault();
    if (key === "toggle") toggle();
    else seek(position + KEY_SECONDS[key]);
  };
  useEffect(() => {
    const on = (e: KeyboardEvent) => keys.current(e);
    document.addEventListener("keydown", on);
    return () => document.removeEventListener("keydown", on);
  }, []);
  // Opened at a moment: once the page has its audio and transcript, the player goes there (once per request) and the
  // line of that moment is shown and marked.
  const startedAt = useRef<number | null>(null);
  useEffect(() => {
    if (!startAt || startedAt.current === startAt.seq || detail.state === "loading" || transcript.state === "loading") return;
    startedAt.current = startAt.seq;
    seek(startAt.ms / 1000);
    window.requestAnimationFrame?.(() => page.current?.querySelector(".el-segment--active")?.scrollIntoView?.({ block: "center" }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [startAt?.seq, detail.state, transcript.state]);
  // Follow: the line being played stays in view.
  useEffect(() => {
    if (!follow || active < 0 || !playing) return;
    page.current?.querySelector(".el-segment--active")?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
  }, [active, follow, playing]);
  const timeUpdate = (a: HTMLAudioElement) => {
    if (skipSilence && !a.paused && rawSegments) {
      const to = skipTarget(rawSegments, a.currentTime * 1000);
      if (to !== null) {
        a.currentTime = to / 1000;
        return;
      }
    }
    setPosition(a.currentTime);
  };
  const act = async (fn: () => Promise<unknown>, done?: string) => {
    setBusy(true);
    try {
      await fn();
      if (done) onMessage(done);
    } catch (e) {
      onMessage(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  if (detail.state === "error" && transcript.state !== "ready") {
    return (
      <div className="el-page">
        <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Library</Button>
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="This recording didn't open" body={detail.message}
          action={<Button variant="primary" onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
      </div>
    );
  }

  return (
    <div className="el-page" ref={page}>
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack} aria-keyshortcuts="Escape">Library</Button>
      <header className="el-page__head el-page__head--top">
        <div className="el-page__titles">
          <h1 className="el-title-l" dir="auto">{item.title?.trim() || "Recording"}</h1>
          <p className="el-page__meta el-secondary">
            <SourceAvatar item={item} size="sm" />
            <span dir="auto">{meta}</span>
            {item.client && (onOpenProject
              ? <Button size="sm" variant="quiet" icon={Icons.FolderIcon} onClick={() => onOpenProject(item.client!.id)} aria-label={`Open project ${item.client.name}`}>{item.client.name}</Button>
              : <span dir="auto">{item.client.name}</span>)}
            {item.title_auto && item.title?.trim() && <Badge>Automatic title</Badge>}
            {state === "ready" ? <Badge tone="success">Transcribed</Badge>
              : state === "failed" ? <Badge tone="danger">Transcription failed</Badge>
                : state === "no_audio" ? <Badge>Nothing was heard</Badge>
                  : item.transcript?.final_status ? <Badge tone="accent">Transcribing…</Badge> : <Badge tone="warning">Untranscribed</Badge>}
          </p>
          {engine && <p className="el-caption el-secondary">Transcribed in {engine}</p>}
          {warning && <p className="el-caption el-row__error" role="status">{warning}</p>}
        </div>
        <div className="el-page__actions">
          <Button variant="quiet" icon={Icons.LinkIcon} onClick={() => setSharing(true)} title="Share a view-only link or send to a teammate">Share</Button>
          <Menu label="Recording actions" items={recordingMenu} button={{ variant: "quiet", icon: Icons.MoreIcon, title: "More" }} />
        </div>
      </header>
      {screens && screens.length > 0 && <ScreensStrip screens={screens} onOpen={setScreenOpen} />}
      <Card className="el-page__player">
        {detail.state === "loading" ? (
          <Loading label="Loading the audio"><Skeleton shape="block" /></Loading>
        ) : (
          <>
            {detail.state === "ready" && detail.value.url && (
              <audio ref={audio} src={detail.value.url} preload="metadata" onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}
                onLoadedMetadata={(e) => setMediaSeconds(e.currentTarget.duration)}
                onEnded={() => setPlaying(false)} onTimeUpdate={(e) => timeUpdate(e.currentTarget)} />
            )}
            <Player playing={playing} position={Math.round(position)} duration={durationS} onToggle={toggle} onSeek={seek}
              onSkip={(s) => seek(position + s)}
              end={<Select label="Playback speed" hideLabel value={speed} onChange={setSpeed} className="el-page__speed"
                options={SPEEDS.map((v) => ({ value: v, label: `${v}×`, dir: "ltr" as const }))} />} />
            <div className="el-recording__toggles">
              <Toggle label="Skip silence" checked={skipSilence} onChange={setSkipSilence} />
              <Toggle label="Follow" checked={follow} onChange={setFollow} />
              <span className="el-caption el-secondary el-recording__keys">{PLAYER_HINT}</span>
            </div>
            {bookmarks.length > 0 && (
              <ul className="el-bookmarks" aria-label="Bookmarks">
                {bookmarks.map((b) => (
                  <li key={b.id}>
                    <Button size="sm" variant="secondary" icon={Icons.PinIcon} title={b.note ?? undefined} disabled={!(detail.state === "ready" && detail.value.url)}
                      onClick={() => { seek(b.at_ms / 1000); void audio.current?.play().catch(() => undefined); }}>
                      {`${timestamp(b.at_ms)}${b.tag ? ` ${b.tag}` : ""}`}
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </>
        )}
      </Card>
      {canExport && (
        <div className="el-recording__actions" role="toolbar" aria-label="Share, copy and export">
          <Button icon={Icons.SendIcon} disabled={busy} onClick={sendToWhatsApp}>Send to WhatsApp…</Button>
          {/* Copy: the main part copies the text without timestamps at once; the chevron has the other ways. */}
          <SplitButton label="Copy" icon={Icons.CopyIcon} mainLabel="Copy text" onClick={() => void copy(false)} menuLabel="More copy options"
            items={copyMenuItems({ withTimestamps: () => void copy(true), forAi: () => void copy(false, true) })} />
          <Menu label={exportView ? `Export as ${VIEW_LABELS[exportView]}` : "Export"} items={exportItems}
            button={{ icon: Icons.DownloadIcon, iconEnd: Icons.ChevronDownIcon, text: exportView ? `Export as ${VIEW_LABELS[exportView]}` : "Export", title: PDF_HINT }} />
        </div>
      )}
      <Tabs label="Recording" value={tab} onChange={setTab} tabs={[{ value: "transcript", label: "Transcript" }, ...(item.client ? [{ value: "notes" as Tab, label: "Call notes" }] : []), { value: "details", label: "Details" }]}>
        {tab === "notes" ? (
          <CallNotesV2 sessionId={item.id} say={onMessage} onSeek={(ms) => { setTab("transcript"); seek(ms / 1000); void audio.current?.play().catch(() => undefined); }} />
        ) : tab === "transcript" ? (
          transcript.state === "loading" ? (
            <Loading label="Loading the transcript">
              {Array.from({ length: 4 }, (_, i) => <Skeleton key={i} width={i % 2 ? "medium" : "long"} />)}
            </Loading>
          ) : transcript.state === "error" ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title="The transcript didn't load" body={transcript.message}
              action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
          ) : segments.length > 0 ? (
            <>
              {(viewsInfo?.available.length ?? 0) > 0 && tDetail?.kind === "final" && (
                <div className="el-recording__viewbar">
                  {editing ? <span className="el-caption el-secondary">Show as is off while you edit: you edit the words as heard.</span> : <Segmented label="Show as" value={showAs} onChange={(v) => void chooseView(v)}
                    options={[...VIEW_ORDER.filter((v) => viewsInfo!.available.includes(v)).map((v) => ({ value: v as ShowAs, label: VIEW_LABELS[v] })),
                      { value: "original" as ShowAs, label: "Original" }]} />}
                  {barView && <ConvertingLine view={barView} percent={viewPercent(views?.progress ?? viewsInfo?.progress, barView)} />}
                  {!barView && failedView && <ConvertingLine view={failedView} failed retrying={retrying} onRetry={() => void retryViews()} />}
                  {showAs !== "original" && (
                    <span className="el-caption el-secondary" role="status">
                      {views?.status === "ready" ? `${VIEW_LABELS[showAs]} view. The transcript itself is unchanged.`
                        : views?.status === "failed" ? `The view could not be made: ${views.error ?? "try again later."}`
                          : `Writing the ${VIEW_LABELS[showAs]} view… this takes a minute or two.`}
                    </span>
                  )}
                </div>
              )}
              {canEdit && (
                <div className="el-recording__viewbar" role="toolbar" aria-label="Transcript tools">
                  <span className="el-caption el-secondary">{transcript.state === "ready" && transcript.value?.edited_at ? "Final transcript · edited" : "Final transcript"}</span>
                  <Button size="sm" variant="quiet" icon={Icons.SearchIcon} aria-pressed={findOpen} aria-keyshortcuts="Meta+F Control+F" onClick={() => setFindOpen(!findOpen)}>Find</Button>
                  <Button size="sm" variant="quiet" icon={Icons.RenameIcon} aria-pressed={editing} onClick={() => { if (!editing) void chooseView("original"); setEditing(!editing); setEditLine(null); }}>{editing ? "Done editing" : "Edit text"}</Button>
                  <Button size="sm" variant="quiet" onClick={() => setTool("replace")}>Find and replace</Button>
                  <Button size="sm" variant="quiet" icon={Icons.UserIcon} onClick={() => setTool("speakers")}>Speakers</Button>
                  <Button size="sm" variant="quiet" icon={Icons.ClockIcon} onClick={() => setTool("history")}>History</Button>
                </div>
              )}
              {findOpen && <FindBar query={findQuery} onQuery={setFindQuery} count={matches.length} current={Math.max(0, findCurrent)}
                onStep={(d) => matches.length && setFindAt((findCurrent + d + matches.length) % matches.length)} onClose={() => { setFindOpen(false); setFindQuery(""); }} />}
              <Transcript label="Transcript" segments={segments} renderText={renderText} activeIndex={active >= 0 ? active : undefined} onPlayLine={detail.state === "ready" && detail.value.url ? playFrom : undefined}
                lang={showAs === "urdu" ? "ur" : showAs === "original" ? normalizeLanguage(tDetail?.language) ?? undefined : undefined}
                note={(i) => (showAs !== "original" && views?.kept?.[showAs]?.includes(i) ? <span className="el-segment__note">· original words</span> : null)}
                after={(i) => { const at = screensAfter.get(i); return at ? <LineScreens screens={at} onOpen={openScreen} /> : null; }} />
            </>
          ) : transcript.state === "ready" && transcript.value?.status === "completed" && transcript.value.segments.length === 0 ? (
            <EmptyState icon={Icons.TranscriptIcon} title="The transcript is empty" body="No speech was detected in this recording." />
          ) : state === "processing" && item.transcript?.final_status ? (
            <EmptyState icon={Icons.TranscriptIcon} title="Transcribing" body="The transcript appears here when it is ready. You can keep working meanwhile." />
          ) : state === "no_audio" ? (
            <EmptyState icon={Icons.MicIcon} title="Nothing was heard" body="The recording has no sound to transcribe. Check the microphone and system audio before the next call." />
          ) : (
            <EmptyState icon={Icons.TranscriptIcon} title={state === "failed" ? "The transcription failed" : "Not transcribed yet"}
              body={`Transcribe this recording to read, search and share what was said.${item.duration_ms ? ` It is ${formatDuration(item.duration_ms)} long.` : ""}`}
              action={<Button variant="primary" loading={busy} loadingLabel="Starting…"
                onClick={() => setTranscribeOpen(true)}>Transcribe</Button>} />
          )
        ) : (
          <dl className="el-details">
            <dt>Recorded</dt><dd>{formatWhen(item.started_at) || "Unknown"}</dd>
            <dt>Length</dt><dd className="el-num">{formatDuration(item.duration_ms) || "Unknown"}</dd>
            {source && <><dt>Source</dt><dd>{source}</dd></>}
            {item.source_device && <><dt>Recorded on</dt><dd>{{ phone: "Phone", desktop: "Computer", web: "Web" }[item.source_device] ?? item.source_device}</dd></>}
            {item.device?.name && <><dt>Device</dt><dd>{item.device.name}</dd></>}
            {item.client?.name && <><dt>Project</dt><dd>{item.client.name}</dd></>}
            {item.language_chip && <><dt>Language</dt><dd>{item.language_chip}</dd></>}
            {item.transcript?.final_provider_label || item.transcript?.final_provider
              ? <><dt>Transcription engine</dt><dd>{item.transcript.final_provider_label || providerName(item.transcript.final_provider)}</dd></> : null}
            <dt>Kept</dt><dd>{item.keep ? "Forever" : "Until auto clean-up (Settings › Storage)"}</dd>
            <dt>Labels</dt><dd>{item.labels?.length ? item.labels.join(", ") : "None"}</dd>
            <dt>Pinned</dt><dd>{item.pinned ? "Yes" : "No"}</dd>
          </dl>
        )}
      </Tabs>
      {whatsApp && <WhatsAppDialog item={item} onClose={() => setWhatsApp(false)} say={onMessage}
        text={lines.length ? copiedTranscript(lines, viewText, false, unsure) : null} hasAudio={detail.state === "ready" && !!detail.value.url} />}
      {tool === "replace" && transcript.state === "ready" && transcript.value && (
        <ReplaceDialog transcriptId={transcript.value.id} onClose={() => setTool(null)} say={onMessage}
          onReplaced={showTranscript} />
      )}
      {tool === "speakers" && transcript.state === "ready" && transcript.value && (
        <SpeakersDialog sessionId={item.id} labels={speakerLabels(transcript.value.segments)} names={names} hasProject={!!item.client}
          onClose={() => setTool(null)} say={onMessage}
          onSaved={(n) => { if (mounted.current) setDetail((d) => (d.state === "ready" ? { state: "ready", value: { ...d.value, names: n } } : d)); }} />
      )}
      {tool === "history" && transcript.state === "ready" && transcript.value && (
        <HistoryDialog transcriptId={transcript.value.id} edited={!!transcript.value.edited_at} onClose={() => setTool(null)} say={onMessage}
          onRestored={showTranscript} />
      )}
      {sharing && (
        <ShareDialog target={{ kind: "session", id: item.id, title: item.title ?? null, hasAudio: detail.state === "ready" && !!detail.value.url }}
          view={exportView} onClose={() => setSharing(false)} notify={(t) => onMessage(t)} />
      )}
      {transcribeOpen && <TranscribeDialog ids={[item.id]} onClose={() => setTranscribeOpen(false)} say={onMessage} />}
      {languageOpen && <LanguageDialog item={item} onClose={() => setLanguageOpen(false)} say={onMessage} />}
      {screenOpen !== null && screens?.[screenOpen] && (
        <ScreenViewerV2 screens={screens} index={screenOpen} onIndex={setScreenOpen} onClose={() => setScreenOpen(null)} onExpired={reloadScreens}
          said={(s) => screenSaid(s, lines)}
          onPlay={detail.state === "ready" && detail.value.url ? (s) => {
            setScreenOpen(null);
            seek(s.at_ms / 1000);
            void audio.current?.play().catch(() => undefined);
          } : undefined}
          onDelete={(s) => void deleteScreen(s)} />
      )}
    </div>
  );
}
