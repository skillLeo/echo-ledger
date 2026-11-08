import { useEffect, useState } from "react";
import { api, listenTo, type AppStatus, type InputDevice, type MicUser } from "../../lib/ipc";
import { processName, sourceAppLabel } from "../../lib/format";
import { explainMicUser, micTone } from "../../lib/explainer";
import { DISCARD_CONFIRM } from "../../lib/pending";
import { BLUETOOTH_NOTE, bluetoothMic } from "../../lib/microphone";
import { useWindowShown } from "../../lib/visibility";
import { fillWidth } from "../../lib/motion";
import { AlertIcon, CaptionsIcon, CopyIcon, MicIcon, RecordIcon } from "../../components/Icons";
import { RecordDotIcon, StopIcon } from "../../components/ControlIcons";
import { LiveTranslateCard } from "./LiveTranslateCard";
import { PermissionBanner } from "./Permissions";
import { isViewer, viewerSentence } from "../../lib/plan";
import { ReadyCheckCard } from "./ReadyCheck";

/**
 * Live view of which apps hold the microphone and what the core decided for each (A1.8), so a call that was not
 * recorded can be explained instead of silently lost.
 */
function CallDetection({ status, run }: { status: AppStatus; run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
  const [users, setUsers] = useState<MicUser[]>([]);
  const shown = useWindowShown();
  useEffect(() => {
    if (!shown) return; // C9: no microphone scans behind a hidden window
    let alive = true;
    const tick = () => api.micUsers().then((u) => alive && setUsers(u)).catch(() => undefined);
    tick();
    const timer = window.setInterval(tick, 2000);
    return () => {
      alive = false;
      window.clearInterval(timer);
    };
  }, [shown]);
  return (
    <div className="card" style={{ marginTop: 14 }}>
      <div className="row">
        <b>Automatic call recording</b>
        <span className="spacer" />
        <button type="button" className={`switch ${status.autoMode ? "on" : ""}`} role="switch" aria-checked={status.autoMode} aria-label="Toggle automatic call recording"
          onClick={() => run(() => api.updateSettings({ autoMode: !status.autoMode }).then(() => undefined))} />
      </div>
      <p className="small muted">
        {status.autoMode
          ? "On: EchoLedger records a call when an app both uses your microphone and plays audio, for example WhatsApp, Zoom, Teams, or Google Meet in a browser. Dictation and voice typing are never recorded."
          : "Off: calls are only recorded when you press REC. Turn this on to record WhatsApp, Zoom, Teams and Meet calls automatically."}
      </p>
      <div className="mic-users" data-testid="mic-users">
        {users.length === 0 && <span className="small muted">No app is using the microphone right now.</span>}
        {users.map((u, i) => (
          <div key={`${u.name}-${i}`} className="mic-user">
            <span className={`status-dot ${micTone(u)}`} aria-hidden="true" />
            <div className="mic-user-main">
              <div className="mic-user-name">
                <span title={u.name}>{processName(u.name)}</span>
                {u.site && <span className="muted">{u.site}</span>}
              </div>
              <div className={`small mic-user-why ${u.decision === "ignored" || u.decision === "off" ? "muted" : ""}`}>{explainMicUser(u)}</div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * The Bluetooth microphone a recording would use (v1.5), or null. Read when the page shows and again when a recording
 * starts (a headset may have been connected since); the choice follows Settings.
 */
function useBluetoothMic(recordingRef: string | null): InputDevice | null {
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

const BACKEND_LABEL: Record<string, string> = {
  process_loopback: "Windows process loopback (EchoLedger excluded)",
  endpoint_loopback: "Windows endpoint loopback (all system audio)",
  core_audio_tap: "macOS Core Audio tap",
  mock: "Simulated source",
};

export function RecordView({ status, run, onImport }: {
  status: AppStatus;
  run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;
  /** Opens the file picker and then the import sheet. */
  onImport?: () => void;
}) {
  const recording = status.recording;
  const paused = !!recording && status.paused;
  const [mic, sys] = status.levels;
  const what = recording ? (recording.mode === "mic_note" ? "voice note" : sourceAppLabel(recording.sourceApp)) : "";
  const bluetooth = useBluetoothMic(recording?.clientRef ?? null);
  const cancel = () => {
    if (window.confirm(DISCARD_CONFIRM)) void run(api.cancelRecording);
  };
  // A viewer (v1.5) opens what is shared with them and records nothing; Live Translate records nothing either.
  if (isViewer(status) && !recording) {
    return (
      <>
        <h1 className="page-title">Record</h1>
        <p className="page-sub">What is shared with you is in the Library, under Shared with me.</p>
        <div className="card notice-card" data-testid="viewer-notice">
          <div className="row"><RecordIcon /><b>Recording is off for viewers</b></div>
          <p className="small">{viewerSentence(status.plan.workspace)}</p>
          <p className="small muted">An owner or admin of the workspace can change your role in the console.</p>
        </div>
        {status.plan.liveTranslate && <LiveTranslateCard run={run} />}
      </>
    );
  }
  return (
    <>
      <h1 className="page-title">Record</h1>
      <p className="page-sub">Two channels: your microphone is "Me", the other side of the call is "Client".</p>
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
            {!recording && <div className="small muted" data-testid="record-now-hint">Record now: a meeting or call on screen is recorded with both sides, anything else as a voice note.</div>}
            {recording && <div className="small muted">{BACKEND_LABEL[recording.backend] ?? recording.backend}</div>}
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
      <CallDetection status={status} run={run} />
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
      {/* Live Translate is hidden when the workspace's plan leaves it out (v1.5). */}
      {status.plan.liveTranslate && <LiveTranslateCard run={run} />}
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
