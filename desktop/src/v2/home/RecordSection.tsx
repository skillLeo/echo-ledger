// Recording itself on the v2 Record page (docs/v2/audit/parity.csv DT-HOME-007 to DT-HOME-020), as classic's Record
// section (RecordView): what is being recorded and whether it is paused, Pause / Resume, Bookmark this moment, Cancel
// (asks), the Me / Client levels, the device and Bluetooth notes, a missing permission; Live captions with Copy last 2
// minutes; voice notes from the microphone or from audio playing now; Choose files…; and the uploads waiting to retry
// with their errors. Same commands as classic. In the window's language (D-261); what the core says (a device's or a
// permission's note, an upload's error) is shown as it comes.
import { useRef } from "react";
import { api, type AppStatus } from "../../lib/ipc";
import { sourceAppLabel } from "../../lib/format";
import { BLUETOOTH_NOTE } from "../../lib/microphone";
import { confirmAction } from "../../lib/confirm";
import { fillWidth } from "../../lib/motion";
import { useBluetoothMic } from "../../views/main/RecordView";
import { SETTINGS_LABEL, usePermissions } from "../../views/main/Permissions";
import { Banner, Button, Card, Icons, Status, Toggle } from "../components";
import { lineDir, t, useLanguage } from "../../i18n";

/** Cancel recording asks first, in the same word as its button (the recording so far is thrown away). */
export function cancelQuestion() {
  return {
    title: t("Cancel this recording?"), body: t("What was recorded so far is deleted. It can't be undone."), confirm: t("Cancel recording"),
    cancel: t("Keep recording"), tone: "danger",
  } as const;
}

/** Runs a core command; `ok` is said once it is done, an error is said instead (MainApp's run). */
export type Run = <T>(fn: () => Promise<T>, ok?: string) => Promise<T | undefined>;

/** "Recording · Zoom", "Paused · voice note", "Not recording", in the window's language (the app's name as it is). */
export function recordingLine(status: Pick<AppStatus, "recording" | "paused">): string {
  const r = status.recording;
  if (!r) return t("Not recording");
  const what = r.mode === "mic_note" ? t("voice note") : sourceAppLabel(r.sourceApp);
  return status.paused ? t("Paused · :what", { what }) : t("Recording · :what", { what });
}

/** A level (0 to 1, speech sits low) as the meter's percent: four times louder, at most full. */
export const levelPercent = (level: number) => Math.round(Math.min(1, Math.max(0, (Number.isFinite(level) ? level : 0) * 4)) * 100);

/** `label` names the side ("Me", "Client"); `name` the meter ("Me level"). */
function Meter({ label, name, level }: { label: string; name: string; level: number }) {
  const percent = levelPercent(level);
  return (
    <div className="el-rec-level">
      <span className="el-caption el-rec-level__label">{label}</span>
      {/* The level bars Settings › Microphone draws (.el-meter), as wide as the card. */}
      <span className="el-meter el-meter--wide" role="meter" aria-label={name} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent}>
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
          action={<Button size="sm" onClick={() => void api.openPrivacySettings(m.kind).catch(() => undefined)}>{settingsLabel(permissions.platform)}</Button>}>
          {m.sentence}
        </Banner>
      ))}
    </>
  );
}

/** The button that opens the system's privacy settings, in the platform's words (lib/controls), in the window's language. */
function settingsLabel(platform: keyof typeof SETTINGS_LABEL): string {
  const words = SETTINGS_LABEL[platform];
  return words ? t(words) : t("Open settings");
}

