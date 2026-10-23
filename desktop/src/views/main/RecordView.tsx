import { api, type AppStatus } from "../../lib/ipc";
import { CaptionsIcon, CopyIcon, MicIcon } from "../../components/Icons";

const BACKEND_LABEL: Record<string, string> = {
  process_loopback: "Windows process loopback (EchoLedger excluded)",
  endpoint_loopback: "Windows endpoint loopback (all system audio)",
  core_audio_tap: "macOS Core Audio tap",
  mock: "Simulated source",
};

export function RecordView({ status, run }: { status: AppStatus; run: <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined> }) {
  const recording = status.recording;
  const [mic, sys] = status.levels;
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
            <div className="row"><span className={`status-dot ${recording ? "live" : ""}`} />{recording ? `Recording · ${recording.sourceApp.replace("_", " ")}` : "Not recording"}</div>
            {recording && <div className="small muted">{BACKEND_LABEL[recording.backend] ?? recording.backend}</div>}
            <div className="row small"><span style={{ width: 52 }}>Me</span><div className="meter"><div style={{ width: `${Math.min(100, mic * 400)}%` }} /></div></div>
            <div className="row small"><span style={{ width: 52 }}>Client</span><div className="meter"><div style={{ width: `${Math.min(100, sys * 400)}%` }} /></div></div>
          </div>
        </div>
      </div>
      <div className="grid-2" style={{ marginTop: 14 }}>
        <div className="card">
          <div className="row"><CaptionsIcon /><b>Live captions</b><span className="spacer" />
            <button className={`switch ${status.captionsLive ? "on" : ""}`} aria-label="Toggle live captions" onClick={() => run(api.toggleCaptions)} />
          </div>
          <p className="small muted">A small translucent overlay that never takes focus. Clicks pass through everywhere except its buttons.</p>
          <button className="btn" onClick={() => run(() => api.copyLastMinutes(2), "Copied the last 2 minutes")}><CopyIcon size={15} />Copy last 2 minutes</button>
        </div>
        <div className="card">
          <div className="row"><MicIcon /><b>Voice notes</b></div>
          <p className="small muted">Voice notes are never recorded automatically. Drop an audio file anywhere in this window, or capture one while it plays.</p>
          <button className="btn" onClick={() => run(api.toggleVoiceNote)} disabled={!!recording && recording.mode !== "voice_note"}>
            {recording?.mode === "voice_note" ? "Stop voice-note capture" : "Capture voice note playing now"}
          </button>
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
