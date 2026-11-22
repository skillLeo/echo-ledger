// The after-call card in the new look (the call choice, docs/v2/copy/call-choice.md), next to the pill: the app's
// logo, the name or "Call" and "0:44 · just now"; Delete as a quiet text button and Transcribe as the one filled
// button; an 8 s line, then "Swipe away to keep audio only". The ×, Esc, a swipe or 8 s with no choice save the audio
// only. A solid surface with radius 16 in the app's font; it slides in over 200 ms, and the line is its only animation
// (both stop under Reduce motion). No sound. The name opens the recording to rename it or pick a project.
import { useMemo } from "react";
import type { AfterEachCall, CallDone, CallGroup, PendingItem } from "../../lib/ipc";
import { CALL_WORDS, cardMeta, cardName, doneText, groupedAudio, progressStyle, useEscape, useSwipeAway } from "../../lib/callChoice";
import { Button, Icons } from "../components";

export type AfterCallActions = {
  onTranscribe: () => void;
  /** The ×, Esc or a swipe: Save audio only. */
  onSaveAudio: () => void;
  onDelete: () => void;
  /** Opens the recording in the main window to rename it or pick a project. */
  onOpen: () => void;
};

export function AfterCallCard({ item, hint = false, screens = 0, busy = false, error = null, actions, now = Date.now() }: {
  item: PendingItem;
  /** The first card ever: where to change it. */
  hint?: boolean;
  /** Screen moments kept during the call (desktop). */
  screens?: number;
  busy?: boolean;
  error?: string | null;
  actions: AfterCallActions;
  /** For tests and screenshots: a fixed clock. */
  now?: number;
}) {
  const name = cardName(item);
  const meta = cardMeta(item.durationMs);
  useEscape(!busy, actions.onSaveAudio);
  const swipe = useSwipeAway(actions.onSaveAudio);
  // Worked out once per deadline: the line runs on by itself from there (a new deadline, after Undo, starts it again).
  const line = useMemo(() => progressStyle(item.deadlineAt, now), [item.deadlineAt]);

  return (
    <section className="el-aftercall" lang="en" data-hit aria-label={name} aria-describedby={`el-aftercall-swipe-${item.id}`} data-testid="after-call-card"
      style={swipe.style} {...swipe.handlers}>
      <div className="el-aftercall__head">
        <span className="el-aftercall__logo" aria-hidden="true"><Icons.SourceAppIcon app={item.sourceApp} /></span>
        <button type="button" className="el-aftercall__name" onClick={actions.onOpen} title="Rename or choose a project"
          aria-label={`${name}, ${meta}${screens > 0 ? `, ${screens} screens` : ""}. Rename or choose a project`}>
          <span className="el-aftercall__title" dir="auto">{name}</span>
          <span className="el-caption el-secondary" dir="auto">{meta}{screens > 0 ? ` · ${screens} screen${screens === 1 ? "" : "s"}` : ""}</span>
        </button>
        <Button variant="quiet" size="sm" icon={Icons.CloseIcon} className="el-aftercall__close" aria-label={CALL_WORDS.save_audio_only} title={CALL_WORDS.save_audio_only}
          disabled={busy} onClick={actions.onSaveAudio} />
      </div>
      {error && <p className="el-field__error" role="alert">{error}</p>}
      <div className="el-aftercall__actions">
        <Button variant="quiet" size="sm" disabled={busy} onClick={actions.onDelete}>{CALL_WORDS.delete}</Button>
        <Button variant="primary" size="sm" disabled={busy} onClick={actions.onTranscribe}>{CALL_WORDS.transcribe}</Button>
      </div>
      <span className="el-aftercall__line" aria-hidden="true"><span key={item.deadlineAt ?? ""} style={line} /></span>
      <p className="el-caption el-secondary el-aftercall__swipe" id={`el-aftercall-swipe-${item.id}`} dir="auto">{CALL_WORDS.card_swipe}</p>
      {hint && <p className="el-caption el-secondary" data-testid="call-hint" dir="auto">{CALL_WORDS.card_first_hint}</p>}
    </section>
  );
}

/** One confirmation with Undo for 5 s: "Transcribing…", "Saved as audio · Transcribe", "Deleted"; once, "Always do
 * this?" [Yes] under it. */
export function CallDoneCard({ done, busy = false, error = null, onUndo, onTranscribe, onAlways }: {
  done: CallDone;
  busy?: boolean;
  error?: string | null;
  onUndo: () => void;
  onTranscribe: () => void;
  onAlways: (value: AfterEachCall) => void;
}) {
  const offer = done.offerAlways;
  return (
    <section className="el-aftercall el-aftercall--done" lang="en" data-hit role="status" aria-label={`${doneText(done)} ${cardName(done)}`} data-testid="call-done">
      <div className="el-aftercall__done">
        <span className="el-aftercall__done-text">
          <span className="el-body-strong" dir="auto">{doneText(done)}</span>
          <span className="el-secondary el-aftercall__done-name" dir="auto">{cardName(done)}</span>
        </span>
        {done.kind === "audio" && <Button variant="quiet" size="sm" disabled={busy} onClick={onTranscribe}>{CALL_WORDS.transcribe}</Button>}
        <Button variant="quiet" size="sm" disabled={busy} onClick={onUndo}>{CALL_WORDS.undo}</Button>
      </div>
      {offer && (
        <div className="el-aftercall__done" data-testid="call-always">
          <span className="el-caption el-secondary" dir="auto">{CALL_WORDS.card_always}</span>
          <Button variant="quiet" size="sm" disabled={busy} onClick={() => onAlways(offer)}>{CALL_WORDS.card_always_yes}</Button>
        </div>
      )}
      {error && <p className="el-field__error" role="alert">{error}</p>}
    </section>
  );
}

/** Back-to-back calls: "3 calls saved as audio · Review", never a stack of cards. */
export function CallGroupCard({ group, busy = false, onReview, onClose }: { group: CallGroup; busy?: boolean; onReview: () => void; onClose: () => void }) {
  return (
    <section className="el-aftercall el-aftercall--done" lang="en" data-hit role="status" aria-label={groupedAudio(group.count)} data-testid="call-group">
      <div className="el-aftercall__done">
        <span className="el-aftercall__logo" aria-hidden="true"><Icons.WaveIcon /></span>
        <span className="el-aftercall__done-text el-body-strong" dir="auto">{groupedAudio(group.count)}</span>
        <Button variant="quiet" size="sm" disabled={busy} onClick={onReview}>{CALL_WORDS.grouped_review}</Button>
        <Button variant="quiet" size="sm" icon={Icons.CloseIcon} aria-label="Close" title="Close" disabled={busy} onClick={onClose} />
      </div>
    </section>
  );
}