export function RecordSection({ status, run, onImport }: { status: AppStatus; run: Run; onImport?: () => void }) {
  useLanguage();
  const recording = status.recording;
  const paused = !!recording && status.paused;
  const bluetooth = useBluetoothMic(recording?.clientRef ?? null);
  // Cancel asks first, and discards only the recording it asked about (another may have started meanwhile).
  const recordingNow = useRef(recording?.clientRef ?? null);
  recordingNow.current = recording?.clientRef ?? null;
  const cancel = async () => {
    const asked = recordingNow.current;
    if (!asked || !(await confirmAction(cancelQuestion()))) return;
    if (recordingNow.current === asked) await run(api.cancelRecording);
  };
  const [mic, sys] = status.levels ?? [0, 0];
  const voiceNote = recording?.mode === "mic_note";
  const capture = recording?.mode === "voice_note";
  const bluetoothNote = t(BLUETOOTH_NOTE);
  return (
    <section className="el-group el-record" aria-label={t("Record")}>
      <PermissionBanners recordingRef={recording?.clientRef ?? null} />
      {status.systemAudioNote && <Banner tone="warning" icon={Icons.WarningIcon} testId="whole-system-note">{status.systemAudioNote}.</Banner>}
      <Card className="el-record__current" label={t("Recording now")}>
        <Status kind={recording ? "recording" : "idle"} live>{recordingLine(status)}</Status>
        {recording && (
          <div className="el-record__controls" role="group" aria-label={t("Recording controls")}>
            {/* Classic's names; Stop and save is the strip's button above. */}
            {paused
              ? <Button size="sm" icon={Icons.PlayIcon} onClick={() => void run(api.resumeRecording)}>{t("Resume recording")}</Button>
              : <Button size="sm" icon={Icons.PauseIcon} onClick={() => void run(api.pauseRecording)}>{t("Pause recording")}</Button>}
            <Button size="sm" icon={Icons.PinIcon} title={t("Marks this moment in the recording")} onClick={() => void run(() => api.markMoment(), t("Bookmarked"))}>{t("Bookmark this moment")}</Button>
            <Button size="sm" variant="danger" icon={Icons.CloseIcon} onClick={() => void cancel()}>{t("Cancel recording")}</Button>
          </div>
        )}
        <div className="el-record__meters">
          <Meter label={t("Me")} name={t("Me level")} level={mic} />
          <Meter label={t("Client")} name={t("Client level")} level={sys} />
        </div>
        {recording && status.deviceNote && (
          <p className={`el-caption ${status.deviceNote.warning ? "el-row__error" : "el-secondary"}`} role="status" data-testid="device-note" dir="auto">{status.deviceNote.text}</p>
        )}
        {recording && bluetooth && !capture && (
          <p className="el-caption el-secondary" data-testid="bluetooth-note" title={bluetooth.name} dir={lineDir(bluetoothNote)}>{bluetoothNote} · <span dir="auto">{bluetooth.name}</span></p>
        )}
      </Card>
      <div className="el-record__grid">
        <Card className="el-record__card" label={t("Live captions")}>
          <Toggle label={t("Live captions")} checked={status.captionsLive} onChange={() => void run(api.toggleCaptions)}
            hint={recording ? t("Captions float over your other windows.") : t("Captions float over your other windows. Turning them on starts a recording.")} />
          <div className="el-record__actions">
            <Button size="sm" icon={Icons.CopyIcon} onClick={() => void run(() => api.copyLastMinutes(2), t("Copied the last 2 minutes"))}>{t("Copy last 2 minutes")}</Button>
          </div>
        </Card>
        <Card className="el-record__card" label={t("Voice notes")}>
          <h2 className="el-body-strong">{t("Voice notes")}</h2>
          <p className="el-caption el-secondary">{t("Never recorded automatically. Speak into your microphone, or capture audio playing on this computer.")}</p>
          <div className="el-record__actions">
            <Button size="sm" variant="primary" icon={Icons.MicIcon} disabled={!!recording && !voiceNote} onClick={() => void run(api.toggleMicNote)}>
              {voiceNote ? t("Stop and save voice note") : t("Record a voice note")}
            </Button>
            <Button size="sm" icon={Icons.WaveIcon} disabled={!!recording && !capture} onClick={() => void run(api.toggleVoiceNote)}>
              {capture ? t("Stop capture") : t("Capture audio playing now")}
            </Button>
          </div>
        </Card>
      </div>
      <Card className="el-record__drop" label={t("Upload files")}>
        <p className="el-secondary">{t("Drop voice notes, audio or video files anywhere in this window to transcribe them (OPUS, OGG, MP3, M4A, AAC, WAV, MP4 or WEBM).")}</p>
        {onImport && <Button icon={Icons.ShareIcon} onClick={onImport}>{t("Choose files…")}</Button>}
      </Card>
      {status.uploadErrors.length > 0 && (
        <Card className="el-record__errors" label={t("Uploads waiting to retry")}>
          <h2 className="el-body-strong">{t("Uploads waiting to retry")}</h2>
          <ul className="el-record__error-list">
            {status.uploadErrors.slice(0, 3).map((e, i) => <li key={i} className="el-caption el-row__error" dir="auto">{e}</li>)}
          </ul>
          {status.uploadErrors.length > 3 && <p className="el-caption el-secondary">{t("And :count more", { count: status.uploadErrors.length - 3 })}</p>}
        </Card>
      )}
    </section>
  );
}
