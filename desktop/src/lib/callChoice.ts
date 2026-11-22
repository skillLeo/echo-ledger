// The call choice (docs/v2/copy/call-choice.md): the words, the setting and the card's small rules, shared by both
// designs' after-call card, the pill's menu and Settings. The desktop's words are English (its windows have no Urdu
// yet); names and numbers stay isolated (dir="auto") so a right-to-left window reads them right.
import { useEffect, useRef, useState, type CSSProperties, type PointerEvent as ReactPointerEvent } from "react";
import type { AfterEachCall, CallChoice, CallDone, PendingItem, Settings, SettingsPatch } from "./ipc";
import { reviewDuration } from "./controls";

/** The copy deck's English words (key: the deck's key). Never "Keep" or "Skip" alone: they don't say what happens. */
export const CALL_WORDS = {
  transcribe: "Transcribe",
  save_audio_only: "Save audio only",
  delete: "Delete",
  dont_record: "Don't record",
  undo: "Undo",
  setting_title: "After each call",
  setting_ask: "Ask me",
  setting_ask_help: "A small card after each call.",
  setting_transcribe: "Transcribe every call",
  setting_transcribe_help: "No question.",
  setting_audio: "Save audio only",
  setting_audio_help: "No question. Transcribe later from the Library if you want.",
  setting_off: "Don't record calls",
  setting_off_help: "Calls are not recorded. You can still record one from the pill or Record.",
  when_title: "When to ask",
  when_after: "After the call (recommended)",
  when_start: "At the start of the call",
  pill_transcribe: "Transcribe this call",
  pill_audio: "Save audio only",
  pill_stop_delete: "Stop and delete",
  start_banner: "Recording this call",
  card_close_desktop: "Close to keep audio only",
  card_first_hint: "You can change this in Settings › After each call.",
  card_always: "Always do this?",
  card_always_yes: "Yes",
  done_transcribing: "Transcribing…",
  done_audio: "Saved as audio",
  done_deleted: "Deleted",
  grouped_review: "Review",
} as const;

/** "0:44 · just now" (card_meta). */
export const cardMeta = (durationMs: number): string => `${reviewDuration(durationMs)} · just now`;
/** "3 calls saved as audio" (grouped_audio). */
export const groupedAudio = (count: number): string => `${count} calls saved as audio`;

/** The seconds the card waits before it saves the audio only (it was 12 s). */
export const CARD_SECONDS = 8;
/** The seconds a confirmation and its Undo stay; the choice is applied then. */
export const CONFIRM_SECONDS = 5;
/** The seconds the start banner stays beside the pill. */
export const START_BANNER_SECONDS = 5;
/** How far the card is dragged sideways before letting go saves the audio only ("Swipe away"). */
export const SWIPE_AWAY_PX = 80;

/** The card's name: the recording's title, else who the call was with, else "Call". */
export function cardName(item: Pick<PendingItem, "title" | "callerName">): string {
  return item.title?.trim() || item.callerName?.trim() || "Call";
}

/** What a confirmation says first. */
export function doneText(done: Pick<CallDone, "kind">): string {
  return done.kind === "transcribe" ? CALL_WORDS.done_transcribing : done.kind === "audio" ? CALL_WORDS.done_audio : CALL_WORDS.done_deleted;
}

/** "After each call" as Settings shows it: Don't record calls while calls are not recorded by themselves. */
export function afterEachCallOf(settings: Pick<Settings, "autoMode" | "afterCall">): AfterEachCall {
  return settings.autoMode ? settings.afterCall : "off";
}

/** The patch that chooses `value`: Don't record calls turns automatic recording off and keeps the choice under it. */
export function afterEachCallPatch(value: AfterEachCall): SettingsPatch {
  return value === "off" ? { autoMode: false } : { autoMode: true, afterCall: value };
}

/** The four choices with their help lines, in the deck's order. */
export const AFTER_EACH_CALL_OPTIONS: { value: AfterEachCall; label: string; hint: string }[] = [
  { value: "ask", label: CALL_WORDS.setting_ask, hint: CALL_WORDS.setting_ask_help },
  { value: "always_transcribe", label: CALL_WORDS.setting_transcribe, hint: CALL_WORDS.setting_transcribe_help },
  { value: "always_audio", label: CALL_WORDS.setting_audio, hint: CALL_WORDS.setting_audio_help },
  { value: "off", label: CALL_WORDS.setting_off, hint: CALL_WORDS.setting_off_help },
];

/** "Always do this? Transcribe every call": the deck's question with the setting Yes will choose (offered only for
 * Transcribe every call and Save audio only, never Don't record calls). */
