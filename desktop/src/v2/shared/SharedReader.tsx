// A recording a teammate sent, open read-only: every control classic's Library keeps for it (LibraryView with
// `readOnly`), in v2 parts as the v2 recording page (RecordingPage.tsx) has them. The head: the source and device, the
// title, when it was recorded, its length and project, "Transcribed in", a retry or script note, and who sent it with
// their note. The player: play, back and forward 10 s, the position, speed, Skip silence, Follow, the keys, and the
// bookmarks. The transcript: its kind ("Final transcript · edited", "Live captions…"), Show as when the owner's views are
// ready (never made from here), Find (Cmd/Ctrl+F), Copy (the text at once; its chevron: with timestamps, for AI), and its lines with the words
// a second transcription heard. Nothing here changes it: Save to my library makes a copy that can be changed, and
// Remove takes it off Shared with me. Same commands as classic: session_detail, transcript_detail, transcript_views. Its
// words are in the account's language (useT).
import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import { api, errorMessage, type SharedItem, type TranscriptDetail, type TranscriptView, type TranscriptViewsState } from "../../lib/ipc";
import { copiedTranscript, DEFAULT_UNSURE, formatDuration, formatWhen, sourceAppLabel, timestamp, type UnsureMarkers } from "../../lib/format";
import { failureLine, needsKeyAction, normalizeLanguage, partsText, retryLine, scriptWarning, transcribedIn, transcriptStatusLine } from "../../lib/languages";
import { openSettingsSection } from "../../lib/entitlements";
import { needsKeyText } from "../../views/main/Upgrade";
import { playerHint, activeIndex, findMatches, KEY_SECONDS, markParts, playerKey, playerLength, skipTarget, speakerName } from "../../lib/player";
import { transcribingLine } from "../../lib/progress";
import { noAudioHint } from "../../lib/pending";
import { bookmarksOf, type Bookmark } from "../../lib/library";
import { NO_TARGET, type Notify } from "../../lib/notice";
import { lineDir, t, useT } from "../../i18n";
import { Badge, Banner, Button, Card, copyMenuItems, EmptyState, Icons, Loading, Player, Segmented, Select, Skeleton, SplitButton, Toggle, Transcript, type Segment } from "../components";
import { Menu } from "../components/Menu";
import { SourceAvatar } from "../library/SourceAvatar";
import { FindBar } from "../library/TranscriptToolsV2";
import { aiHeader } from "../library/organise";
import { quoted, sentLine, sharedTitle, when } from "./model";

type Load<T> = { state: "loading" } | { state: "error"; message: string } | { state: "ready"; value: T };
type ShowAs = "original" | TranscriptView;

