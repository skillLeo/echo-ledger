// Recording itself on the v2 Record page (docs/v2/audit/parity.csv DT-HOME-007 to DT-HOME-020), as classic's Record
// section (RecordView): what is being recorded and whether it is paused, Pause / Resume, Bookmark this moment, Cancel
// (asks), the Me / Client levels, the device and Bluetooth notes, a missing permission; Live captions with Copy last 2
// minutes; voice notes from the microphone or from audio playing now; Choose files…; and the uploads waiting to retry
// with their errors. Same commands as classic.
import { useRef } from "react";
import { api, type AppStatus } from "../../lib/ipc";
import { sourceAppLabel } from "../../lib/format";
import { BLUETOOTH_NOTE } from "../../lib/microphone";
import { confirmAction } from "../../lib/confirm";
import { fillWidth } from "../../lib/motion";
import { useBluetoothMic } from "../../views/main/RecordView";
import { SETTINGS_LABEL, usePermissions } from "../../views/main/Permissions";
import { Banner, Button, Card, Icons, Status, Toggle } from "../components";

/** Cancel recording asks first, in the same word as its button (the recording so far is thrown away). */
export const CANCEL_QUESTION = {
  title: "Cancel this recording?", body: "What was recorded so far is deleted. It can't be undone.", confirm: "Cancel recording", cancel: "Keep recording", tone: "danger",
} as const;

/** Runs a core command; `ok` is said once it is done, an error is said instead (MainApp's run). */
export type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;

/** "Recording · Zoom", "Paused · voice note", "Not recording". */
export function recordingLine(status: Pick<AppStatus, "recording" | "paused">): string {
  const r = status.recording;
  if (!r) return "Not recording";
  // The line is still English (Home is not in the account's language yet): the app's words in English too.
  const what = r.mode === "mic_note" ? "voice note" : sourceAppLabel(r.sourceApp, "en");
  return `${status.paused ? "Paused" : "Recording"} · ${what}`;
}

/** A level (0 to 1, speech sits low) as the meter's percent: four times louder, at most full. */
export const levelPercent = (level: number) => Math.round(Math.min(1, Math.max(0, (Number.isFinite(level) ? level : 0) * 4)) * 100);