export function alwaysOffer(value: AfterEachCall): string {
  return `${CALL_WORDS.card_always} ${AFTER_EACH_CALL_OPTIONS.find((o) => o.value === value)?.label ?? ""}`.trim();
}

/** The rows a list of grouped calls shows before "+N more" (the core's after_call::GROUP_ROWS). */
export const GROUP_ROWS = 3;

/** "4 calls" (grouped_mixed): the grouped card's first line when its calls had different choices. */
export const groupedMixed = (count: number): string => `${count} calls`;

/** The grouped card's first line: "3 calls saved as audio" when every call in it is saved as audio, else "3 calls". */
export function groupHeadline(calls: Pick<CallDone, "kind">[]): string {
  return calls.length > 0 && calls.every((c) => c.kind === "audio") ? groupedAudio(calls.length) : groupedMixed(calls.length);
}

/**
 * The rows a list of grouped calls shows (the core's after_call::visible_rows): every Delete, always, so its Undo is
 * always there, and the newest Transcribe and Save audio only rows up to GROUP_ROWS rows in all; the others fold into
 * "+N more" (their audio is kept either way).
 */
export function visibleRows<T extends Pick<CallDone, "kind">>(calls: T[]): { shown: T[]; more: number } {
  const deletes = calls.filter((c) => c.kind === "delete").length;
  const others = calls.length - deletes;
  const room = Math.min(Math.max(GROUP_ROWS - deletes, 0), others);
  const more = others - room;
  let seen = 0;
  return { shown: calls.filter((c) => c.kind === "delete" || ++seen > more), more };
}

/** "+2 more": the folded rows. */
export const moreRows = (count: number): string => `+${count} more`;

/** "When to ask" under Ask me. */
export const ASK_WHEN_OPTIONS: { value: Settings["askWhen"]; label: string }[] = [
  { value: "after", label: CALL_WORDS.when_after },
  { value: "start", label: CALL_WORDS.when_start },
];

/** The pill's menu: what each item chooses. */
export const PILL_MENU: { choice: CallChoice; label: string }[] = [
  { choice: "transcribe", label: CALL_WORDS.pill_transcribe },
  { choice: "audio", label: CALL_WORDS.pill_audio },
  { choice: "delete", label: CALL_WORDS.pill_stop_delete },
];

/** The progress line's style: it runs from where the time is to empty over the seconds left (one CSS animation, which
 * the stylesheet stops under Reduce motion). */
export function progressStyle(deadlineAt: string | null, now: number): CSSProperties {
  const left = deadlineAt ? Math.max(0, Math.min(CARD_SECONDS, (Date.parse(deadlineAt) - now) / 1000)) : CARD_SECONDS;
  return { animationDuration: `${left}s`, "--from": String(left / CARD_SECONDS) } as CSSProperties;
}

/**
 * Esc on the card: Save audio only (the window cannot take focus, so it reaches it when it has focus, as in a browser;
 * the × is always there).
 */
export function useEscape(on: boolean, action: () => void) {
  const latest = useRef(action);
  latest.current = action;
  useEffect(() => {
    if (!on) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") latest.current();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [on]);
}

/**
 * "Swipe away to keep audio only": the card follows a sideways drag and, let go past [SWIPE_AWAY_PX], saves the audio
 * only; less than that, it goes back. Buttons inside the card keep their clicks.
 */
export function useSwipeAway(onAway: () => void): { style: CSSProperties; handlers: Record<string, (e: ReactPointerEvent<HTMLElement>) => void> } {
  const [dx, setDx] = useState(0);
  const start = useRef<number | null>(null);
  const end = (e: ReactPointerEvent<HTMLElement>) => {
    if (start.current === null) return;
    const moved = e.clientX - start.current;
    start.current = null;
    setDx(0);
    if (Math.abs(moved) >= SWIPE_AWAY_PX) onAway();
  };
  return {
    style: dx ? { transform: `translateX(${dx}px)`, opacity: Math.max(0.3, 1 - Math.abs(dx) / (SWIPE_AWAY_PX * 2.5)) } : {},
    handlers: {
      onPointerDown: (e) => {
        if (e.button !== 0 || (e.target as Element).closest("button")) return;
        start.current = e.clientX;
        (e.currentTarget as Element).setPointerCapture?.(e.pointerId);
      },
      onPointerMove: (e) => {
        if (start.current !== null) setDx(e.clientX - start.current);
      },
      onPointerUp: end,
      onPointerCancel: () => {
        start.current = null;
        setDx(0);
      },
    },
  };
}