/** The words under the sender's line: what this view lets you do. */
export const readOnlyLine = () => t("You can play and read it. Save to my library makes a copy you can change.");
/** Classic's words when the signed audio link has run out and a fresh one could not be had. */
export const playbackExpired = () => t("Playback link expired. Open the recording again.");
const SPEEDS = ["0.5", "0.75", "1", "1.25", "1.5", "1.75", "2"];
const VIEW_ORDER: TranscriptView[] = ["roman", "urdu", "english"];
/** A view's name (Urdu's in its own script, in every language). */
const viewLabel = (view: TranscriptView) => (view === "roman" ? t("Roman Urdu") : view === "english" ? t("English") : "اردو");
const IN_FLIGHT = new Set(["pending", "submitted", "processing"]);
const isTranscribing = (status?: string | null) => !!status && IN_FLIGHT.has(status);
/** The device a recording was made on, in words (the core's own word when it is another). */
const deviceWord = (device: string) => (device === "phone" ? t("Phone") : device === "desktop" ? t("Computer") : device === "web" ? t("Web") : device);

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
  const { t, rtl } = useT();
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
  /** The signed audio link ran out and a fresh one could not be had. */
  const [playbackExpiredNow, setPlaybackExpired] = useState(false);
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
    setPlaybackExpired(false);
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
  const url = detail.state === "ready" && !playbackExpiredNow ? detail.value.url : null;
  const bookmarks = detail.state === "ready" ? detail.value.bookmarks : [];

  const toggle = () => {
    const a = audio.current;
    if (!a) return;
    if (a.paused) void a.play().catch(() => notify(t("The audio didn't play. Try again in a moment."), true));
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
    if (refreshed.current) return setPlaybackExpired(true);
    refreshed.current = true;
    const failed = detail.state === "ready" ? detail.value.url : null;
    api.session(session.id).then((d) => {
      const next = typeof d.playback_url === "string" ? d.playback_url : null;
      if (next && next !== failed) setDetail((s) => (s.state === "ready" ? { state: "ready", value: { ...s.value, url: next } } : s));
      else setPlaybackExpired(true);
    }).catch(() => setPlaybackExpired(true));
  };

  // Space plays or pauses, the arrows jump 5 s and Shift with an arrow 10 s; Cmd/Ctrl+F opens Find. Never while typing.
  const keys = useRef<(e: KeyboardEvent) => void>(() => undefined);
  keys.current = (e: KeyboardEvent) => {
    // Nothing to find (no transcript): Cmd/Ctrl+F is left to app search, as on the recording page.
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
      await navigator.clipboard.writeText(forAi ? [aiHeader(session, people, showAs !== "original" && views?.status === "ready" ? showAs : null), body].join("\n\n") : body);
      notify(forAi ? t("Copied for AI, with the note on unsure words") : withTimestamps ? t("Copied with timestamps") : t("Copied"), false, NO_TARGET);
    } catch {
      notify(t("Could not access the clipboard"), true);
    }
  };

  const source = session.source_app_label ?? (session.source_app ? sourceAppLabel(session.source_app) : null);
  const device = session.source_device_label ?? (session.source_device ? deviceWord(session.source_device) : null);
  // When it was recorded, how long, and its project; the time and the length are number isolates (right way round in Urdu).
  const recorded = [
    session.started_at && formatWhen(session.started_at) ? <Fragment key="at">{when(session.started_at)}</Fragment> : null,
    formatDuration(session.duration_ms) ? <span key="length" className="el-num">{formatDuration(session.duration_ms)}</span> : null,
    session.client?.name ? <span key="project" dir="auto">{session.client.name}</span> : null,
  ].filter(Boolean).flatMap((part, i) => (i ? [" · ", part] : [part]));
  const engine = transcribedIn(session.transcript);
  // A line of pieces (the source and device; when, how long, the project) runs in the window's direction in Urdu, its
  // first piece at the start edge whatever its letters; "auto" in English, as always.
  const pieces = rtl ? "rtl" : "auto";
  /** Who sent it, when, and their note: one line of text, its direction from its own words (lineDir). */
  const sent = sentLine(item) + (item.note ? " · " + quoted(item.note) : "");
  const finalStatus = session.transcript?.final_status ?? null;
  const retrying = tDetail && isTranscribing(tDetail.status) ? retryLine(tDetail) : null;
  const warning = scriptWarning(session.transcript?.script_warning);
  const parts = partsText(session.transcript?.final_progress);
  const kindLine = tDetail?.kind === "final" ? (tDetail.edited_at ? t("Final transcript · edited") : t("Final transcript"))
    : parts ? t("Live captions (final transcript pending · :parts)", { parts }) : t("Live captions (final transcript pending)");

  if (detail.state === "error" && transcript.state !== "ready") {
    return (
      <div className="el-page" data-v2="shared-reader">
        <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{t("Shared")}</Button>
        <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("This recording didn't open")} body={detail.message}
          action={<Button variant="primary" onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
      </div>
    );
  }

  return (
    <div className="el-page" data-v2="shared-reader" ref={page}>
      <Button variant="quiet" size="sm" icon={Icons.ChevronStartIcon} directional className="el-page__back" onClick={onBack}>{t("Shared")}</Button>
      <header className="el-page__head el-page__head--top">
        <div className="el-page__titles">
          <p className="el-page__meta el-secondary" data-testid="source-line" dir={pieces}>
            <SourceAvatar item={session} size="sm" />
            <span dir="auto">{[source, device].filter(Boolean).join(" · ")}</span>
          </p>
          <h1 className="el-title-l" dir="auto">{title}</h1>
          <p className="el-page__meta el-secondary" data-testid="recorded-line" dir="auto">
            <span dir={pieces} data-testid="recorded">{recorded}</span>
            <Badge>{t("Read-only")}</Badge>
            {item.saved_at && <Badge tone="success">{t("Saved to my library")}</Badge>}
          </p>
          {engine && <p className="el-caption el-secondary" dir={lineDir(t("Transcribed in :engine", { engine }))}>{t("Transcribed in :engine", { engine })}</p>}
          {retrying && <p className="el-caption el-secondary" role="status" dir="auto">{retrying}</p>}
          {warning && <p className="el-caption el-row__error" role="status" dir="auto">{warning}</p>}
        </div>
        <div className="el-page__actions">
          {!item.saved_at && <Button variant="primary" icon={Icons.DownloadIcon} loading={busy} onClick={onSave}>{t("Save to my library")}</Button>}
          <Menu label={t("More for this recording")} button={{ variant: "quiet", icon: Icons.MoreIcon, title: t("More") }}
            items={[{ label: t("Remove"), icon: Icons.TrashIcon, danger: true, disabled: busy, onPick: onRemove }]} />
        </div>
      </header>

      <Banner icon={Icons.InfoIcon} testId="reader-sent">
        <span dir={lineDir(sent)}>{sent}</span>{" "}<span dir="auto">{readOnlyLine()}</span>
      </Banner>

      <Card className="el-page__player">
        {detail.state === "loading" ? (
          <Loading label={t("Loading the audio")}><Skeleton shape="block" /></Loading>
        ) : detail.state === "error" ? (
          // The transcript came, the recording's details (its audio) did not: say why, with one way to try again.
          <Banner tone="danger" icon={Icons.ErrorIcon} testId="audio-error" action={<Button size="sm" onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>}>
            {t("The audio didn't load. :error", { error: detail.message })}
          </Banner>
        ) : (
          <>
            {url && (
              <audio ref={audio} src={url} preload="metadata" data-testid="player" onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}
                onLoadedMetadata={(e) => { setMediaSeconds(e.currentTarget.duration); e.currentTarget.playbackRate = Number(speed); }}
                onDurationChange={(e) => setMediaSeconds(e.currentTarget.duration)} onEnded={() => setPlaying(false)}
                onTimeUpdate={(e) => timeUpdate(e.currentTarget)} onError={audioError} />
            )}
            {playbackExpiredNow && <Banner tone="warning" icon={Icons.WarningIcon}>{playbackExpired()}</Banner>}
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
        <div className="el-recording__actions" role="toolbar" aria-label={t("Find and copy")}>
          <span className="el-caption el-secondary">{kindLine}</span>
          <Button size="sm" variant="quiet" icon={Icons.SearchIcon} aria-pressed={findOpen} aria-keyshortcuts="Meta+F Control+F" disabled={segments.length === 0}
            title={t("Find in this transcript (Ctrl+F)")} onClick={() => setFindOpen(!findOpen)}>{t("Find")}</Button>
          <SplitButton label={t("Copy")} icon={Icons.CopyIcon} mainLabel={t("Copy text")} onClick={() => void copy(false)} menuLabel={t("More copy options")}
            disabled={segments.length === 0} items={copyMenuItems({ withTimestamps: () => void copy(true), forAi: () => void copy(false, true) })} />
        </div>
      )}

      <section className="el-group" aria-label={t("Transcript")}>
        {transcript.state === "loading" ? (
          <Loading label={t("Loading the transcript")}><Card><Skeleton /><Skeleton width="long" /><Skeleton width="medium" /></Card></Loading>
        ) : transcript.state === "error" ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("The transcript didn't load")} body={transcript.message}
            action={<Button onClick={() => setAttempt((n) => n + 1)}>{t("Try again")}</Button>} />
        ) : tDetail?.status === "failed" ? (
          <EmptyState tone="error" icon={Icons.ErrorIcon} title={t("Transcription failed.")}
            body={<>{failureLine(tDetail.error_code)}{tDetail.error_code === "no_audio" ? ` ${noAudioHint()}` : ""}{tDetail.error_code === "needs_transcription_key" ? ` ${needsKeyText()}` : ""}</>}
            action={needsKeyAction(tDetail.error_code) ? (
              <Button onClick={() => openSettingsSection("transcription-key")}>{tDetail.error_code === "own_key_rejected" ? t("Check my Groq key") : t("Add my Groq key")}</Button>
            ) : undefined} />
        ) : tDetail && isTranscribing(tDetail.status) ? (
          <EmptyState icon={Icons.PendingIcon} title={t("Transcribing")} body={transcribingLine(session.transcript?.final_progress ?? tDetail.progress)} />
        ) : segments.length > 0 ? (
          <>
            {canShowAs && (
              <div className="el-recording__viewbar">
                <Segmented label={t("Show as")} value={showAs} onChange={(v) => void chooseView(v)}
                  options={[...VIEW_ORDER.filter((v) => tDetail!.views!.available.includes(v)).map((v) => ({ value: v as ShowAs, label: viewLabel(v) })),
                    { value: "original" as ShowAs, label: t("Original") }]} />
                {showAs !== "original" && (
                  <span className="el-caption el-secondary" role="status">
                    {views?.status === "ready" ? t(":view view. The transcript itself is unchanged.", { view: viewLabel(showAs) })
                      : t("Writing the :view view… this takes a minute or two.", { view: viewLabel(showAs) })}
                  </span>
                )}
              </div>
            )}
            {findOpen && <FindBar query={findQuery} onQuery={setFindQuery} count={matches.length} current={Math.max(0, findCurrent)}
              onStep={(d) => matches.length && setFindAt((findCurrent + d + matches.length) % matches.length)} onClose={() => { setFindOpen(false); setFindQuery(""); }} />}
            <Card>
              <Transcript label={t("Transcript")} grouped segments={segments} renderText={renderText} activeIndex={active >= 0 ? active : undefined} onPlayLine={url ? playFrom : undefined}
                lang={showAs === "urdu" ? "ur" : showAs === "original" ? normalizeLanguage(tDetail?.language) ?? undefined : undefined}
                note={(i) => {
                  if (viewText(i) !== null) return views?.kept?.[showAs as TranscriptView]?.includes(i) ? <span className="el-segment__note">{t("· original words")}</span> : null;
                  const check = rawSegments?.[i]?.check;
                  return check ? <span className="el-segment__note" dir="auto">{t("A second transcription heard: :text", { text: check.text })}</span> : null;
                }} />
            </Card>
          </>
        ) : tDetail ? (
          <EmptyState icon={Icons.TranscriptIcon} title={t("The transcript is empty")} body={t("No speech was detected in this recording.")} />
        ) : (
          <EmptyState icon={Icons.TranscriptIcon} title={t("No transcript yet")} body={isTranscribing(finalStatus) ? transcribingLine(session.transcript?.final_progress) : transcriptStatusLine(finalStatus)} />
        )}
      </section>
    </div>
  );
}