function Meter({ label, level }: { label: string; level: number }) {
  const percent = levelPercent(level);
  return (
    <div className="el-rec-level">
      <span className="el-caption el-rec-level__label">{label}</span>
      {/* The level bars Settings › Microphone draws (.el-meter), as wide as the card. */}
      <span className="el-meter el-meter--wide" role="meter" aria-label={`${label} level`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
        <span className="el-meter__fill" style={fillWidth(percent / 100)} />
      </span>
    </div>
  );
}

/** A permission the system holds back: what a recording misses without it, and the system's settings one press away. */
function PermissionBanners({ recordingRef }: { recordingRef: string | null }) {
  const [permissions] = usePermissions(recordingRef ?? "");
  const missing = permissions?.missing ?? [];
  if (!permissions || !missing.length) return null;
  return (
    <>
      {missing.map((m) => (
        <Banner key={m.kind} tone="warning" icon={Icons.WarningIcon} testId={`permission-${m.kind}`}
          action={<Button size="sm" onClick={() => void api.openPrivacySettings(m.kind).catch(() => undefined)}>{SETTINGS_LABEL[permissions.platform] || "Open settings"}</Button>}>
          {m.sentence}
        </Banner>
      ))}
    </>
  );
}

export function RecordSection({ status, run, onImport }: { status: AppStatus; run: Run; onImport?: () => void }) {
  const recording = status.recording;
  const paused = !!recording && status.paused;
  const bluetooth = useBluetoothMic(recording?.clientRef ?? null);
  // Cancel asks first, and discards only the recording it asked about (another may have started meanwhile).
  const recordingNow = useRef(recording?.clientRef ?? null);
  recordingNow.current = recording?.clientRef ?? null;
  const cancel = async () => {
    const asked = recordingNow.current;
    if (!asked || !(await confirmAction(CANCEL_QUESTION))) return;
    if (recordingNow.current === asked) await run(api.cancelRecording);
  };
  const [mic, sys] = status.levels ?? [0, 0];
  const voiceNote = recording?.mode === "mic_note";
  const capture = recording?.mode === "voice_note";
  return (
    <section className="el-group el-record" aria-label="Record">
      <PermissionBanners recordingRef={recording?.clientRef ?? null} />
      {status.systemAudioNote && <Banner tone="warning" icon={Icons.WarningIcon} testId="whole-system-note">{status.systemAudioNote}.</Banner>}
      <Card className="el-record__now" label="Recording now">
        <Status kind={recording ? "recording" : "idle"} live>{recordingLine(status)}</Status>
        {recording && (
          <div className="el-record__controls" role="group" aria-label="Recording controls">
            {/* Classic's names; Stop and save is the strip's button above. */}
            {paused
              ? <Button size="sm" icon={Icons.PlayIcon} onClick={() => void run(api.resumeRecording)}>Resume recording</Button>
              : <Button size="sm" icon={Icons.PauseIcon} onClick={() => void run(api.pauseRecording)}>Pause recording</Button>}
            <Button size="sm" icon={Icons.PinIcon} title="Marks this moment in the recording" onClick={() => void run(() => api.markMoment(), "Bookmarked")}>Bookmark this moment</Button>
            <Button size="sm" variant="danger" icon={Icons.CloseIcon} onClick={() => void cancel()}>Cancel recording</Button>
          </div>
        )}
        <div className="el-record__meters">
          <Meter label="Me" level={mic} />
          <Meter label="Client" level={sys} />
        </div>
        {recording && status.deviceNote && (
          <p className={`el-caption ${status.deviceNote.warning ? "el-row__error" : "el-secondary"}`} role="status" data-testid="device-note">{status.deviceNote.text}</p>
        )}
        {recording && bluetooth && !capture && (
          <p className="el-caption el-secondary" data-testid="bluetooth-note" title={bluetooth.name}>{BLUETOOTH_NOTE} · <span dir="auto">{bluetooth.name}</span></p>
        )}
      </Card>
      <div className="el-record__grid">
        <Card className="el-record__card" label="Live captions">
          <Toggle label="Live captions" checked={status.captionsLive} onChange={() => void run(api.toggleCaptions)}
            hint={`Captions float over your other windows.${recording ? "" : " Turning them on starts a recording."}`} />
          <div className="el-record__actions">
            <Button size="sm" icon={Icons.CopyIcon} onClick={() => void run(() => api.copyLastMinutes(2), "Copied the last 2 minutes")}>Copy last 2 minutes</Button>
          </div>
        </Card>
        <Card className="el-record__card" label="Voice notes">
          <h2 className="el-body-strong">Voice notes</h2>
          <p className="el-caption el-secondary">Never recorded automatically. Speak into your microphone, or capture audio playing on this computer.</p>
          <div className="el-record__actions">
            <Button size="sm" variant="primary" icon={Icons.MicIcon} disabled={!!recording && !voiceNote} onClick={() => void run(api.toggleMicNote)}>
              {voiceNote ? "Stop and save voice note" : "Record a voice note"}
            </Button>
            <Button size="sm" icon={Icons.WaveIcon} disabled={!!recording && !capture} onClick={() => void run(api.toggleVoiceNote)}>
              {capture ? "Stop capture" : "Capture audio playing now"}
            </Button>
          </div>
        </Card>
      </div>
      <Card className="el-record__drop" label="Upload files">
        <p className="el-secondary">Drop voice notes, audio or video files anywhere in this window to transcribe them (OPUS, OGG, MP3, M4A, AAC, WAV, MP4 or WEBM).</p>
        {onImport && <Button icon={Icons.ShareIcon} onClick={onImport}>Choose files…</Button>}
      </Card>
      {status.uploadErrors.length > 0 && (
        <Card className="el-record__errors" label="Uploads waiting to retry">
          <h2 className="el-body-strong">Uploads waiting to retry</h2>
          <ul className="el-record__error-list">
            {status.uploadErrors.slice(0, 3).map((e, i) => <li key={i} className="el-caption el-row__error" dir="auto">{e}</li>)}
          </ul>
          {status.uploadErrors.length > 3 && <p className="el-caption el-secondary">{`And ${status.uploadErrors.length - 3} more`}</p>}
        </Card>
      )}
    </section>
  );
}
