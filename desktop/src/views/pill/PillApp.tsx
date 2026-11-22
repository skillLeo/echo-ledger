import { lazy, Suspense, useCallback, useEffect, useRef, useState, type MouseEvent, type ReactNode } from "react";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import { announceReady, api, errorMessage, listenTo, type CallChoice, type FarSidePayload, type MarkPayload, type RecordingState, type ScreenHoldReason, type ScreensPill } from "../../lib/ipc";
import { CALL_WORDS, PILL_MENU } from "../../lib/callChoice";
import { useHitRegions } from "../../lib/hitRegions";
import { useConcealed } from "../../lib/privacy";
import { PrivacyBadge } from "../../components/PrivacyBadge";
import { fillHeight } from "../../lib/motion";
import { languageChip, languageLabel, nextLanguage } from "../../lib/languages";
import { elapsedAt, formatTimer, isTwoChannel, meterLevel, missingShort, nextAnchor, otherSideNotRecorded, sourceLabel, type TimerAnchor } from "../../lib/controls";
import { BLUETOOTH_NOTE } from "../../lib/microphone";
import { CaptionsIcon, MicIcon, MonitorIcon, SourceAppIcon, TrashIcon } from "../../components/Icons";
import { BookmarkIcon, CheckIcon, MinimizeIcon, PauseIcon, PlayIcon, StopIcon, WarningIcon } from "../../components/ControlIcons";
import "../controls.css";
import { useRisingCount, useUiFlags } from "../../v2/flags";

// The pill in the new look (ui_v2.recording_controls), loaded only when its flag is on.
const RecordingPillV2 = lazy(() => import("../../v2/controls/RecordingPill").then((m) => ({ default: m.RecordingPill })));
const captureNowV2 = () => import("../../v2/controls/commands").then((m) => m.captureNow());

/** How long a failed action's message replaces the source label. */
const ERROR_MS = 4000;
/** The drag runs in the OS move loop; the position is read this long after it started (contract 4.8). */
const DRAG_SAVE_MS = 400;
/** How long the bookmark tray stays after the last bookmark or tag. */
export const MARK_TRAY_MS = 6000;
/** How long "Accuracy is lower with Bluetooth mics" stays under the pill when a recording starts (v1.5). */
export const BLUETOOTH_NOTICE_MS = 8000;
/** How long "Screens on" stays under the pill the first time screens are kept in a recording (v1.5). */
export const SCREENS_NOTICE_MS = 6000;

/** Why screens are paused, as the Screens chip says it (v1.5). */
export const SCREEN_HOLD_TEXT: Record<ScreenHoldReason, string> = {
  you: "paused by you",
  recording_paused: "paused with the recording",
  minimised: "paused while the meeting window is minimised or hidden",
  no_window: "waiting for the meeting window",
  excluded_app: "paused while an excluded app is on screen",
  permission: "paused: macOS needs the Screen Recording permission",
  hour_cap: "paused: 120 screens this hour",
  starting: "starting",
  limit: "paused: this recording has reached its limit",
};

/** Said once under the pill when the server keeps no more screens of the recording (`409 too_many_screens`). */
export const SCREENS_LIMIT_NOTE = "Screens paused: this recording has reached its limit";

/** "Screens on · 3 kept. Click to pause screens" */
export function screensChipText(screens: ScreensPill): string {
  const kept = screens.count === 1 ? "1 kept" : `${screens.count} kept`;
  if (screens.on) return `Screens on · ${kept}. Click to pause screens`;
  if (screens.reason === "you") return `Screens paused by you · ${kept}. Click to resume`;
  return `Screens ${SCREEN_HOLD_TEXT[screens.reason ?? "starting"]} · ${kept}. Click to pause screens`;
}

type Action = "pause" | "stop" | "cancel" | "choice" | "captions" | "hide" | "mark" | "language" | "screens";

/**
 * Loom-style recording controls in the 352 x 148 `pill` window: REC + timer, source, Me/Client levels, Pause/Resume,
 * Stop ("After each call" decides what follows), Mark, the language chip, Captions on/off and Hide. A press on REC and
 * the timer opens the call choice's menu under the pill: Transcribe this call, Save audio only (a call only) and Stop
 * and delete; a choice made there means no card after the call. With "At the start of the call", "Recording this call ·
 * Don't record" shows under the pill for 5 s. Under the pill, a
 * bookmark (from here or the shortcut) shows its tag presets and Undo for a few seconds, a call that has not heard
 * the other side for 30 s shows a warning, and a recording from a Bluetooth microphone says once that accuracy is lower
 * with it. While screen moments can be kept (v1.5) the Screens chip says whether they are on and pauses them in one
 * click; the first time they are on in a recording, "Screens on" shows under the pill for a few seconds. The window
 * never takes focus: every control works on click, and only the pill itself is reported as a hit region.
 */
