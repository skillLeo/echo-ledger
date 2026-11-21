import { lazy, Suspense, useEffect, useRef, useState } from "react";
import { api, listenTo, type AppStatus, type InputDevice } from "../../lib/ipc";
import { sourceAppLabel } from "../../lib/format";
import { DISCARD_QUESTION } from "../../lib/pending";
import { BLUETOOTH_NOTE, bluetoothMic } from "../../lib/microphone";
import { useWindowShown } from "../../lib/visibility";
import { fillWidth } from "../../lib/motion";
import { AlertIcon, CaptionsIcon, CopyIcon, MicIcon, RecordIcon } from "../../components/Icons";
import { RecordDotIcon, StopIcon } from "../../components/ControlIcons";
import { LiveTranslateCard } from "./LiveTranslateCard";
import { translateAvailability, useTranslateAvailability } from "../../lib/translate";
import { LockedFeatureCard, useLocked } from "./Upgrade";
import { PermissionBanner } from "./Permissions";
import { isViewer, viewerSentence } from "../../lib/plan";
import { ReadyCheckCard } from "./ReadyCheck";
import { confirmAction } from "../../lib/confirm";

// Live Assist has no classic counterpart and is on for everyone: classic reaches the same prep screen, window and card
// from here, where classic starts things (its section lives with the new design's code, loaded on first use).
const LiveAssistClassic = lazy(() => import("../../v2/app").then((m) => ({ default: m.LiveAssistClassic })));

/**
 * The Bluetooth microphone a recording would use (v1.5), or null. Read when the page shows and again when a recording
 * starts (a headset may have been connected since); the choice follows Settings.
 */
