import { useEffect, useState } from "react";
import { api, type AppStatus, type MicUser } from "../../lib/ipc";
import { sourceAppLabel } from "../../lib/format";
import { explainMicUser, micTone } from "../../lib/explainer";
import { DISCARD_CONFIRM } from "../../lib/pending";
import { CaptionsIcon, CopyIcon, MicIcon } from "../../components/Icons";

/**
 * Live view of which apps hold the microphone and what the core decided for each (A1.8), so a call that was not
 * recorded can be explained instead of silently lost.
 */
function CallDetection({ status, run }: { status: AppStatus; run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
  const [users, setUsers] = useState<MicUser[]>([]);
  useEffect(() => {
    let alive = true;
    const tick = () => api.micUsers().then((u) => alive && setUsers(u)).catch(() => undefined);
    tick();
    const timer = window.setInterval(tick, 2000);
    return () => {
      alive = false;
      window.clearInterval(timer);
    };
  }, []);
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
                <span>{u.name}</span>
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

const BACKEND_LABEL: Record<string, string> = {
  process_loopback: "Windows process loopback (EchoLedger excluded)",
  endpoint_loopback: "Windows endpoint loopback (all system audio)",
  core_audio_tap: "macOS Core Audio tap",
  mock: "Simulated source",
};

export function RecordView({ status, run }: { status: AppStatus; run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
  const recording = status.recording;
  const paused = !!recording && status.paused;
  const [mic, sys] = status.levels;
  const what = recording ? (recording.mode === "mic_note" ? "voice note" : sourceAppLabel(recording.sourceApp)) : "";
  const cancel = () => {
    if (window.confirm(DISCARD_CONFIRM)) void run(api.cancelRecording);
  };
  return (
    <>
      <h1 className="page-title">Record</h1>
      <p className="page-sub">Two channels: your microphone is "Me", the other side of the call is "Client".</p>
      <div className="card">
        <div className="record-hero">
          <button className={`record-btn ${recording ? "" : "idle"}`} onClick={() => run(recording ? api.stopRecording : () => api.startRecording())}
            aria-label={recording ? "Stop recording" : "Start recording"}>
            {recording ? "STOP" : "REC"}
          </button>
          <div style={{ display: "grid", gap: 8 }}>
            <div className="row">
              <span className={`status-dot ${paused ? "paused" : recording ? "live" : ""}`} />
              {recording ? `${paused ? "Paused" : "Recording"} · ${what}` : "Not recording"}
            </div>
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
            <div className="row small"><span style={{ width: 52 }}>Me</span><div className="meter"><div style={{ width: `${Math.min(100, mic * 400)}%` }} /></div></div>
            <div className="row small"><span style={{ width: 52 }}>Client</span><div className="meter"><div style={{ width: `${Math.min(100, sys * 400)}%` }} /></div></div>
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
            A small translucent overlay that never takes focus; clicks pass through everywhere except its buttons.
            {!recording && " Turning captions on starts a recording so there is audio to caption."}
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
      <div className="card dropzone" style={{ marginTop: 14 }}>Drag &amp; drop .opus / .ogg / .m4a / .mp3 / .wav voice notes here to transcribe them.</div>
      {status.uploadErrors.length > 0 && (
        <div className="card" style={{ marginTop: 14 }}>
          <b>Uploads waiting to retry</b>
          {status.uploadErrors.slice(0, 3).map((e, i) => <div key={i} className="small error-text">{e}</div>)}
        </div>
      )}
    </>
  );
}