export default function PillApp() {
  const [rec, setRec] = useState<RecordingState | null>(null);
  // The interface flags are read again at each recording's start, before the pill shows, and never in the middle of one
  // (v2/flags.ts): a flag turned on or off, or "Switch back to classic", reaches the next recording.
  const runKey = `run-${useRisingCount(!!rec?.active)}`;
  const [ui, , flagsFor] = useUiFlags(true, runKey, false);
  const [visible, setVisible] = useState(false);
  const [anchor, setAnchor] = useState<TimerAnchor | null>(null);
  const [now, setNow] = useState(() => Date.now());
  const [menu, setMenu] = useState(false);
  /** The new look's menu is open (the pill owns it). */
  const [menuV2, setMenuV2] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [mark, setMark] = useState<MarkPayload | null>(null);
  const [markAt, setMarkAt] = useState(0);
  const [tags, setTags] = useState<string[]>([]);
  const [farSide, setFarSide] = useState<FarSidePayload | null>(null);
  const [bluetooth, setBluetooth] = useState(false);
  /** The Bluetooth notice was shown for this recording (once each). */
  const bluetoothShown = useRef(false);
  const [screensNotice, setScreensNotice] = useState(false);
  /** "Screens on" was shown for this recording (once each). */
  const screensShown = useRef(false);
  const [limitNotice, setLimitNotice] = useState(false);
  /** The screens limit was said for this recording (once each). */
  const limitShown = useRef(false);
  /** The person dismissed what a missing permission means for this recording. */
  const [missingDismissed, setMissingDismissed] = useState(false);
  const busy = useRef<Action | null>(null);
  /** Set while Stop, Cancel or Hide runs, so a late once-a-second event cannot bring the pill back. */
  const closing = useRef(false);
  const dragStartedAt = useRef(0);

  const apply = useCallback((state: RecordingState) => {
    const t = Date.now();
    setRec(state);
    setAnchor((prev) => nextAnchor(prev, state, t));
    setNow(t);
    const show = state.active && !state.hidden;
    const keep = closing.current;
    setVisible((was) => (show && keep ? was : show));
    if (!state.active) {
      setMenu(false);
      setMark(null);
      setFarSide(null);
      bluetoothShown.current = false;
      setBluetooth(false);
      screensShown.current = false;
      setScreensNotice(false);
      limitShown.current = false;
      setLimitNotice(false);
      setMissingDismissed(false);
      return;
    }
    if (state.bluetoothMic && !bluetoothShown.current) {
      // The core checks the microphone just after the start; said once per recording.
      bluetoothShown.current = true;
      setBluetooth(true);
    }
    if (state.screens?.on && !screensShown.current) {
      screensShown.current = true;
      setScreensNotice(true);
    }
    if (state.screens?.reason === "limit" && !limitShown.current) {
      limitShown.current = true;
      setLimitNotice(true);
    }
  }, []);

  const refresh = useCallback(() => api.recordingState().then(apply).catch(() => undefined), [apply]);

  useEffect(() => {
    let alive = true;
    let heardEvent = false;
    api.recordingState().then((state) => {
      if (alive && !heardEvent) apply(state);
    }).catch(() => undefined);
    const subscriptions = [
      listenTo("recording:state", (state) => {
        heardEvent = true;
        apply(state);
      }),
      listenTo("recording:marked", (payload) => {
        setMark(payload);
        setMarkAt(Date.now());
        api.markerTags().then(setTags).catch(() => undefined);
      }),
      listenTo("capture:far_side", (payload) => setFarSide(payload.silent ? payload : null)),
      listenTo("pill:visibility", (show) => {
        setVisible(show);
        if (show) void refresh();
        else setMenu(false);
      }),
    ];
    announceReady(subscriptions, () => alive);
    return () => {
      alive = false;
      subscriptions.forEach((p) => p.then((unlisten) => unlisten()).catch(() => undefined));
    };
  }, [apply, refresh]);

  const live = rec?.active ? rec : null;
  const shown = visible && live !== null;
  const running = shown && !live.paused;

  // Interpolates the timer between the core's 1 s events; a paused timer shows the frozen value.
  useEffect(() => {
    if (!running) return;
    const timer = window.setInterval(() => setNow(Date.now()), 250);
    return () => window.clearInterval(timer);
  }, [running]);

  useEffect(() => {
    if (!error) return;
    const timer = window.setTimeout(() => setError(null), ERROR_MS);
    return () => window.clearTimeout(timer);
  }, [error]);

  // The window cannot take focus, so Escape only reaches it in a plain browser; it closes the menu.
  useEffect(() => {
    if (!menu) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setMenu(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [menu]);

  // The bookmark tray closes by itself; tagging keeps it open a little longer.
  useEffect(() => {
    if (!mark) return;
    const timer = window.setTimeout(() => setMark(null), MARK_TRAY_MS);
    return () => window.clearTimeout(timer);
  }, [mark, markAt]);

  useEffect(() => {
    if (!bluetooth) return;
    const timer = window.setTimeout(() => setBluetooth(false), BLUETOOTH_NOTICE_MS);
    return () => window.clearTimeout(timer);
  }, [bluetooth]);

  useEffect(() => {
    if (!screensNotice) return;
    const timer = window.setTimeout(() => setScreensNotice(false), SCREENS_NOTICE_MS);
    return () => window.clearTimeout(timer);
  }, [screensNotice]);

  useEffect(() => {
    if (!limitNotice) return;
    const timer = window.setTimeout(() => setLimitNotice(false), SCREENS_NOTICE_MS);
    return () => window.clearTimeout(timer);
  }, [limitNotice]);

  // Dismissed is for what was on the line: something new going missing during the recording is said again.
  const missingKinds = (live?.missing ?? []).map((m) => `${m.kind}:${m.sentence}`).join();
  useEffect(() => setMissingDismissed(false), [missingKinds]);
  // The other side not being recorded (2.0.6) stays until it is heard: no dismiss.
  const notRecorded = shown ? otherSideNotRecorded(live?.missing) : null;
  const missing = shown && (!missingDismissed || notRecorded) ? missingShort(live?.missing, live?.mode) : null;
  const deviceNote = shown ? live?.deviceNote ?? null : null;
  const banner = shown && !!live?.startBanner;
  // The window takes no more of the screen than it shows: it grows while the menu or the start banner shows, and gives
  // the screen back when they close. Said only when it changes (the first state is the closed one).
  // A warning (the other side not being recorded) stays under the pill with the menu or the banner open: the window
  // grows for whichever shows, or both, so neither is clipped.
  const expanded = shown && (menu || menuV2 || banner);
  // Closed, only "not recorded" needs more room (its sentence on a line and its two buttons under it: 124 px); a one-row
  // line that can be dismissed fits the closed pill's 84 px. With the menu or banner open, any warning keeps its row
  // above them, so the window makes room for it too.
  const warning = shown && !!missing && (!!notRecorded || expanded);
  const sent = useRef("false:false");
  /** Grows the window first and then lets the menu be drawn, so it is never clipped for a frame. */
  const growFirst = useCallback(async () => {
    const withWarning = shown && !!missing;
    sent.current = `true:${withWarning}`;
    await api.pillExpanded(true, withWarning).catch(() => undefined);
  }, [shown, missing]);
  useEffect(() => {
    const key = `${expanded}:${warning}`;
    if (sent.current === key) return;
    sent.current = key;
    api.pillExpanded(expanded, warning).catch(() => undefined);
  }, [expanded, warning]);
  const tray = menu && shown ? "menu" : banner ? "start" : mark ? "mark" : deviceNote?.warning ? "device" : missing ? "missing" : farSide && running ? "warn" : deviceNote ? "device" : bluetooth && shown ? "bluetooth" : limitNotice && shown ? "limit" : screensNotice && shown && live?.screens?.on ? "screens" : null;
  const concealed = useConcealed();
  // With the menu or the banner open, the warning keeps its row above them.
  const warningWithOpen = (menu || banner) && !!missing;
  useHitRegions([shown, menu, tray, warningWithOpen, concealed]);

  /** Runs one command at a time; on failure shows the message and resyncs with the core. */
  const run = async (action: Action, command: () => Promise<unknown>) => {
    if (busy.current) return;
    busy.current = action;
    try {
      await command();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busy.current = null;
      closing.current = false;
      await refresh();
    }
  };

  /** Optimistic local change, so the click answers at once; the core's state follows. */
  const patch = (change: Partial<RecordingState>) => {
    if (!rec) return;
    apply({ ...rec, elapsedMs: elapsedAt(anchor, Date.now()), ...change });
  };

  const togglePause = () => {
    if (!rec || busy.current) return;
    const paused = rec.paused;
    patch({ paused: !paused });
    void run("pause", paused ? api.resumeRecording : api.pauseRecording);
  };

  const stop = () => {
    if (busy.current) return;
    closing.current = true;
    setVisible(false);
    void run("stop", api.stopRecording);
  };

  /** The menu's choice (and the banner's Don't record): Stop and delete ends the recording now, with Undo after it;
   * Transcribe this call and Save audio only are what happens when the call ends (no card then). */
  const choose = (choice: CallChoice) => {
    if (busy.current) return;
    setMenu(false);
    if (choice === "delete") {
      closing.current = true;
      setVisible(false);
      void run("cancel", () => api.callChoice("delete"));
      return;
    }
    patch({ callChoice: choice });
    void run("choice", () => api.callChoice(choice));
  };

  const toggleCaptions = () => {
    if (!rec || busy.current) return;
    patch({ captions: !rec.captions });
    void run("captions", api.toggleCaptions);
  };

  // The window cannot open a menu, so the chip steps through the languages; captions reconnect in the new one.
  const cycleLanguage = () => {
    if (!rec || busy.current) return;
    const next = nextLanguage(rec.language);
    patch({ language: next });
    void run("language", () => api.setRecordingLanguage(next));
  };

  const markNow = () => void run("mark", api.markMoment);

  // One click pauses screens for this recording (the recording goes on); a pause by the person is undone the same way.
  const toggleScreens = () => {
    if (!rec?.screens || busy.current) return;
    const resume = rec.screens.reason === "you";
    setScreensNotice(false);
    patch({ screens: resume ? { ...rec.screens, on: true, reason: null } : { ...rec.screens, on: false, reason: "you" } });
    void run("screens", () => api.screensPause(!resume));
  };

  const tagMark = (tag: string) => {
    if (!mark) return;
    const next = mark.tag === tag ? null : tag;
    setMark({ ...mark, tag: next });
    setMarkAt(Date.now());
    api.tagLastMark(next, mark.note).catch((e) => setError(errorMessage(e)));
  };

  const undoMark = () => {
    setMark(null);
    api.undoMark().catch((e) => setError(errorMessage(e)));
  };

  const hide = () => {
    if (busy.current) return;
    closing.current = true;
    setVisible(false);
    void run("hide", () => api.setPillHidden(true));
  };

  const saveDrag = () => api.pillMoved().catch(() => undefined);

  const startDrag = async (e: MouseEvent<HTMLDivElement>) => {
    if (e.button !== 0 || (e.target as Element).closest("button")) return;
    e.preventDefault();
    dragStartedAt.current = Date.now();
    await getCurrentWebviewWindow().startDragging().catch(() => undefined);
    window.setTimeout(saveDrag, DRAG_SAVE_MS);
  };

  // A drag that outlasts the timer is saved again on the first pointer movement after the OS move loop ends.
  const afterDrag = () => {
    if (!dragStartedAt.current || Date.now() - dragStartedAt.current < DRAG_SAVE_MS) return;
    dragStartedAt.current = 0;
    void saveDrag();
  };

  if (!shown || !live || concealed || flagsFor !== runKey) return <div className="pill-root" />;

  const paused = live.paused;
  const label = sourceLabel(live.sourceApp);
  const elapsed = formatTimer(elapsedAt(anchor, now));
  const [me, client] = live.levels;

  if (ui.flags["ui_v2.recording_controls"]) {
    const caller = (live as RecordingState & { callerName?: string | null }).callerName?.trim();
    return (
      <div className="pill-root el-root el-embedded" onMouseDown={startDrag} onMouseMove={afterDrag}>
        <Suspense fallback={null}>
          <RecordingPillV2
            onMenu={setMenuV2}
            beforeOpen={growFirst}
            model={{
              elapsed, name: caller || label, paused, captions: live.captions, language: languageChip(live.language), error,
              screens: live.screens ? { on: live.screens.on, count: live.screens.count } : null,
              call: live.mode === "call", choice: live.callChoice ?? null, banner,
              warning: missing ? { text: missing, sentence: notRecorded?.sentence ?? live.missing?.[0]?.sentence ?? missing, notRecorded: !!notRecorded } : null,
            }}
            actions={{
              onCaptions: toggleCaptions, onPause: togglePause, onStop: stop, onScreens: toggleScreens,
              onCapture: () => void run("screens", captureNowV2), onLanguage: cycleLanguage, onChoice: choose,
              onOpenSettings: () => void api.openPrivacySettings("system_audio").catch(() => undefined),
              onRetrySystem: () => void api.captureRetrySystem().catch(() => undefined),
              onDismissWarning: () => setMissingDismissed(true),
            }} />
        </Suspense>
      </div>
    );
  }

  // The other side not being recorded: the sentence on a line of its own, whole, and its two buttons under it.
  const missingRow = missing && (notRecorded ? (
    <div className="mark-tray warn stacked" lang="en" data-hit data-testid="missing-permission" role="alert" title={notRecorded.sentence}>
      <span className="mark-line"><WarningIcon size={14} /><span className="mark-text whole">{missing}</span></span>
      <span className="mark-buttons">
        <button type="button" className="rec-text-btn" title={notRecorded.sentence} onClick={() => void api.openPrivacySettings("system_audio").catch(() => undefined)}>Open System Settings</button>
        <button type="button" className="rec-text-btn" onClick={() => void api.captureRetrySystem().catch(() => undefined)}>Try again</button>
      </span>
    </div>
  ) : (
    <div className="mark-tray warn" data-hit data-testid="missing-permission" role="alert" title={live?.missing?.[0]?.sentence}>
      <WarningIcon size={14} />
      <span className="mark-text">{missing}</span>
      <button type="button" className="rec-btn" aria-label="Dismiss warning" title="Dismiss" onClick={() => setMissingDismissed(true)}><MinimizeIcon size={14} /></button>
    </div>
  ));

  return (
    <div className="pill-root">
      <div className={`rec-pill${paused ? " paused" : ""}`} data-hit data-testid="recording-pill" role="toolbar"
        aria-label="Recording controls" onMouseDown={startDrag} onMouseMove={afterDrag}>
        <button type="button" className="rec-choice" data-testid="pill-choice" aria-haspopup="menu" aria-expanded={menu} aria-controls="rec-menu"
          aria-label={`${paused ? "Paused" : "Recording"} ${elapsed}. ${menu ? "Hide" : "Show"} what happens to this recording`}
          onClick={() => { if (menu) setMenu(false); else void growFirst().then(() => setMenu(true)); }}>
          <span className="rec-status">
            <span className="rec-dot" aria-hidden="true" />
            <span className="rec-state">{paused ? "Paused" : "REC"}</span>
          </span>
          <span className="rec-timer" data-testid="pill-timer">{elapsed}</span>
        </button>
        {error ? (
          <span className="rec-source rec-error" role="alert" title={error}><span className="rec-label">{error}</span></span>
        ) : (
          <span className="rec-source" title={label}>
            <SourceAppIcon app={live.sourceApp ?? "other"} size={14} />
            <span className="rec-label">{label}</span>
          </span>
        )}
        {isTwoChannel(live.mode) && (
          <span className="rec-meters" data-testid="pill-meters">
            <span className="rec-meter me" title="Me" aria-hidden="true"><span style={fillHeight(meterLevel(me))} /></span>
            <span className="rec-meter client" title="Client" aria-hidden="true"><span style={fillHeight(meterLevel(client))} /></span>
          </span>
        )}
        <span className="rec-actions">
            <PrivacyBadge compact />
            <PillButton label={paused ? "Resume recording" : "Pause recording"} onClick={togglePause}>
              {paused ? <PlayIcon size={14} /> : <PauseIcon size={14} />}
            </PillButton>
            <PillButton label="Stop recording" className="stop" onClick={stop}><StopIcon size={14} /></PillButton>
            <PillButton label="Bookmark this moment" onClick={markNow}><BookmarkIcon size={15} /></PillButton>
            <button type="button" className="rec-lang" data-testid="pill-language" onClick={cycleLanguage}
              aria-label={`Language: ${languageLabel(live.language)}. Click to change`} title={`Language: ${languageLabel(live.language)} (click to change)`}>
              {languageChip(live.language)}
            </button>
            {live.screens && (
              <button type="button" className={`rec-btn rec-screens${live.screens.on ? " on" : " held"}`} data-testid="pill-screens"
                aria-label={screensChipText(live.screens)} title={screensChipText(live.screens)} onClick={toggleScreens}>
                <MonitorIcon size={15} />
              </button>
            )}
            <PillButton label={live.captions ? "Turn captions off" : "Turn captions on"} pressed={live.captions} onClick={toggleCaptions}>
              <CaptionsIcon size={15} />
            </PillButton>
            <PillButton label="Hide controls" onClick={hide}><MinimizeIcon size={15} /></PillButton>
        </span>
      </div>
      {warningWithOpen && missingRow}
      {tray === "menu" && (
        <div className="rec-menu" id="rec-menu" lang="en" data-hit role="menu" aria-label={CALL_WORDS.setting_title} data-testid="pill-menu">
          {PILL_MENU.filter((m) => live.mode === "call" || m.choice === "delete").map((m) => (
            <button key={m.choice} type="button" role={m.choice === "delete" ? "menuitem" : "menuitemradio"}
              aria-checked={m.choice === "delete" ? undefined : live.callChoice === m.choice} className={`rec-menu-item${m.choice === "delete" ? " danger" : ""}`}
              onClick={() => choose(m.choice)}>
              <span className="rec-menu-mark" aria-hidden="true">{live.callChoice === m.choice ? <CheckIcon size={14} /> : m.choice === "delete" ? <TrashIcon size={14} /> : null}</span>
              {m.label}
            </button>
          ))}
        </div>
      )}
      {tray === "start" && (
        <div className="mark-tray note" lang="en" data-hit data-testid="start-banner" role="status">
          <span className="rec-dot" aria-hidden="true" />
          <span className="mark-text" dir="auto">{CALL_WORDS.start_banner}</span>
          <button type="button" className="rec-text-btn" onClick={() => choose("delete")}>{CALL_WORDS.dont_record}</button>
        </div>
      )}
      {tray === "mark" && mark && (
        <div className="mark-tray" data-hit data-testid="mark-tray" role="group" aria-label="Bookmark">
          <span className="mark-done">Bookmarked {formatTimer(mark.atMs)}</span>
          <span className="mark-tags">
            {tags.map((tag) => (
              <button type="button" key={tag} className="mark-tag" aria-pressed={mark.tag === tag} onClick={() => tagMark(tag)}>{tag}</button>
            ))}
          </span>
          <button type="button" className="rec-text-btn" onClick={undoMark}>Undo</button>
        </div>
      )}
      {tray === "warn" && farSide && (
        <div className="mark-tray warn" data-hit data-testid="far-side-warning" role="alert">
          <WarningIcon size={14} />
          <span className="mark-text">Not hearing the other side for {farSide.seconds} s. Check the call's speaker or device.</span>
          <button type="button" className="rec-btn" aria-label="Dismiss warning" title="Dismiss" onClick={() => setFarSide(null)}><MinimizeIcon size={14} /></button>
        </div>
      )}
      {tray === "device" && deviceNote && (
        <div className={`mark-tray ${deviceNote.warning ? "warn" : "note"}`} data-hit data-testid="device-notice" role={deviceNote.warning ? "alert" : "status"} title={deviceNote.text}>
          {deviceNote.warning ? <WarningIcon size={14} /> : <MicIcon size={14} />}
          <span className="mark-text">{deviceNote.text}</span>
        </div>
      )}
      {tray === "missing" && missingRow}
      {tray === "screens" && (
        <div className="mark-tray note" data-hit data-testid="screens-notice" role="status">
          <MonitorIcon size={14} />
          <span className="mark-text">Screens on for this meeting</span>
          <button type="button" className="rec-text-btn" onClick={toggleScreens}>Pause</button>
        </div>
      )}
      {tray === "limit" && (
        <div className="mark-tray note" data-hit data-testid="screens-limit-notice" role="status">
          <MonitorIcon size={14} />
          <span className="mark-text">{SCREENS_LIMIT_NOTE}</span>
          <button type="button" className="rec-btn" aria-label="Dismiss notice" title="Dismiss" onClick={() => setLimitNotice(false)}><MinimizeIcon size={14} /></button>
        </div>
      )}
      {tray === "bluetooth" && (
        <div className="mark-tray note" data-hit data-testid="bluetooth-notice" role="status">
          <MicIcon size={14} />
          <span className="mark-text">{BLUETOOTH_NOTE}</span>
          <button type="button" className="rec-btn" aria-label="Dismiss notice" title="Dismiss" onClick={() => setBluetooth(false)}><MinimizeIcon size={14} /></button>
        </div>
      )}
    </div>
  );
}

function PillButton({ label, onClick, pressed, className, children }: {
  label: string;
  onClick: () => void;
  pressed?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <button type="button" className={`rec-btn${className ? ` ${className}` : ""}`} aria-label={label} title={label}
      aria-pressed={pressed} onClick={onClick}>
      {children}
    </button>
  );
}
