// A recording a teammate sent, open read-only: every control classic's Library keeps for it (LibraryView with
// `readOnly`), in v2 parts as the v2 recording page (RecordingPage.tsx) has them. The head: the source and device, the
// title, when it was recorded, its length and project, "Transcribed in", a retry or script note, and who sent it with
// their note. The player: play, back and forward 10 s, the position, speed, Skip silence, Follow, the keys, and the
// bookmarks. The transcript: its kind ("Final transcript · edited", "Live captions…"), Show as when the owner's views are
// ready (never made from here), Find (Cmd/Ctrl+F), Copy ▾ (text, with timestamps, for AI), and its lines with the words
// a second transcription heard. Nothing here changes it: Save to my library makes a copy that can be changed, and
// Remove takes it off Shared with me. Same commands as classic: session_detail, transcript_detail, transcript_views.
import { useEffect, useMemo, useRef, useState } from "react";
import { api, errorMessage, type SharedItem, type TranscriptDetail, type TranscriptView, type TranscriptViewsState } from "../../lib/ipc";
import { copiedTranscript, DEFAULT_UNSURE, formatDuration, formatWhen, sourceAppLabel, timestamp, type UnsureMarkers } from "../../lib/format";
import { failureLine, needsKeyAction, normalizeLanguage, partsText, retryLine, scriptWarning, transcribedIn, transcriptStatusLine } from "../../lib/languages";
import { openSettingsSection } from "../../lib/entitlements";
import { NEEDS_KEY_TEXT } from "../../views/main/Upgrade";
import { PLAYER_HINT, activeIndex, findMatches, KEY_SECONDS, markParts, playerKey, playerLength, skipTarget, speakerName } from "../../lib/player";
import { transcribingLine } from "../../lib/progress";
import { NO_AUDIO_HINT } from "../../lib/pending";
import { bookmarksOf, type Bookmark } from "../../lib/library";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { sentLine } from "../../views/main/SharedWithMe";
import { Badge, Banner, Button, Card, EmptyState, Icons, Loading, Player, Segmented, Select, Skeleton, Toggle, Transcript, type Segment } from "../components";
import { Menu } from "../components/Menu";
import { SourceAvatar } from "../library/SourceAvatar";
import { FindBar } from "../library/TranscriptToolsV2";
import { aiHeader } from "../library/organise";
import { sharedTitle } from "./model";

type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; value: T };
type ShowAs = "original" | TranscriptView;

/** The words under the sender's line: what this view lets you do. */
export const READ_ONLY_LINE = "You can play and read it. Save to my library makes a copy you can change.";
/** Classic's words when the signed audio link has run out and a fresh one could not be had. */
export const PLAYBACK_EXPIRED = "Playback link expired. Open the recording again.";
/** Classic's words before what a second transcription heard on a line (never the engine's name). */
const SECOND_HEARD = "A second transcription heard:";
const SPEEDS = ["0.5", "0.75", "1", "1.25", "1.5", "1.75", "2"];
const VIEW_ORDER: TranscriptView[] = ["roman", "urdu", "english"];
const VIEW_LABELS: Record<TranscriptView, string> = { roman: "Roman Urdu", english: "English", urdu: "اردو" };
const IN_FLIGHT = new Set(["pending", "submitted", "processing"]);
const isTranscribing = (status?: string | null) => !!status && IN_FLIGHT.has(status);
const DEVICE_WORDS: Record<string, string> = { phone: "Phone", desktop: "Computer", web: "Web" };

/** Keys the page leaves alone: typing, and anything inside a dialog or a menu. */
const inField = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName)
    || !!target.closest('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]'));

