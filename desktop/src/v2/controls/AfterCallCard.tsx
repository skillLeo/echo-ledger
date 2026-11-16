// The after-call card in the new look (ui_v2.after_call, v1.8 P1.1), by the tray: the app's icon, "Name · 4:12" and the
// screens kept, Transcribe (the main action), Save audio, Delete; the name opens the recording to rename it or pick a
// project; a thin line shows the time left, and with no answer the audio is saved, not transcribed (the core's
// deadline). Delete goes through the one feedback system: a Toast with Undo.
import { useEffect, useState } from "react";
import { afterCallTitle, reviewDuration, secondsLeft } from "../../lib/controls";
import type { PendingItem, SavedToast } from "../../lib/ipc";
import { Button, Icons, Progress, Toast } from "../components";
import { AFTER_CALL_SECONDS } from "./commands";

export type AfterCallActions = {
  onTranscribe: () => void;
  onSaveAudio: () => void;
  onDelete: () => void;
  /** Opens the recording in the main window to rename it or pick a project. */
  onOpen: () => void;
};

/** "Omar · 4:12", "Voice note · 0:48". */
export function cardTitle(item: Pick<PendingItem, "title" | "callerName" | "sourceApp" | "mode" | "durationMs">): string {
  return `${afterCallTitle(item)} · ${reviewDuration(item.durationMs)}`;
}

/** The time-left line's words: what happens when it runs out. */
export function timeLeftText(seconds: number): string {
  const s = Math.ceil(seconds);
  return s <= 0 ? "Saving the audio" : `Saves the audio in ${s} second${s === 1 ? "" : "s"}`;
}

export function AfterCallCard({ item, screens = 0, busy = false, error = null, actions, now: fixedNow }: {
  item: PendingItem;
  /** Screen moments kept during the call (desktop). */
  screens?: number;
  busy?: boolean;
  error?: string | null;
  actions: AfterCallActions;
  /** For tests and screenshots: a fixed clock. */
  now?: number;
}) {
  const [now, setNow] = useState(() => fixedNow ?? Date.now());
  useEffect(() => {
    if (fixedNow !== undefined) return;
    const timer = window.setInterval(() => setNow(Date.now()), 250);
    return () => window.clearInterval(timer);
  }, [fixedNow]);
  const left = secondsLeft(item.deadlineAt, fixedNow ?? now);
  const title = cardTitle(item);

  return (
    <section className="el-aftercall" data-hit aria-label={`After the call: ${title}`} data-testid="after-call-card">
      <div className="el-aftercall__head">
        <span className="el-row__leading el-row__leading--avatar" aria-hidden="true"><Icons.SourceAppIcon app={item.sourceApp} /></span>
        <button type="button" className="el-aftercall__name" onClick={actions.onOpen} title="Rename or choose a project"
          aria-label={`${title}${screens > 0 ? `, ${screens} screens` : ""}. Rename or choose a project`}>
          <span className="el-aftercall__title" dir="auto">{title}</span>
          {screens > 0 && <span className="el-caption">{`${screens} screen${screens === 1 ? "" : "s"}`}</span>}
        </button>
      </div>
      {error && <p className="el-field__error" role="alert">{error}</p>}
      <div className="el-aftercall__actions">
        <Button variant="primary" size="sm" disabled={busy} onClick={actions.onTranscribe}>Transcribe</Button>
        <Button size="sm" disabled={busy} onClick={actions.onSaveAudio}>Save audio</Button>
        <Button size="sm" variant="quiet" icon={Icons.TrashIcon} disabled={busy} onClick={actions.onDelete}>Delete</Button>
      </div>
      {left !== null && (
        <Progress label="Time to answer" value={Math.min(left, AFTER_CALL_SECONDS)} max={AFTER_CALL_SECONDS} valueText={timeLeftText(left)} />
      )}
    </section>
  );
}

/** "Deleted · Omar · 4:12" with Undo, while the core can still bring the files back. */
export function DeletedToast({ item, onUndo, onClose }: { item: SavedToast; onUndo: () => void; onClose: () => void }) {
  return <Toast message={`Deleted · ${cardTitle(item)}`} actionLabel="Undo" onAction={onUndo} onClose={onClose} duration={0} />;
}

/** "Saved · Omar · 4:12" with Undo (the call was kept by itself). */
export function SavedToastV2({ item, onUndo, onClose }: { item: SavedToast; onUndo: () => void; onClose: () => void }) {
  return <Toast message={`Saved · ${cardTitle(item)}`} actionLabel="Undo" onAction={onUndo} onClose={onClose} duration={0} />;
}