export function useBluetoothMic(recordingRef: string | null): InputDevice | null {
  const [devices, setDevices] = useState<InputDevice[]>([]);
  const [chosen, setChosen] = useState<string | null>(null);
  const shown = useWindowShown();
  useEffect(() => {
    if (!shown) return;
    let alive = true;
    api.inputDevices().then((d) => alive && setDevices(Array.isArray(d) ? d : [])).catch(() => undefined);
    api.settings().then((s) => alive && setChosen(s.inputDevice ?? null)).catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [shown, recordingRef]);
  useEffect(() => {
    const un = listenTo("settings:changed", (s) => setChosen(s.inputDevice ?? null));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return bluetoothMic(devices, chosen);
}

export function RecordView({ status, run, onImport, assistOn = false, projects = [] }: {
  status: AppStatus;
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  /** Opens the file picker and then the import sheet. */
  onImport?: () => void;
  /** Live Assist is on for this person (the server's live_assist): its section shows here. */
  assistOn?: boolean;
  /** The projects a Live Assist session can be linked to. */
  projects?: { id: string; name: string }[];
}) {
  const recording = status.recording;
  const liveTranslateLocked = useLocked("live_translate");
  // Where Live Translate runs, and where the system or the plan holds it back (the core says which; an older core: worked
  // out from the system and the plan). The plan's own badge stays for a plan without it.
  const translateAnswer = useTranslateAvailability();
  const translate = translateAvailability(translateAnswer, status.platform, status.plan.liveTranslate && !liveTranslateLocked);
  const translateCard = liveTranslateLocked ? <LockedFeatureCard feature="live_translate" />
    : translate.available ? <LiveTranslateCard run={run} />
      : translate.reason === "platform" || translateAnswer?.reason === "plan" ? <LiveTranslateCard run={run} unavailable={translate.reason} />
        : null;
  const paused = !!recording && status.paused;
  const [mic, sys] = status.levels;
  const what = recording ? (recording.mode === "mic_note" ? "voice note" : sourceAppLabel(recording.sourceApp)) : "";
  const bluetooth = useBluetoothMic(recording?.clientRef ?? null);
  // Discard asks first; it discards only the recording it asked about (another may have started meanwhile).
  const recordingNow = useRef(recording?.clientRef ?? null);
  recordingNow.current = recording?.clientRef ?? null;
  const cancel = async () => {
    const asked = recordingNow.current;
    if (!asked || !(await confirmAction(DISCARD_QUESTION))) return;
    if (recordingNow.current === asked) void run(api.cancelRecording);
  };
  // A viewer (v1.5) opens what is shared with them and records nothing; Live Translate records nothing either.
  if (isViewer(status) && !recording) {
    return (
      <>
        <div className="card notice-card" data-testid="viewer-notice">
          <div className="row"><RecordIcon /><b>Recording is off for viewers</b></div>
          <p className="small">{viewerSentence(status.plan.workspace)}</p>
          <p className="small muted">An owner or admin of the workspace can change your role in the console.</p>
        </div>
        {translateCard}
      </>
    );
  }
  return (
    <>
      <h2 className="section-title">Record</h2>
      <PermissionBanner recordingRef={recording?.clientRef ?? null} />
      {!recording && <ReadyCheckCard />}
      {status.systemAudioNote && <div className="banner warn" role="status" data-testid="whole-system-note"><AlertIcon size={16} /><span>{status.systemAudioNote}.</span></div>}
      <div className="card">
        <div className="record-hero">
          {/* The record control (since v1.5, as on the phone): its colour changes over 200 ms, it gives a little under a
              press, and a calm ring breathes around it while it records; Record is a filled dot, a recording shows stop. */}
          <span className={`record-wrap ${recording && !paused ? "live" : ""}`} data-testid="record-wrap">
            {/* Record now (Phase 1C): the meeting or call on screen with both sides, otherwise a voice note. */}
            <button className={`record-btn ${recording ? "" : "idle"}`} onClick={() => run(recording ? api.stopRecording : api.recordNow)}
              aria-label={recording ? "Stop recording" : "Record now"}>
              <span key={recording ? "stop" : "rec"} className="record-glyph" aria-hidden="true">{recording ? <StopIcon size={18} /> : <RecordDotIcon size={18} />}</span>
              <span className="record-word">{recording ? "STOP" : "REC"}</span>
            </button>
          </span>
          <div style={{ display: "grid", gap: 8 }}>
            <div className="row">
              <span className={`status-dot ${paused ? "paused" : recording ? "live" : ""}`} />
              {recording ? `${paused ? "Paused" : "Recording"} · ${what}` : "Not recording"}
            </div>
            {!recording && <div className="small muted" data-testid="record-now-hint">Record now: a meeting or call on screen is recorded with both sides, anything else as a voice note. Your microphone is "Me", the other side of the call is "Client".</div>}
            {recording && (
              <div className="record-controls">
                {paused ? (
                  <button type="button" className="btn compact" aria-label="Resume recording" onClick={() => run(api.resumeRecording)}>Resume</button>
                ) : (
                  <button type="button" className="btn compact" aria-label="Pause recording" onClick={() => run(api.pauseRecording)}>Pause</button>
                )}
                <button type="button" className="btn ghost compact danger" aria-label="Cancel recording" onClick={cancel}>Cancel</button>
              </div>
            )}
            <div className="row small"><span style={{ width: 52 }}>Me</span><div className="meter"><div style={fillWidth(mic * 4)} /></div></div>
            <div className="row small"><span style={{ width: 52 }}>Client</span><div className="meter"><div style={fillWidth(sys * 4)} /></div></div>
            {recording && status.deviceNote && (
              <p className={`small ${status.deviceNote.warning ? "warning-text" : "muted"}`} role="status" data-testid="device-note">{status.deviceNote.text}</p>
            )}
            {bluetooth && recording?.mode !== "voice_note" && (
              <p className="small muted bluetooth-note" data-testid="bluetooth-note" title={bluetooth.name}>{BLUETOOTH_NOTE} · {bluetooth.name}</p>
            )}
          </div>
        </div>
      </div>
      <div className="grid-2" style={{ marginTop: 14 }}>
        <div className="card">
          <div className="row"><CaptionsIcon /><b>Live captions</b><span className="spacer" />
            <button type="button" className={`switch ${status.captionsLive ? "on" : ""}`} role="switch" aria-checked={status.captionsLive} aria-label="Toggle live captions" onClick={() => run(api.toggleCaptions)} />
          </div>
          <p className="small muted">
            Captions float over your other windows and never get in the way.
            {!recording && " Turning them on starts a recording."}
          </p>
          <div className="card-actions">
            <button className="btn" onClick={() => run(() => api.copyLastMinutes(2), "Copied the last 2 minutes")}><CopyIcon size={15} />Copy last 2 minutes</button>
          </div>
        </div>
        <div className="card">
          <div className="row"><MicIcon /><b>Voice notes</b></div>
          <p className="small muted">Voice notes are never recorded automatically. Speak into your microphone, capture audio playing on this computer, or drop an audio file anywhere in this window.</p>
          <div className="card-actions">
            <button className="btn primary" onClick={() => run(api.toggleMicNote)} disabled={!!recording && recording.mode !== "mic_note"}>
              <MicIcon size={15} />{recording?.mode === "mic_note" ? "Stop and save voice note" : "Record a voice note"}
            </button>
            <button className="btn" onClick={() => run(api.toggleVoiceNote)} disabled={!!recording && recording.mode !== "voice_note"}>
              {recording?.mode === "voice_note" ? "Stop capture" : "Capture audio playing now"}
            </button>
          </div>
        </div>
      </div>
      {assistOn && !isViewer(status) && (
        <div className="record-assist">
          <Suspense fallback={null}><LiveAssistClassic projects={projects} /></Suspense>
        </div>
      )}
      {/* Live Translate when the plan has it; its badge when the entitlements (v1.6) leave it out; hidden by an older plan. */}
      {translateCard}
      <div className="card dropzone" style={{ marginTop: 14 }}>
        Drop voice notes, audio or video files anywhere in this window to transcribe them (OPUS, OGG, MP3, M4A, AAC, WAV, MP4 or WEBM).
        {onImport && <div className="card-actions dropzone-actions"><button type="button" className="btn" onClick={onImport}>Choose files…</button></div>}
      </div>
      {status.uploadErrors.length > 0 && (
        <div className="card" style={{ marginTop: 14 }}>
          <b>Uploads waiting to retry</b>
          {status.uploadErrors.slice(0, 3).map((e, i) => <div key={i} className="small error-text">{e}</div>)}
        </div>
      )}
    </>
  );
}
