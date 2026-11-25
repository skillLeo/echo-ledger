// The v2 recording page (ui_v2.library): title, the one player, the transcript with speakers, and the actions. The
// data are today's: session_detail for the audio and speaker names, transcript_detail for the text. Its ⋮ is the
// Library's one menu (organise.tsx) with this page's parts: Change language, the Export ▸ in the view on screen with
// PDF and the meeting packs, and Save audio. Under the player: Send to WhatsApp · Copy (the text at once, its chevron
// for the other ways) · Export ▾; over the
// transcript: Show as (Roman Urdu · اردو · English · Original), as classic. In the account's language (src/i18n).
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import {
  api, errorMessage, type ExportFormat, type LearnOffer, type LibraryItem, type TranscriptionLanguage, type TranscriptSuggestion, type MeetingPackFormat, type ScreenMoment, type TranscriptDetail, type TranscriptView,
  type TranscriptViewsState,
} from "../../lib/ipc";
import { confirmAction } from "../../lib/confirm";
import { screenSaid, screensByLine } from "../../lib/screens";
import { useSessionScreens } from "../../views/main/ScreenMoments";
import { LineScreens, ScreensStrip, ScreenViewerV2 } from "./screens";
import { itemState } from "../../lib/library";
import { copiedTranscript, DEFAULT_UNSURE, fileSize, formatDuration, formatWhen, providerName, sourceAppLabel, timestamp, type UnsureMarkers } from "../../lib/format";
import { playerHint, activeIndex, activeWord, waveformBars, findMatches, KEY_SECONDS, markParts, newTerms, playerKey, playerLength, skipTarget, speakerLabels, speakerName, suggestionSpan, uncertainWord } from "../../lib/player";
import { CallNotesV2, ConvertingLine, FindBar, HistoryDialog, LineEditor, ReplaceDialog, SpeakersDialog } from "./TranscriptToolsV2";
import { defaultViewOf, improvingView, transcribingLine, viewName, viewPercent } from "../../lib/progress";
import { usePoll } from "../../lib/usePoll";
import { canTryAgain, failureLine, languageLabel, needsKeyAction, normalizeLanguage, partsText, retryLine, scriptWarning, transcribedIn, usedBackupEngine } from "../../lib/languages";
import { openSettingsSection } from "../../lib/entitlements";
import { playbackExpired } from "../shared/SharedReader";
import { bookmarksOf, type Bookmark } from "../../lib/library";
import { ShareDialog } from "../../views/main/ShareDialog";
import { pdfHint, WAVE_BARS } from "../../views/main/TranscriptTools";
import { LockedChip, needsKeyText } from "../../views/main/Upgrade";
import { BackupEngineLine, FiledByLineV2, LearnOfferV2, RecordingStepsV2, VocabularyOfferV2 } from "./recordingExtras";
import { screenMarker } from "../../lib/screens";
import { Waveform, Badge, Banner, Button, Card, copyMenuItems, LabelChip, EmptyState, Icons, Loading, Player, Segmented, Select, Skeleton, SplitButton, Tabs, Toggle, Transcript, type Segment } from "../components";
import { ContextMenu, Menu, type MenuItem } from "../components/Menu";
import type { TranscriptMenuEvent } from "../components/media";
import { contextItems } from "../actions/contextMenus";
import { currentPlatform, popupNative } from "../actions/popup";
import { choiceItems, type ActionContext } from "../actions/registry";
import { useActions } from "../actions/useActions";
import { SourceAvatar } from "./SourceAvatar";
import { rowState, STATE_WORDS } from "./model";
import { aiHeader, LanguageDialog, recordingExportItems, RenameDialog, sayProblem, TranscribeDialog, WhatsAppDialog, type Say } from "./organise";
import type { RecordingMenuParts } from "./LibraryV2";
import { lineDir, useT, withParts, wordsIn, type Lang } from "../../i18n";

type Tab = "transcript" | "notes" | "details";

/** A transcript still being made (queued, waiting for the engine, in parts). */
const IN_FLIGHT = new Set(["pending", "submitted", "processing"]);
const isTranscribing = (status?: string | null) => !!status && IN_FLIGHT.has(status);

/** The primary recording's size from the session's detail, when the server says it. */
export function primarySize(detail: Record<string, unknown>): number | null {
  const recordings = Array.isArray(detail.recordings) ? (detail.recordings as Record<string, unknown>[]) : [];
  const primary = recordings.find((r) => r.role === "primary") ?? recordings[0];
  const size = primary?.size_bytes;
  return typeof size === "number" && size > 0 ? size : null;
}

/** Tooltips of what the engine was not sure of (kept as heard), as classic's: in the window's language, or English with
 * `lang` "en" (UNSURE_WORD and UNSURE_LINE, as the tests read them). */
export const unsureWord = (lang?: Lang) => wordsIn(lang).t("Not sure of this word. Click to hear it.");
export const unsureLine = (lang?: Lang) => wordsIn(lang).t("The engine was not sure of this line. Click to hear it.");
export const UNSURE_WORD = unsureWord("en");
export const UNSURE_LINE = unsureLine("en");
/**
 * The words with timings join back into the line's text, ignoring spaces, punctuation and capitals (engines send words
 * without the line's commas and full stops): only then are they drawn one by one.
 */
export function wordsMatch(words: readonly { text: string }[], text: string): boolean {
  // An apostrophe joins its word ("don't" is one word whether an engine sends "don't" or "dont"); other marks split.
  const squash = (t: string) => t.toLowerCase().replace(/['’‘`ʼ]/g, "").replace(/[^\p{L}\p{M}\p{N}]+/gu, " ").trim();
  return squash(words.map((w) => w.text).join(" ")) === squash(text);
}

/** No speaker names yet: one empty object, so what is worked out from them keeps while the page reads. */
const NO_NAMES: Record<string, string> = {};
/** Double-check: what a second transcription heard on a line it disagreed on (never the engine's name, D-132). */
export const secondHeard = (lang?: Lang) => wordsIn(lang).t("A second transcription heard:");
export const SECOND_HEARD = secondHeard("en");
type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; value: T };

export type RecordingPageProps = {
  item: LibraryItem;
  /** Open at a moment (a transcript match, a link): the player goes there and its line is shown. */
  startAt?: { ms: number; seq: number } | null;
  onBack: () => void;
  /** One message at a time, with at most one action (the Library's Toast). */
  /** Says something in the Library's toast (a problem through sayProblem). */
  onMessage: Say;
  /** Opens the recording's project (its name in the meta line). */
  onOpenProject?: (id: string) => void;
  /** The Library's one menu for this recording, with this page's parts in their places (Delete stays last). */
  menu: (own: RecordingMenuParts) => MenuItem[];
  /** What the page read about the transcript's views, for the recording's row in the Library. */
  onViews?: (views: TranscriptViewsState) => void;
  /** The recording changed here (renamed by its title, a label taken off): the Library shows it at once. */
  onItemChanged?: (item: Partial<LibraryItem> & { id: string }) => void;
};

const SPEEDS = ["0.5", "0.75", "1", "1.25", "1.5", "1.75", "2"];
/** Show as: the views in classic's order, the original words last, and their names (Urdu's in its own letters). */
const VIEW_ORDER: TranscriptView[] = ["roman", "urdu", "english"];
const viewLabel = (view: TranscriptView) => (view === "urdu" ? "اردو" : viewName(view));
const VIEWS_POLL_MS = 5_000;
type ShowAs = "original" | TranscriptView;

/** Keys the page leaves alone: typing, and anything inside a dialog or a menu. */
const inField = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || !!target.closest('[role="dialog"]:not(.el-drawer), [role="alertdialog"], [role="menu"], [role="listbox"]'));