export function SharedReader({ item, busy, notify, onBack, onSave, onRemove }: {
  item: SharedItem;
  busy: boolean;
  notify: Notify;
  onBack: () => void;
  onSave: () => void;
  onRemove: () => void;
}) {
  const session = item.session!;
  const title = sharedTitle(item);
  const transcriptId = session.transcript?.final_transcript_id ?? session.transcript?.live_transcript_id ?? null;
  const [detail, setDetail] = useState<Load<{ url: string | null; names: Record<string, string>; bookmarks: Bookmark[] }>>({ state: "loading" });
  const [transcript, setTranscript] = useState<Load<TranscriptDetail | null>>({ state: "loading" });
  const [attempt, setAttempt] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [position, setPosition] = useState(0);
  const [mediaSeconds, setMediaSeconds] = useState<number | null>(null);
  const [speed, setSpeed] = useState("1");
  const [skipSilence, setSkipSilence] = useState(false);
  const [follow, setFollow] = useState(true);
  const [playbackError, setPlaybackError] = useState<string | null>(null);
  const [showAs, setShowAs] = useState<ShowAs>("original");
  const [views, setViews] = useState<TranscriptViewsState | null>(null);
  const [findOpen, setFindOpen] = useState(false);
  const [findQuery, setFindQuery] = useState("");
  const [findAt, setFindAt] = useState(0);
  const [unsure, setUnsure] = useState<UnsureMarkers>(DEFAULT_UNSURE);
  const audio = useRef<HTMLAudioElement>(null);
  const page = useRef<HTMLDivElement>(null);
  const refreshed = useRef(false);
  const viewPress = useRef(0);

  useEffect(() => {
    let live = true;
    setDetail({ state: "loading" });
    setTranscript(transcriptId ? { state: "loading" } : { state: "ready", value: null });
    setPlaybackError(null);
    refreshed.current = false;
    api.session(session.id).then((d) => {
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
  }, [session.id, transcriptId, attempt]);
  useEffect(() => {
    api.unsureMarkers().then((m) => { if (m) setUnsure({ ...DEFAULT_UNSURE, ...m }); }).catch(() => undefined);
  }, []);
  useEffect(() => {
    if (audio.current) audio.current.playbackRate = Number(speed);
  }, [speed]);

  const tDetail = transcript.state === "ready" ? transcript.value : null;
  // Show as: only the views the owner already has made (classic never makes them on a recording a teammate sent).
  const canShowAs = !!tDetail && tDetail.kind === "final" && tDetail.status === "completed" && tDetail.segments.length > 0
    && (tDetail.views?.available.length ?? 0) > 0 && tDetail.views?.status === "ready";
  const chooseView = async (view: ShowAs) => {
    const press = ++viewPress.current;
    setShowAs(view);
    if (view === "original" || !tDetail || views?.status === "ready") return;
    try {
      const state = await api.transcriptViews(tDetail.id);
      if (state.status === "none" || state.status === "failed") {
        if (viewPress.current === press) setShowAs("original");
        return;
      }
      setViews(state);
    } catch (e) {
      notify(errorMessage(e), true);
      if (viewPress.current === press) setShowAs("original");
    }
  };
  const viewText = (i: number): string | null => (showAs !== "original" && views?.status === "ready" ? views[showAs]?.[i] ?? null : null);

  const names = detail.state === "ready" ? detail.value.names : {};
  const rawSegments = tDetail?.segments ?? null;
  const segments: Segment[] = (rawSegments ?? []).map((s, i) => ({ speaker: speakerName(names, s.speaker), start: s.start_ms / 1000, text: viewText(i) ?? s.text }));
  const lines = useMemo(() => (rawSegments ?? []).map((s) => ({ ...s, speaker: speakerName(names, s.speaker) })), [rawSegments, names]);
  const matches = useMemo(() => (findOpen ? findMatches(segments.map((s) => s.text), findQuery) : []),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [findOpen, findQuery, transcript, showAs, views]);
  const findCurrent = matches.length ? Math.min(findAt, matches.length - 1) : -1;
  useEffect(() => setFindAt(0), [findQuery]);
  useEffect(() => {
    if (findCurrent >= 0) page.current?.querySelector("mark.el-find--current")?.scrollIntoView?.({ block: "center" });
  }, [findCurrent, findQuery]);
  const renderText = (i: number) => {
    const inLine = matches.map((m, index) => ({ ...m, index })).filter((m) => m.seg === i);
    if (!inLine.length) return undefined;
    return markParts(segments[i].text, inLine).map((p, k) => (p.match === null ? <span key={k}>{p.text}</span>
      : <mark key={k} className={p.match === findCurrent ? "el-find el-find--current" : "el-find"}>{p.text}</mark>));
  };

  // The player's length: the longest of the recording's, the audio file's and the last line's end (classic's rule), so a
  // file that reports 0 or 1 s before it has loaded never shows as the length.
  const durationS = Math.max(1, Math.round(playerLength(session.duration_ms, rawSegments, mediaSeconds) / 1000));
  const active = rawSegments && (playing || position > 0) ? activeIndex(rawSegments, position * 1000) : -1;
  const url = detail.state === "ready" && !playbackError ? detail.value.url : null;
  const bookmarks = detail.state === "ready" ? detail.value.bookmarks : [];

  const toggle = () => {
    const a = audio.current;
    if (!a) return;
    if (a.paused) void a.play().catch(() => notify("The audio didn't play. Try again in a moment.", true));
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
  // The signed audio link lives 30 minutes: a failed load asks for a fresh one once, then says so (classic's rule).
  const audioError = () => {
    if (refreshed.current) return setPlaybackError(PLAYBACK_EXPIRED);
    refreshed.current = true;
    const failed = detail.state === "ready" ? detail.value.url : null;
    api.session(session.id).then((d) => {
      const next = typeof d.playback_url === "string" ? d.playback_url : null;
      if (next && next !== failed) setDetail((s) => (s.state === "ready" ? { state: "ready", value: { ...s.value, url: next } } : s));
      else setPlaybackError(PLAYBACK_EXPIRED);
    }).catch(() => setPlaybackError(PLAYBACK_EXPIRED));
  };

  // Space plays or pauses, the arrows jump 5 s and Shift with an arrow 10 s; Cmd/Ctrl+F opens Find. Never while typing.
  const keys = useRef<(e: KeyboardEvent) => void>(() => undefined);
  keys.current = (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "f" && !e.altKey && !inField(e.target)) {
      e.preventDefault();
      if (segments.length) setFindOpen(true);
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
  useEffect(() => {
    if (!follow || active < 0 || !playing) return;
    page.current?.querySelector(".el-segment--active")?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
  }, [active, follow, playing]);

  // Copy: the lines in the view on screen, with unsure words marked; Copy for AI starts with the short header.
  const copy = async (withTimestamps: boolean, forAi = false) => {
    if (!lines.length) return;
    try {
      const body = copiedTranscript(lines, viewText, withTimestamps, unsure, forAi);
      const people = [...new Set(lines.map((l) => l.speaker))];
      await navigator.clipboard.writeText(forAi ? `${aiHeader(session, people)}\n\n${body}` : body);
      notify(forAi ? "Copied for AI, with the note on unsure words" : withTimestamps ? "Copied with timestamps" : "Copied", false, NO_TARGET);
    } catch {
      notify("Could not access the clipboard", true);
    }
  };

  const source = session.source_app_label ?? (session.source_app ? sourceAppLabel(session.source_app) : null);
  const device = session.source_device_label ?? (session.source_device ? DEVICE_WORDS[session.source_device] ?? session.source_device : null);
  // When it was recorded, how long, and its project; the time and the length are number isolates (right way round in Urdu).
  const recorded = [
    formatWhen(session.started_at) ? <span key="at" className="el-num">{formatWhen(session.started_at)}</span> : null,
    formatDuration(session.duration_ms) ? <span key="length" className="el-num">{formatDuration(session.duration_ms)}</span> : null,
    session.client?.name ? <span key="project" dir="auto">{session.client.name}</span> : null,
  ].filter(Boolean).flatMap((part, i) => (i ? [" · ", part] : [part]));
  const engine = transcribedIn(session.transcript);
  const finalStatus = session.transcript?.final_status ?? null;
  const retrying = tDetail && isTranscribing(tDetail.status) ? retryLine(tDetail) : null;
  const warning = scriptWarning(session.transcript?.script_warning);
  const kindLine = tDetail?.kind === "final" ? `Final transcript${tDetail.edited_at ? " · edited" : ""}`
    : `Live captions (final transcript pending${partsText(session.transcript?.final_progress) ? ` · ${partsText(session.transcript?.final_progress)}` : ""})`;

  if (detail.state === "error" && transcript.state !== "ready") {
    return (
      <div className="el-page" data-v2="shared-reader">
        <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Shared</Button>
        <EmptyState tone="error" icon={Icons.ErrorIcon} title="This recording didn't open" body={detail.message}
          action={<Button variant="primary" onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
      </div>
    );
  }

  return (
    <div className="el-page" data-v2="shared-reader" ref={page}>
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>Shared</Button>
      <header className="el-page__head el-page__head--top">
        <div className="el-page__titles">
          <p className="el-page__meta el-secondary" data-testid="source-line" dir="auto">
            <SourceAvatar item={session} size="sm" />
            <span dir="auto">{[source, device].filter(Boolean).join(" · ")}</span>
          </p>
          <h1 className="el-title-l" dir="auto">{title}</h1>
          <p className="el-page__meta el-secondary" data-testid="recorded-line" dir="auto">
            <span dir="auto" data-testid="recorded">{recorded}</span>
            <Badge>Read-only</Badge>
            {item.saved_at && <Badge tone="success">Saved to my library</Badge>}
          </p>
          {engine && <p className="el-caption el-secondary" dir="auto">Transcribed in {engine}</p>}
          {retrying && <p className="el-caption el-secondary" role="status" dir="auto">{retrying}</p>}
          {warning && <p className="el-caption el-row__error" role="status" dir="auto">{warning}</p>}
        </div>
        <div className="el-page__actions">
          {!item.saved_at && <Button variant="primary" icon={Icons.DownloadIcon} loading={busy} onClick={onSave}>Save to my library</Button>}
          <Menu label="More for this recording" button={{ variant: "quiet", icon: Icons.MoreIcon, title: "More" }}
            items={[{ label: "Remove", icon: Icons.TrashIcon, danger: true, disabled: busy, onPick: onRemove }]} />
        </div>
      </header>

      <Banner icon={Icons.InfoIcon} testId="reader-sent">
        <span dir="auto">{sentLine(item)}{item.note ? ` · “${item.note}”` : ""}</span>{" "}<span dir="auto">{READ_ONLY_LINE}</span>
      </Banner>

      <Card className="el-page__player">
        {detail.state === "loading" ? (
          <Loading label="Loading the audio"><Skeleton shape="block" /></Loading>
        ) : (
          <>
            {url && (
              <audio ref={audio} src={url} preload="metadata" data-testid="player" onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}
                onLoadedMetadata={(e) => { setMediaSeconds(e.currentTarget.duration); e.currentTarget.playbackRate = Number(speed); }}
                onDurationChange={(e) => setMediaSeconds(e.currentTarget.duration)} onEnded={() => setPlaying(false)}
                onTimeUpdate={(e) => timeUpdate(e.currentTarget)} onError={audioError} />
            )}
            {playbackError && <Banner tone="warning" icon={Icons.WarningIcon}>{playbackError}</Banner>}
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
                    <Button size="sm" variant="secondary" icon={Icons.PinIcon} title={b.note ?? undefined} disabled={!url}
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

      {tDetail && !isTranscribing(tDetail.status) && tDetail.status !== "failed" && (
        <div className="el-recording__actions" role="toolbar" aria-label="Find and copy">
          <span className="el-caption el-secondary">{kindLine}</span>
          <Button size="sm" variant="quiet" icon={Icons.SearchIcon} aria-pressed={findOpen} aria-keyshortcuts="Meta+F Control+F" disabled={segments.length === 0}
            title="Find in this transcript (Ctrl+F)" onClick={() => setFindOpen(!findOpen)}>Find</Button>
          <Menu label="Copy" button={{ icon: Icons.CopyIcon, iconEnd: Icons.ChevronDownIcon, text: "Copy", disabled: segments.length === 0 }} items={[
            { label: "Copy text", icon: Icons.CopyIcon, onPick: () => void copy(false) },
            { label: "Copy with timestamps", icon: Icons.ClockIcon, onPick: () => void copy(true) },
            { label: "Copy for AI", icon: Icons.SparkleIcon, onPick: () => void copy(false, true) },
          ]} />
        </div>
      )}

      <section className="el-group" aria-label="Transcript">
        {transcript.state === "loading" ? (
          <Loading label="Loading the transcript"><Card><Skeleton /><Skeleton width="long" /><Skeleton width="medium" /></Card></Loading>
        ) : transcript.state === "error" ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title="The transcript didn't load" body={transcript.message}
            action={<Button onClick={() => setAttempt((n) => n + 1)}>Try again</Button>} />
        ) : tDetail?.status === "failed" ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title="Transcription failed."
            body={<>{failureLine(tDetail.error_code)}{tDetail.error_code === "no_audio" ? ` ${NO_AUDIO_HINT}` : ""}{tDetail.error_code === "needs_transcription_key" ? ` ${NEEDS_KEY_TEXT}` : ""}</>}
            action={needsKeyAction(tDetail.error_code) ? (
              <Button onClick={() => openSettingsSection("transcription-key")}>{tDetail.error_code === "own_key_rejected" ? "Check my Groq key" : "Add my Groq key"}</Button>
            ) : undefined} />
        ) : tDetail && isTranscribing(tDetail.status) ? (
          <EmptyState icon={Icons.PendingIcon} title="Transcribing" body={transcribingLine(session.transcript?.final_progress ?? tDetail.progress)} />
        ) : segments.length > 0 ? (
          <>
            {canShowAs && (
              <div className="el-recording__viewbar">
                <Segmented label="Show as" value={showAs} onChange={(v) => void chooseView(v)}
                  options={[...VIEW_ORDER.filter((v) => tDetail!.views!.available.includes(v)).map((v) => ({ value: v as ShowAs, label: VIEW_LABELS[v] })),
                    { value: "original" as ShowAs, label: "Original" }]} />
                {showAs !== "original" && (
                  <span className="el-caption el-secondary" role="status">
                    {views?.status === "ready" ? `${VIEW_LABELS[showAs]} view. The transcript itself is unchanged.` : `Writing the ${VIEW_LABELS[showAs]} view… this takes a minute or two.`}
                  </span>
                )}
              </div>
            )}
            {findOpen && <FindBar query={findQuery} onQuery={setFindQuery} count={matches.length} current={Math.max(0, findCurrent)}
              onStep={(d) => matches.length && setFindAt((findCurrent + d + matches.length) % matches.length)} onClose={() => { setFindOpen(false); setFindQuery(""); }} />}
            <Card>
              <Transcript label="Transcript" segments={segments} renderText={renderText} activeIndex={active >= 0 ? active : undefined} onPlayLine={url ? playFrom : undefined}
                lang={showAs === "urdu" ? "ur" : showAs === "original" ? normalizeLanguage(tDetail?.language) ?? undefined : undefined}
                note={(i) => {
                  if (viewText(i) !== null) return views?.kept?.[showAs as TranscriptView]?.includes(i) ? <span className="el-segment__note">· original words</span> : null;
                  const check = rawSegments?.[i]?.check;
                  return check ? <span className="el-segment__note" dir="auto">{SECOND_HEARD} {check.text}</span> : null;
                }} />
            </Card>
          </>
        ) : tDetail ? (
          <EmptyState icon={Icons.TranscriptIcon} title="The transcript is empty" body="No speech was detected in this recording." />
        ) : (
          <EmptyState icon={Icons.TranscriptIcon} title="No transcript yet" body={isTranscribing(finalStatus) ? transcribingLine(session.transcript?.final_progress) : transcriptStatusLine(finalStatus)} />
        )}
      </section>
    </div>
  );
}