export function RecordingPage({ item, startAt = null, onBack, onMessage, menu, onOpenProject, onViews, onItemChanged }: RecordingPageProps) {
  const { t, tc, rtl, lang } = useT();
  const [sharing, setSharing] = useState(false);
  // Rename by pressing the title (a click, a tap, or Enter on it): the Rename dialog, as the Library's Rename… and the
  // other apps have it, pre-filled only with the person's own title. Closed, the keyboard is back on the title.
  const [renaming, setRenaming] = useState(false);
  const titleButton = useRef<HTMLButtonElement>(null);
  const [tab, setTab] = useState<Tab>("transcript");
  const [detail, setDetail] = useState<Load<{ url: string | null; names: Record<string, string>; bookmarks: Bookmark[]; sizeBytes: number | null }>>({ state: "loading" });
  // An expired playback link is asked for again once (classic's); a second failure says so.
  const [playbackError, setPlaybackError] = useState<string | null>(null);
  const refreshedLink = useRef(false);
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
  // Which version is shown after Re-transcribe as: the transcript (newest), or the previous one while it is kept.
  const [version, setVersion] = useState<"final" | "alternate">("final");
  useEffect(() => setVersion("final"), [item.id]);
  const alternateId = item.transcript?.alternate_status === "completed" ? item.transcript?.alternate_transcript_id ?? null : null;
  const transcriptId = (version === "alternate" && alternateId) || (item.transcript?.final_transcript_id ?? item.transcript?.live_transcript_id ?? null);
  // After an edit: "Always write X as Y for Acme?" (one at a time), else the new words to add to the vocabulary.
  const [learn, setLearn] = useState<LearnOffer[]>([]);
  const [vocabTerms, setVocabTerms] = useState<string[]>([]);
  useEffect(() => {
    setLearn([]);
    setVocabTerms([]);
  }, [transcriptId]);

  useEffect(() => {
    let live = true;
    setDetail({ state: "loading" });
    setPlaybackError(null);
    refreshedLink.current = false;
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
          sizeBytes: primarySize(d),
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
  // A failed read never stops it (usePoll keeps asking, and waits while the window is hidden), so the converting line
  // can't spin for ever on one lost answer; the server says "failed" itself after 20 quiet minutes.
  const readViews = async () => {
    const id = tDetail?.id;
    if (!id) return;
    const v = await api.transcriptViews(id);
    if (shownTranscript.current === id) setViews(v);
  };
  useEffect(() => {
    if (tDetail?.id && viewsMaking && !views) void readViews().catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tDetail?.id, viewsMaking]);
  usePoll(!!tDetail?.id && viewsMaking, readViews, VIEWS_POLL_MS);
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
      sayProblem(onMessage, errorMessage(e));
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
      sayProblem(onMessage, errorMessage(e));
      if (viewPress.current === press) setShowAs("original");
    }
  };
  const viewText = (i: number): string | null => (showAs !== "original" && views?.status === "ready" ? views[showAs]?.[i] ?? null : null);
  const exportView = showAs !== "original" && views?.status === "ready" ? showAs : null;

  const names = detail.state === "ready" ? detail.value.names : NO_NAMES;
  const segments: Segment[] = useMemo(() => (transcript.state === "ready" && transcript.value
    ? transcript.value.segments.map((s, i) => ({ speaker: speakerName(names, s.speaker), start: s.start_ms / 1000, text: viewText(i) ?? s.text }))
    : []),
  // eslint-disable-next-line react-hooks/exhaustive-deps
  [transcript, names, showAs, views]);
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
    const before = transcript.state === "ready" ? transcript.value?.segments[index]?.text ?? "" : "";
    try {
      const { learn: offers, ...edited } = await api.editTranscript(id, index, text);
      if (!stillShown(id)) return;
      setTranscript({ state: "ready", value: edited });
      // For a project's recording the server names the words worth a rule; that offer takes the vocabulary's place.
      const rules = Array.isArray(offers) ? offers : [];
      setLearn(rules);
      setVocabTerms(rules.length ? [] : newTerms(before, text));
      onMessage(t("Line saved"));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    }
  };
  // Suggested fixes (since v1.4): open ones are marked in their line; one at a time opens with Accept and Dismiss. They
  // are decided only in the words as heard, never in a Show as view or while editing.
  const [openFix, setOpenFix] = useState<number | null>(null);
  const reviewButton = useRef<HTMLButtonElement>(null);
  const focusFix = useRef(false);
  const focusReview = useRef(false);
  const openFixAndFocus = (id: number) => {
    focusFix.current = true;
    setOpenFix(id);
  };
  const closeFix = () => {
    setOpenFix(null);
    focusReview.current = true;
  };
  // After the box opens from Review (or the next one after a decision): scrolled into view, the keyboard on Accept. After
  // Close, or the last decision: back on Review, or on the title when Review has gone with the last fix.
  useEffect(() => {
    if (focusFix.current && openFix !== null) {
      focusFix.current = false;
      const box = page.current?.querySelector<HTMLElement>(`[data-fix="${openFix}"]`);
      box?.scrollIntoView?.({ block: "center" });
      box?.querySelector<HTMLButtonElement>("button")?.focus();
    } else if (focusReview.current) {
      focusReview.current = false;
      (reviewButton.current ?? titleButton.current)?.focus();
    }
  });
  const [fixBusy, setFixBusy] = useState(false);
  const openFixes = useMemo(() => (tDetail?.suggestions ?? []).filter((f) => f.status === "open"), [tDetail]);
  const fixesShown = useMemo(() => (showAs === "original" && !editing ? openFixes : []), [showAs, editing, openFixes]);
  const pendingFix = fixesShown.find((f) => f.id === openFix) ?? null;
  const decideFix = async (fix: TranscriptSuggestion, accept: boolean) => {
    const id = shownTranscript.current;
    if (!id) return;
    setFixBusy(true);
    try {
      const next = await api.decideSuggestion(id, fix.id, accept);
      if (stillShown(id)) {
        setTranscript({ state: "ready", value: next });
        // The next open fix opens with the keyboard on its Accept; with none left, the keyboard goes back to Review.
        const left = (next.suggestions ?? []).filter((f) => f.status === "open").sort((a, b) => a.index - b.index);
        const after = left.find((f) => f.index >= fix.index) ?? left[0] ?? null;
        if (after) openFixAndFocus(after.id);
        else {
          setOpenFix(null);
          focusReview.current = true;
        }
      }
      onMessage(accept ? t("Changed to “:to”", { to: fix.to }) : t("Suggestion dismissed"));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    } finally {
      if (mounted.current) setFixBusy(false);
    }
  };
  /** A word the engine was not sure of plays from that word (its line's click would play from the line's start). */
  const playWord = (ms: number) => {
    seek(ms / 1000);
    void audio.current?.play().catch(() => undefined);
  };
  const canPlay = detail.state === "ready" && !!detail.value.url;
  /**
   * A line as heard: its suggested fixes marked, else its unsure words underlined (a press plays from the word), and on
   * the line being played the word heard now (`on`, -1 for none). Words are drawn only when they join back into the
   * line's text (an edited line keeps its words as written).
   */
  const heardText = (i: number, on = -1): ReactNode | undefined => {
    const raw = tDetail?.segments[i];
    if (!raw) return undefined;
    const fixes = fixesShown.filter((f) => f.index === i).map((f) => ({ f, span: suggestionSpan(raw.text, f.from) }))
      .filter((x): x is { f: TranscriptSuggestion; span: [number, number] } => !!x.span).sort((a, b) => a.span[0] - b.span[0]);
    if (fixes.length) {
      const out: ReactNode[] = [];
      let pos = 0;
      for (const { f, span: [from, to] } of fixes) {
        if (from < pos) continue;
        out.push(raw.text.slice(pos, from));
        out.push(
          <button key={f.id} type="button" className="el-suggest" aria-expanded={openFix === f.id}
            title={f.reason ? t("Suggested: :to (:reason)", { to: f.to, reason: f.reason }) : t("Suggested: :to", { to: f.to })}
            aria-label={t("Suggested fix: :from to :to", { from: raw.text.slice(from, to), to: f.to })} onClick={(e) => { e.stopPropagation(); setOpenFix(openFix === f.id ? null : f.id); }}>
            {raw.text.slice(from, to)}
          </button>,
        );
        pos = to;
      }
      out.push(raw.text.slice(pos));
      return out;
    }
    if (!raw.words?.length || !wordsMatch(raw.words, raw.text)) return undefined;
    // The line being played shows its words one by one: the word being heard now is marked (classic's).
    if (on < 0 && !raw.words.some(uncertainWord)) return undefined;
    const words = raw.words;
    return words.map((w, wi) => {
      const cls = ["el-word", uncertainWord(w) && "el-word--unsure", wi === on && "el-word--on"].filter(Boolean).join(" ");
      return (
        <span key={wi}>
          {uncertainWord(w) ? (
            // Out of the Tab order (a long transcript would be hundreds of stops): the line's time plays it from the keys.
            <button type="button" tabIndex={-1} className={cls} title={unsureWord()}
              aria-label={canPlay ? t(":word, not sure of this word, play it", { word: w.text }) : t(":word, not sure of this word", { word: w.text })}
              disabled={!canPlay} onClick={(e) => { e.stopPropagation(); playWord(w.start_ms); }}>{w.text}</button>
          ) : <span className={cls} data-on={wi === on || undefined}>{w.text}</span>}
          {wi < words.length - 1 ? " " : ""}
        </span>
      );
    });
  };
  /** A Show as view's "[?word]" marks drawn like the words as heard: underlined, without the brackets. */
  const viewMarks = (text: string): ReactNode | undefined => {
    const parts = text.split(/(\[\?[^\]]+\])/);
    if (parts.length === 1) return undefined;
    return parts.map((part, k) => {
      const marked = /^\[\?([^\]]+)\]$/.exec(part);
      return marked ? <span key={k} className="el-word el-word--unsure" title={unsureWord()}>{marked[1]}</span> : part;
    });
  };
  /** The words a line has in the transcript itself (never a Show as view's): what an edit starts from. */
  const rawText = (i: number) => (transcript.state === "ready" ? transcript.value?.segments[i]?.text ?? segments[i].text : segments[i].text);
  const lineText = (i: number, on = -1): ReactNode | undefined => {
    if (editing && editLine === i) return <LineEditor text={rawText(i)} onSave={(t) => void saveLine(i, t)} onCancel={() => setEditLine(null)} />;
    if (editing) return <button type="button" className="el-line-edit" aria-label={t("Edit line :n: :text", { n: i + 1, text: rawText(i) })} onClick={(e) => { e.stopPropagation(); setEditLine(i); }}>{rawText(i)}</button>;
    const inLine = matches.map((m, index) => ({ ...m, index })).filter((m) => m.seg === i);
    if (!inLine.length) return viewText(i) !== null ? viewMarks(segments[i].text) : heardText(i, on);
    return markParts(segments[i].text, inLine).map((p, k) => (p.match === null ? <span key={k}>{p.text}</span>
      : <mark key={k} className={p.match === findCurrent ? "el-find el-find--current" : "el-find"}>{p.text}</mark>));
  };
  // The line being played is marked once playing has started, not on a page just opened.
  const active = transcript.state === "ready" && transcript.value && (playing || position > 0) ? activeIndex(transcript.value.segments, position * 1000) : -1;
  const activeWordAt = active >= 0 && tDetail ? activeWord(tDetail.segments[active]?.words, position * 1000) : -1;
  // The screens at their moments: after the line that started at or before each one (the phone's rule).
  const rawSegments = transcript.state === "ready" && transcript.value ? transcript.value.segments : null;
  // The player's length: the server's, else the audio file's own (on loadedmetadata), else the last line's end.
  const durationS = Math.max(1, Math.round(playerLength(item.duration_ms, rawSegments, mediaSeconds) / 1000));
  const screensAfter = useMemo(() => screensByLine(rawSegments ?? [], screens ?? []), [rawSegments, screens]);
  // The waveform is drawn from the transcript (who speaks when), as classic's: decoding a long recording here would be slow.
  const waveBars = useMemo(() => waveformBars(rawSegments ?? [], durationS * 1000, WAVE_BARS), [rawSegments, durationS]);
  const lines = useMemo(() => (rawSegments ?? []).map((s) => ({ ...s, speaker: speakerName(names, s.speaker) })), [rawSegments, names]);
  const openScreen = (screen: ScreenMoment) => setScreenOpen(Math.max(0, (screens ?? []).findIndex((s) => s.id === screen.id)));
  const deleteScreen = async (screen: ScreenMoment) => {
    if (!(await confirmAction({ title: t("Delete screen :index?", { index: screen.index }), body: t("The recording and its transcript stay."), confirm: t("Delete"), tone: "danger" }))) return;
    try {
      await api.deleteScreen(item.id, screen.id);
      setScreenOpen(null);
      reloadScreens();
      onMessage(t("Screen :index deleted", { index: screen.index }));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    }
  };
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : null);
  // When, how long, then the project (its own button after this line).
  const metaParts = [source, formatWhen(item.started_at), formatDuration(item.duration_ms)].filter((p): p is string => !!p);
  const meta = metaParts.join(" · ");
  // A transcription waiting for its engine says when it tries again; a failed one says why.
  const engineRetry = tDetail && isTranscribing(tDetail.status) ? retryLine(tDetail) : null;
  const failureCode = tDetail?.status === "failed" ? tDetail.error_code ?? null : item.transcript?.final_error_code ?? null;
  const liveParts = partsText(item.transcript?.final_progress);
  const liveLine = tDetail?.kind === "live"
    ? (liveParts ? t("Live captions (final transcript pending · :parts)", { parts: liveParts }) : t("Live captions (final transcript pending)")) : null;
  const closeRename = () => {
    setRenaming(false);
    titleButton.current?.focus();
  };
  /** Try again after a failed transcription: the same language as before (classic's). */
  const tryAgain = async () => {
    const language = (item.language ?? null) as TranscriptionLanguage | null;
    setBusy(true);
    try {
      await api.retranscribe(item.id, language);
      onMessage(t("Re-transcribing in :language. The current transcript stays until the new one is ready.", { language: languageLabel(language) }));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  /** The preferred engine again, in the same language (the backup engine's Re-transcribe). */
  const retranscribeSame = tryAgain;
  const answerLearn = async (yes: boolean) => {
    const offer = learn[0];
    if (!offer) return;
    setLearn((list) => list.slice(1));
    if (!yes) return;
    try {
      await api.addReplacement(offer.from, offer.to, offer.client_id);
      onMessage(t(":project's transcripts will write “:to” from now on", { project: offer.client_name, to: offer.to }));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    }
  };
  const addToVocabulary = async (term: string) => {
    try {
      await api.addToVocabulary(term, item.client?.id ?? null);
      setVocabTerms((terms) => terms.filter((t) => t !== term));
      onMessage(t("Added “:term” to the vocabulary", { term }));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    }
  };
  /** A label's × on the page: the recording keeps its other labels ("Label removed"). */
  const removeLabel = async (label: string) => {
    try {
      const left = (item.labels ?? []).filter((l) => l !== label);
      const saved = await api.organiseSession(item.id, { labels: left });
      onItemChanged?.({ id: item.id, labels: saved?.data?.labels ?? left });
      onMessage(t("Label removed: :label", { label }));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    }
  };
  /** The fix box's buttons: Review and each decision put the keyboard there, or back on Review. */
  const fixBox = (fix: TranscriptSuggestion) => (
    <div className="el-suggest-pop" role="group" aria-label={t("Suggested fix")} data-fix={fix.id}>
      <span dir={rtl ? "rtl" : "auto"}>{withParts(t("Change :from to :to?"), { from: <b>{fix.from}</b>, to: <b>{fix.to}</b> })}{fix.reason ? <span className="el-secondary"> ({fix.reason})</span> : null}</span>
      <Button size="sm" variant="primary" loading={fixBusy} loadingLabel={t("Changing…")} onClick={() => void decideFix(fix, true)}>{t("Accept")}</Button>
      <Button size="sm" disabled={fixBusy} onClick={() => void decideFix(fix, false)}>{t("Dismiss")}</Button>
      <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label={t("Close")} onClick={() => closeFix()} />
    </div>
  );

  // Each line's words, note, screens, fix box and tooltip, worked out once and not on every tick of the player: only the
  // line being played is drawn again as it plays (its word heard now).
  const parts = useMemo(() => segments.map((_, i) => ({
    text: lineText(i),
    note: showAs !== "original" && views?.kept?.[showAs]?.includes(i) ? <span className="el-segment__note">· {t("original words")}</span>
      : viewText(i) === null && tDetail?.segments[i]?.check ? <span className="el-segment__check" dir="auto"><b>{secondHeard()}</b> {tDetail.segments[i].check!.text}</span> : null,
    title: viewText(i) === null && tDetail?.segments[i]?.uncertain ? unsureLine() : undefined,
    after: (() => {
      const at = screensAfter.get(i);
      const fix = pendingFix?.index === i ? fixBox(pendingFix) : null;
      return at || fix ? <>{fix}{at && <LineScreens screens={at} onOpen={openScreen} />}</> : null;
    })(),
  })),
  // eslint-disable-next-line react-hooks/exhaustive-deps
  [segments, editing, editLine, matches, findCurrent, tDetail, fixesShown, openFix, fixBusy, screensAfter, canPlay, showAs, views, lang]);
  const renderText = (i: number) => (i === active && activeWordAt >= 0 ? lineText(i, activeWordAt) : parts[i]?.text);
  const audioError = () => {
    if (refreshedLink.current) return setPlaybackError(playbackExpired());
    refreshedLink.current = true;
    const failed = detail.state === "ready" ? detail.value.url : null;
    api.session(item.id).then((d) => {
      const next = typeof d.playback_url === "string" ? d.playback_url : null;
      if (next && next !== failed) setDetail((s) => (s.state === "ready" ? { state: "ready", value: { ...s.value, url: next } } : s));
      else setPlaybackError(playbackExpired());
    }).catch(() => setPlaybackError(playbackExpired()));
  };
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
      onMessage(forAi ? t("Copied for AI, with the note on unsure words") : withTimestamps ? t("Copied with timestamps") : t("Copied"));
    } catch {
      sayProblem(onMessage, t("Could not access the clipboard"));
    }
  };
  // ⌘P on a Mac, Ctrl+P elsewhere: print the transcript (its print page, where Save as PDF is too), not the window
  // (FIX-10). Heard on the document, before the window's guard; never while typing (a field keeps its keys; Ctrl+P
  // moves up a line on a Mac) or behind a dialog.
  const printNow = useRef<() => void>(() => undefined);
  printNow.current = () => void exportAs("html");
  useEffect(() => {
    const mac = /mac/i.test(navigator.platform || navigator.userAgent);
    const onKey = (e: KeyboardEvent) => {
      const mod = mac ? e.metaKey && !e.ctrlKey : e.ctrlKey && !e.metaKey;
      // The P key whatever the layout types with it (Urdu, Arabic: e.code), as LibraryNav does.
      const p = e.key.toLowerCase() === "p" || (e.key.length === 1 && !/^[a-z0-9]$/i.test(e.key) && e.code === "KeyP");
      if (e.defaultPrevented || !mod || e.altKey || e.shiftKey || !p) return;
      const target = e.target instanceof HTMLElement ? e.target : null;
      if (target && (target.isContentEditable || target.closest("input, textarea, select"))) return;
      if (document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      e.preventDefault();
      // Held down, it repeats: printed once.
      if (e.repeat) return;
      printNow.current();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, []);
  const exportAs = async (format: ExportFormat) => {
    if (!tDetail) return;
    try {
      const path = await api.exportTranscript(tDetail.id, format, true, true, exportView);
      if (format === "html") onMessage(t("Opened the print page in your browser: choose Save as PDF there"));
      else if (path) onMessage(t("Saved to :path", { path }));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
    }
  };
  const packAs = async (format: MeetingPackFormat, withAudio: boolean) => {
    try {
      const path = await api.meetingPack(item.id, format, exportView, withAudio);
      if (format === "pdf") onMessage(t("Opened the meeting pack in your browser: choose Save as PDF there"));
      else if (path) onMessage(t("Saved to :path", { path }));
    } catch (e) {
      sayProblem(onMessage, errorMessage(e));
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
    view: exportView ? viewLabel(exportView) : null,
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }), [exportView, item.screen_count, lang]);
  const exportWord = t("Export");
  const recordingMenu = useMemo(() => menu({
    organise: canChangeLanguage ? [{ label: t("Change language and re-transcribe…"), icon: Icons.LanguagesIcon, onPick: () => setLanguageOpen(true) }] : [],
    export: canExport ? { label: exportWord, icon: Icons.DownloadIcon, group: exportWord, items: exportItems } : undefined,
    export_more: [{ label: t("Save audio"), icon: Icons.StorageIcon, onPick: () => void act(async () => { if (await api.saveAudio(item.id)) onMessage(t("Audio saved")); }) }],
    whatsapp: () => setWhatsApp(true),
    ai: () => void copyRef.current(false, true),
  }).filter((i) => canExport || i.label !== exportWord),
  // eslint-disable-next-line react-hooks/exhaustive-deps
  [menu, canChangeLanguage, canExport, exportItems, item.id, lang]);
  const sendToWhatsApp = () => setWhatsApp(true);

  const toggle = () => {
    const a = audio.current;
    if (!a) return;
    if (a.paused) void a.play().catch(() => sayProblem(onMessage, t("The audio didn't play. Try again in a moment.")));
    else a.pause();
  };
  const seek = (s: number) => {
    const to = Math.max(0, Math.min(durationS, s));
    setPosition(to);
    if (audio.current) audio.current.currentTime = to;
  };
  // One callback for every line's time (lines are drawn again only when their own parts change).
  const playFromRef = useRef<(i: number) => void>(() => undefined);
  const playLine = useCallback((i: number) => playFromRef.current(i), []);
  const playFrom = (i: number) => {
    seek(segments[i]?.start ?? 0);
    void audio.current?.play().catch(() => undefined);
  };
  playFromRef.current = playFrom;

  // UX-22 (3b): the player's, a line's, a speaker's and selected words' right-click menus, from actions.json. On a Mac
  // selected words keep the system's own text menu (Look Up, Translate, Copy, Share…); elsewhere ours: Copy · Copy with
  // time · Play from here · Select all.
  const [pageMenu, setPageMenu] = useState<{ label: string; at: { x: number; y: number }; items: MenuItem[] } | null>(null);
  const showPageMenu = (label: string, at: { x: number; y: number }, items: MenuItem[]) => {
    if (!items.length) return;
    if (currentPlatform() !== "macos") return setPageMenu({ label, at, items });
    void popupNative(items, at).then((shown) => { if (!shown) setPageMenu({ label, at, items }); });
  };
  const copyWords = (text: string, said = t("Copied")) => void navigator.clipboard?.writeText(text).then(() => onMessage(said)).catch(() => sayProblem(onMessage, t("Couldn't copy.")));
  useActions("transcript", {
    // From a line's or selected words' start (state.at), or the waveform's moment (value).
    "player.playFromHere": (c) => {
      seek(Number(c.state?.at ?? c.value) || 0);
      void audio.current?.play().catch(() => undefined);
    },
    "speaker.rename": () => setTool("speakers"),
    "speaker.mergeWith": (c) => {
      const [from, to] = (c.value ?? "").split("\u0000");
      if (!from || !to) return;
      const merged = { ...names, [from]: speakerName(names, to) };
      void api.renameSpeakers(item.id, merged, false).then((r) => {
        if (mounted.current) setDetail((d) => (d.state === "ready" ? { state: "ready", value: { ...d.value, names: r.speaker_names } } : d));
        onMessage(t("Merged into :name", { name: speakerName(names, to) }));
      }).catch((e) => sayProblem(onMessage, errorMessage(e)));
    },
    "edit.copy": (c) => { if (c.value) copyWords(c.value); },
    "text.copyWithTime": (c) => {
      const at = Number(c.state?.at) || 0;
      if (c.value) copyWords(`[${timestamp(at * 1000)}] ${c.value}`, t("Copied with its time"));
    },
    "edit.selectAll": () => {
      const list = document.querySelector<HTMLElement>('ol.el-transcript[aria-label="Transcript"]');
      const selection = window.getSelection?.();
      if (list && selection) selection.selectAllChildren(list);
    },
  });
  /** A right-click in the transcript: selected words, a speaker's name, or a line. */
  const transcriptMenu = (e: TranscriptMenuEvent) => {
    const line = segments[e.index];
    if (!line) return;
    const platform = currentPlatform();
    const ctx: ActionContext = { platform, kind: "transcript", state: { hasAudio: canPlay, hasTranscript: true, at: String(line.start) } };
    if (e.selected) {
      if (platform === "macos") return;
      e.preventDefault();
      showPageMenu(t("Selected words"), e.at, contextItems(["edit.copy", "text.copyWithTime", "text.ask", "-", "player.playFromHere", "-", "edit.selectAll"],
        { ...ctx, value: e.selected }));
      return;
    }
    e.preventDefault();
    if (e.onSpeaker) {
      const label = rawSegments?.[e.index]?.speaker;
      const others = speakerLabels(rawSegments ?? []).filter((l) => l !== label);
      showPageMenu(line.speaker, e.at, contextItems(["speaker.rename", "speaker.mergeWith"], ctx, {
        "speaker.mergeWith": label ? choiceItems("speaker.mergeWith", others.map((o) => ({ value: `${label}\u0000${o}`, label: speakerName(names, o) })), ctx) : [],
      }));
      return;
    }
    showPageMenu(t("Line at :time", { time: timestamp(line.start * 1000) }), e.at, contextItems(["player.playFromHere"], { ...ctx, value: String(line.start) }));
  };
  // The selected-words bar (3b), on every platform: [Play from here] [Ask about this] [Copy with time] [Share] above the
  // words selected in the transcript (what can't run here is left out).
  const [picked, setPicked] = useState<{ text: string; at: number; x: number; y: number } | null>(null);
  useEffect(() => {
    const changed = () => {
      const selection = typeof window.getSelection === "function" ? window.getSelection() : null;
      const list = document.querySelector('ol.el-transcript[aria-label="Transcript"]');
      const node = selection?.anchorNode ?? null;
      const text = selection && !selection.isCollapsed ? selection.toString().trim() : "";
      if (!selection || !text || !list || !node || !list.contains(node)) return setPicked(null);
      const li = (node instanceof Element ? node : node.parentElement)?.closest("li.el-segment");
      const index = li ? [...list.querySelectorAll(":scope > li.el-segment")].indexOf(li) : -1;
      const range = selection.rangeCount ? selection.getRangeAt(0) : null;
      const box = range && typeof range.getBoundingClientRect === "function" ? range.getBoundingClientRect() : null;
      setPicked({ text, at: segments[index]?.start ?? 0, x: box ? box.left + box.width / 2 : 0, y: box?.top ?? 0 });
    };
    // One update a frame, on a change of the selection and on any scroll (the bar follows the words it is over).
    let frame = 0;
    const soon = () => {
      if (!frame) frame = requestAnimationFrame(() => { frame = 0; changed(); });
    };
    document.addEventListener("selectionchange", soon);
    window.addEventListener("scroll", soon, true);
    return () => {
      document.removeEventListener("selectionchange", soon);
      window.removeEventListener("scroll", soon, true);
      cancelAnimationFrame(frame);
    };
  }, [segments]);
  const pickedItems = picked ? contextItems(["player.playFromHere", "text.ask", "text.copyWithTime", "text.share"],
    { platform: currentPlatform(), kind: "transcript", value: picked.text, state: { hasAudio: canPlay, hasTranscript: true, at: String(picked.at) } }) : [];
  /** The waveform's right-click: Play from here, at the moment under the pointer. */
  const waveMenu = (ms: number, at: { x: number; y: number }) =>
    showPageMenu(t("Waveform"), at, contextItems(["player.playFromHere"], { platform: currentPlatform(), kind: "transcript", value: String(ms / 1000), state: { hasAudio: canPlay } }));
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
      sayProblem(onMessage, errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  /** Where it was recorded, in words (an id the app doesn't know yet as it is). */
  const deviceWords = (device: string) => (device === "phone" ? t("Phone") : device === "desktop" ? t("Computer") : device === "web" ? t("Web") : device);

  if (detail.state === "error" && transcript.state !== "ready") {
    return (
      <div className="el-page">
        <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{t("Library")}</Button>
        <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("This recording didn't open")} body={detail.message}
          action={<Button variant="primary" onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
      </div>
    );
  }

  return (
    <div className="el-page" ref={page}>
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack} aria-keyshortcuts="Escape">{t("Library")}</Button>
      <header className="el-page__head el-page__head--top">
        <div className="el-page__titles">
          <span className="el-visually-hidden" id="el-recording-rename-hint">{t("Press the title to rename the recording")}</span>
          <h1 className="el-title-l" dir="auto">
            <button type="button" ref={titleButton} className="el-recording__title" title={t("Rename")} aria-describedby="el-recording-rename-hint" aria-haspopup="dialog"
              onClick={(e) => {
                // A Mac doesn't focus a button on a click: the dialog gives the keyboard back to what had it when it opened.
                e.currentTarget.focus();
                setRenaming(true);
              }}>
              {item.title?.trim() || t("Recording")}
            </button>
          </h1>
          <p className="el-page__meta el-secondary">
            <SourceAvatar item={item} size="sm" />
            {/* Pieces joined by dots: in Urdu the list reads right to left from its first piece, each piece in its own
                isolate (a Latin app name and a number would otherwise run together left to right); English as always. */}
            <span dir={rtl ? "rtl" : "auto"}>{rtl ? metaParts.flatMap((part, i) => [i ? " · " : null, <bdi key={i}>{part}</bdi>]) : meta}</span>
            {item.client && (onOpenProject
              ? <Button size="sm" variant="quiet" icon={Icons.FolderIcon} onClick={() => onOpenProject(item.client!.id)} aria-label={t("Open project :name", { name: item.client.name })}>{item.client.name}</Button>
              : <span dir="auto">{item.client.name}</span>)}
            {item.title_auto && item.title?.trim() && <Badge>{t("Automatic title")}</Badge>}
            {state === "ready" ? <Badge tone="success">{t("Transcribed")}</Badge>
              : state === "failed" ? <Badge tone="danger">{t("Transcription failed")}</Badge>
                : state === "no_audio" ? <Badge>{t("Nothing was heard")}</Badge>
                  : item.transcript?.final_status ? <Badge tone="accent">{t("Transcribing…")}</Badge> : <Badge>{rowState(item)?.text ?? STATE_WORDS.audio_only}</Badge>}
          </p>
          {(item.labels?.length ?? 0) > 0 && (
            <ul className="el-recording__labels" aria-label={t("Labels")}>
              {item.labels!.map((l) => <li key={l}><LabelChip name={l} colour={item.label_colors?.[l] ?? null} onRemove={() => void removeLabel(l)} /></li>)}
            </ul>
          )}
          <RecordingStepsV2 item={item} improving={barView !== null && tDetail?.kind === "final"} say={onMessage} />
          {engine && <p className="el-caption el-secondary">{t("Transcribed in :engine", { engine })}</p>}
          {tDetail?.kind === "final" && tDetail.status === "completed" && version === "final" && usedBackupEngine(tDetail) && !isTranscribing(item.transcript?.final_status) && (
            <BackupEngineLine busy={busy} onRetranscribe={() => void retranscribeSame()} />
          )}
          <FiledByLineV2 item={item} />
          {/* What the plan leaves out here, each with the plan that has it (classic's locked chips; none when all are in). */}
          <p className="el-recording__locked el-caption" data-testid="locked-chips">
            {item.transcript?.final_status && <LockedChip feature="premium_engines" label={t("Premium engines")} />}
            {tDetail?.kind === "final" && <LockedChip feature="views" label={`${viewLabel("roman")} · ${viewLabel("english")} · ${viewLabel("urdu")}`} />}
            {tDetail?.kind === "final" && <LockedChip feature="double_check" label={t("Double-check")} />}
            {!screens?.length && <LockedChip feature="screen_moments" label={t("Screens")} />}
            <LockedChip feature="connector" label={t("Ask an AI assistant")} />
          </p>
          {engineRetry && <p className="el-caption el-secondary" role="status" dir={lineDir(engineRetry)}>{engineRetry}</p>}
          {warning && <p className="el-caption el-row__error" role="status" dir={lineDir(warning)}>{warning}</p>}
        </div>
        <div className="el-page__actions">
          <Button variant="quiet" icon={Icons.LinkIcon} onClick={() => setSharing(true)} title={t("Share a view-only link or send to a teammate")}>{t("Share")}</Button>
          <Menu label={t("Recording actions")} items={recordingMenu} button={{ variant: "quiet", icon: Icons.MoreIcon, title: t("More") }} />
        </div>
      </header>
      {screens && screens.length > 0 && <ScreensStrip screens={screens} onOpen={setScreenOpen} />}
      <Card className="el-page__player">
        {detail.state === "loading" ? (
          <Loading label={t("Loading the audio")}><Skeleton shape="block" /></Loading>
        ) : (
          <>
            {detail.state === "ready" && detail.value.url && (
              <audio ref={audio} src={detail.value.url} preload="metadata" onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}
                onLoadedMetadata={(e) => setMediaSeconds(e.currentTarget.duration)}
                onEnded={() => setPlaying(false)} onTimeUpdate={(e) => timeUpdate(e.currentTarget)} onError={audioError} />
            )}
            {playbackError && <Banner tone="warning" icon={Icons.WarningIcon}>{playbackError}</Banner>}
            <Waveform bars={waveBars} marks={bookmarks.map((b) => ({ id: b.id, atMs: b.at_ms, title: `${timestamp(b.at_ms)}${b.tag ? ` ${b.tag}` : ""}${b.note ? ` · ${b.note}` : ""}` }))}
              durationMs={durationS * 1000} nowMs={position * 1000} onSeek={(ms) => seek(ms / 1000)} onMenu={waveMenu} />
            <Player playing={playing} position={Math.round(position)} duration={durationS} onToggle={toggle} onSeek={seek}
              onSkip={(s) => seek(position + s)}
              end={<Select label={t("Playback speed")} hideLabel value={speed} onChange={setSpeed} className="el-page__speed"
                options={SPEEDS.map((v) => ({ value: v, label: `${v}×`, dir: "ltr" as const }))} />} />
            <div className="el-recording__toggles">
              <Toggle label={t("Skip silence")} checked={skipSilence} onChange={setSkipSilence} />
              <Toggle label={t("Follow")} checked={follow} onChange={setFollow} />
              <span className="el-caption el-secondary el-recording__keys">{playerHint()}</span>
            </div>
            {bookmarks.length > 0 && (
              <ul className="el-bookmarks" aria-label={t("Bookmarks")}>
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
        <div className="el-recording__actions" role="toolbar" aria-label={t("Share, copy and export")}>
          <Button icon={Icons.SendIcon} disabled={busy} onClick={sendToWhatsApp}>{t("Send to WhatsApp…")}</Button>
          {/* Copy: the main part copies the text without timestamps at once; the chevron has the other ways. */}
          <SplitButton label={t("Copy")} icon={Icons.CopyIcon} mainLabel={t("Copy text")} onClick={() => void copy(false)} menuLabel={t("More copy options")}
            items={copyMenuItems({ withTimestamps: () => void copy(true), forAi: () => void copy(false, true) })} />
          <Menu label={exportView ? t("Export as :view", { view: viewLabel(exportView) }) : exportWord} items={exportItems}
            button={{ icon: Icons.DownloadIcon, iconEnd: Icons.ChevronDownIcon, text: exportView ? t("Export as :view", { view: viewLabel(exportView) }) : exportWord, title: pdfHint() }} />
        </div>
      )}
      <Tabs label={t("Recording")} value={tab} onChange={setTab} tabs={[{ value: "transcript", label: t("Transcript") }, ...(item.client ? [{ value: "notes" as Tab, label: t("Call notes") }] : []), { value: "details", label: t("Details") }]}>
        {tab === "notes" ? (
          <CallNotesV2 sessionId={item.id} say={onMessage} onSeek={(ms) => { setTab("transcript"); seek(ms / 1000); void audio.current?.play().catch(() => undefined); }} />
        ) : tab === "transcript" ? (
          transcript.state === "loading" ? (
            <Loading label={t("Loading the transcript")}>
              {Array.from({ length: 4 }, (_, i) => <Skeleton key={i} width={i % 2 ? "medium" : "long"} />)}
            </Loading>
          ) : transcript.state === "error" ? (
            <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("The transcript didn't load")} body={transcript.message}
              action={<Button onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
          ) : segments.length > 0 ? (
            <>
              {alternateId && (
                <div className="el-recording__viewbar">
                  <Segmented label={t("Version")} value={version} onChange={(v) => { setEditing(false); setVersion(v); }}
                    options={[{ value: "final" as const, label: t("Current") },
                      { value: "alternate" as const, label: item.transcript?.alternate_language ? t("Previous (:language)", { language: languageLabel(item.transcript.alternate_language) }) : t("Previous") }]} />
                </div>
              )}
              {liveLine && <p className="el-caption el-secondary" role="status" data-testid="live-line" dir={lineDir(liveLine)}>{liveLine}</p>}
              {(viewsInfo?.available.length ?? 0) > 0 && tDetail?.kind === "final" && (
                <div className="el-recording__viewbar">
                  {editing ? <span className="el-caption el-secondary">{t("Show as is off while you edit: you edit the words as heard.")}</span> : <Segmented label={t("Show as")} value={showAs} onChange={(v) => void chooseView(v)}
                    options={[...VIEW_ORDER.filter((v) => viewsInfo!.available.includes(v)).map((v) => ({ value: v as ShowAs, label: viewLabel(v) })),
                      { value: "original" as ShowAs, label: t("Original") }]} />}
                  {barView && <ConvertingLine view={barView} percent={viewPercent(views?.progress ?? viewsInfo?.progress, barView)} />}
                  {!barView && failedView && <ConvertingLine view={failedView} failed retrying={retrying} onRetry={() => void retryViews()} />}
                  {/* The converting line says it while the view is written or could not be (U19): no second caption then. */}
                  {showAs !== "original" && !barView && !failedView && (
                    <span className="el-caption el-secondary" role="status">
                      {views?.status === "ready" ? t(":view view. The transcript itself is unchanged.", { view: viewLabel(showAs) })
                        : views?.status === "failed" ? (views.error ? t("The view could not be made: :reason", { reason: views.error }) : t("The view could not be made: try again later."))
                          : t("Writing the :view view… this takes a minute or two.", { view: viewLabel(showAs) })}
                    </span>
                  )}
                </div>
              )}
              {canEdit && (
                <div className="el-recording__viewbar" role="toolbar" aria-label={t("Transcript tools")}>
                  <span className="el-caption el-secondary">{transcript.state === "ready" && transcript.value?.edited_at ? t("Final transcript · edited") : t("Final transcript")}</span>
                  <Button size="sm" variant="quiet" icon={Icons.SearchIcon} aria-pressed={findOpen} aria-keyshortcuts="Meta+F Control+F" onClick={() => setFindOpen(!findOpen)}>{t("Find")}</Button>
                  <Button size="sm" variant="quiet" icon={Icons.RenameIcon} aria-pressed={editing} onClick={() => { if (!editing) void chooseView("original"); setEditing(!editing); setEditLine(null); }}>{editing ? t("Done editing") : t("Edit text")}</Button>
                  <Button size="sm" variant="quiet" onClick={() => setTool("replace")}>{t("Find and replace")}</Button>
                  <Button size="sm" variant="quiet" icon={Icons.UserIcon} onClick={() => setTool("speakers")}>{t("Speakers")}</Button>
                  <Button size="sm" variant="quiet" icon={Icons.ClockIcon} onClick={() => setTool("history")}>{t("History")}</Button>
                </div>
              )}
              {findOpen && <FindBar query={findQuery} onQuery={setFindQuery} count={matches.length} current={Math.max(0, findCurrent)}
                onStep={(d) => matches.length && setFindAt((findCurrent + d + matches.length) % matches.length)} onClose={() => { setFindOpen(false); setFindQuery(""); }} />}
              {learn[0] && <LearnOfferV2 key={`${learn[0].from}-${learn[0].to}`} offer={learn[0]} onAnswer={(yes) => void answerLearn(yes)} />}
              {!learn.length && <VocabularyOfferV2 terms={vocabTerms} projectName={item.client?.name ?? null} onAdd={(t) => void addToVocabulary(t)} onDismiss={() => setVocabTerms([])} />}
              {fixesShown.length > 0 && (
                <p className="el-suggestions el-caption" role="status" dir={rtl ? "rtl" : "auto"}>
                  <span>{tc(":count suggested fix: words that may have been misheard are underlined.|:count suggested fixes: words that may have been misheard are underlined.", fixesShown.length)}</span>
                  <Button ref={reviewButton} size="sm" variant="quiet" icon={Icons.SparkleIcon} onClick={() => openFixAndFocus(fixesShown[0].id)}>{t("Review")}</Button>
                </p>
              )}
              <Transcript label={t("Transcript")} grouped segments={segments} renderText={renderText} activeIndex={active >= 0 ? active : undefined} onPlayLine={canPlay ? playLine : undefined}
                onMenu={transcriptMenu}
                lang={showAs === "urdu" ? "ur" : showAs === "original" ? normalizeLanguage(tDetail?.language) ?? undefined : undefined}
                note={(i) => parts[i]?.note} lineTitle={(i) => parts[i]?.title} after={(i) => parts[i]?.after} />
              {/* The selected words' bar, right after the transcript (Tab reaches it from the words). */}
              {picked && pickedItems.length > 0 && (
                // A press here keeps the selection (the words stay chosen while the bar acts on them).
                <div role="toolbar" aria-label={t("Selected words")} className="el-selection-bar" style={{ left: picked.x, top: picked.y }} onMouseDown={(e) => e.preventDefault()}>
                  {pickedItems.map((i) => (
                    <Button key={i.action} size="sm" variant="quiet" icon={i.icon} data-action={i.action} onClick={() => i.onPick?.()}>{i.label}</Button>
                  ))}
                </div>
              )}
            </>
          ) : transcript.state === "ready" && transcript.value?.status === "completed" && transcript.value.segments.length === 0 ? (
            <EmptyState icon={Icons.TranscriptIcon} title={t("The transcript is empty")} body={t("No speech was detected in this recording.")} />
          ) : state === "processing" && item.transcript?.final_status ? (
            <EmptyState icon={Icons.TranscriptIcon} title={t("Transcribing")}
              body={`${transcribingLine(item.transcript?.final_progress ?? tDetail?.progress)} ${t("The transcript appears here when it is ready. You can keep working meanwhile.")}`} />
          ) : state === "no_audio" ? (
            <EmptyState icon={Icons.MicIcon} title={t("Nothing was heard")} body={t("The recording has no sound to transcribe. Check the microphone and system audio before the next call.")} />
          ) : (
            <EmptyState icon={Icons.TranscriptIcon} title={state === "failed" ? t("The transcription failed") : rowState(item)?.text ?? STATE_WORDS.audio_only}
              body={state === "failed" ? (failureCode === "needs_transcription_key" ? needsKeyText()
                : needsKeyAction(failureCode) ? failureLine(failureCode) : `${failureLine(failureCode)} ${t("Transcribe it again to read, search and share what was said.")}`)
                : item.duration_ms ? t("Transcribe this recording to read, search and share what was said. It is :length long.", { length: formatDuration(item.duration_ms) })
                  : t("Transcribe this recording to read, search and share what was said.")}
              action={state === "failed" && needsKeyAction(failureCode)
                ? <Button variant="primary" icon={Icons.SettingsIcon} onClick={() => openSettingsSection("transcription-key")}>{failureCode === "own_key_rejected" ? t("Check my Groq key") : t("Add my Groq key")}</Button>
                : state === "failed" && canTryAgain(failureCode)
                ? <Button variant="primary" loading={busy} loadingLabel={t("Starting…")} onClick={() => void tryAgain()}>{t("Try again")}</Button>
                : <Button variant="primary" loading={busy} loadingLabel={t("Starting…")} onClick={() => setTranscribeOpen(true)}>{t("Transcribe")}</Button>} />
          )
        ) : (
          <dl className="el-details">
            <dt>{t("Recorded")}</dt><dd>{formatWhen(item.started_at) || t("Unknown")}</dd>
            <dt>{t("Length")}</dt><dd className="el-num">{formatDuration(item.duration_ms) || t("Unknown")}</dd>
            {source && <><dt>{t("Source")}</dt><dd>{source}</dd></>}
            {item.source_device && <><dt>{t("Recorded on")}</dt><dd>{deviceWords(item.source_device)}</dd></>}
            {item.device?.name && <><dt>{t("Device")}</dt><dd>{item.device.name}</dd></>}
            {item.client?.name && <><dt>{t("Project")}</dt><dd>{item.client.name}</dd></>}
            {item.language_chip && <><dt>{t("Language")}</dt><dd>{item.language_chip}</dd></>}
            {detail.state === "ready" && detail.value.sizeBytes ? <><dt>{t("File size")}</dt><dd className="el-num">{fileSize(detail.value.sizeBytes)}</dd></> : null}
            {item.transcript?.final_provider_label || item.transcript?.final_provider
              ? <><dt>{t("Transcription engine")}</dt><dd>{item.transcript.final_provider_label || providerName(item.transcript.final_provider)}</dd></> : null}
            <dt>{t("Kept")}</dt><dd>{item.keep ? t("Forever") : t("Until auto clean-up (Settings › Storage)")}</dd>
            <dt>{t("Labels")}</dt><dd>{item.labels?.length ? item.labels.join(", ") : t("None")}</dd>
            <dt>{t("Pinned")}</dt><dd>{item.pinned ? t("Yes") : t("No")}</dd>
          </dl>
        )}
      </Tabs>
      {whatsApp && <WhatsAppDialog item={item} onClose={() => setWhatsApp(false)} say={onMessage}
        text={lines.length ? copiedTranscript(lines, viewText, false, unsure) : null} hasAudio={detail.state === "ready" && !!detail.value.url} />}
      {tool === "replace" && transcript.state === "ready" && transcript.value && (
        <ReplaceDialog transcriptId={transcript.value.id} onClose={() => setTool(null)} say={onMessage}
          onReplaced={showTranscript} />
      )}
      {pageMenu && <ContextMenu label={pageMenu.label} items={pageMenu.items} at={pageMenu.at} onClose={() => setPageMenu(null)} />}
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
          view={exportView} onClose={() => setSharing(false)} notify={(t, error) => onMessage(t, undefined, error)} />
      )}
      {transcribeOpen && <TranscribeDialog ids={[item.id]} onClose={() => setTranscribeOpen(false)} say={onMessage} />}
      {renaming && (
        <RenameDialog item={item} onClose={closeRename} say={onMessage}
          onRenamed={(saved) => onItemChanged?.({ id: item.id, title: saved.title, title_auto: saved.title_auto ?? false, title_source: saved.title_source })} />
      )}
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
