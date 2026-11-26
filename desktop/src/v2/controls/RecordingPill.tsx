// The recording pill in the new look (ui_v2.recording_controls, v1.8 P1.3): "● 02:14 · Omar"; a press opens the
// controls (Captions, Pause, Stop, Screens on/off and Capture now, Language) and, under the pill, the call choice's
// menu: Transcribe this call, Save audio only, Stop and delete (a voice note has only Stop and delete). A choice made
// there means no card after the call. With "At the start of the call", "Recording this call · Don't record" shows
// under the pill for 5 s. Captions are off by default (the core's choice). Media controls never mirror. The window
// never takes focus, so everything works on click; [data-hit] marks what the core keeps clickable.
import { useEffect, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import type { CallChoice } from "../../lib/ipc";
import { CALL_WORDS, PILL_MENU } from "../../lib/callChoice";
import { Button, Icons, Status } from "../components";
import { PauseIcon, PlayIcon } from "../components/icons";
import { RecordDotIcon, StopIcon } from "../../components/ControlIcons";

export type PillModel = {
  /** "02:14" */
  elapsed: string;
  /** Who or what the call is with ("Omar", "WhatsApp"). */
  name: string;
  paused: boolean;
  captions: boolean;
  /** The language chip ("Auto", "UR+EN"). */
  language: string;
  /** Screen moments: null while they are off for this recording. */
  screens: { on: boolean; count: number } | null;
  /** A failed action's sentence, shown in place of the name for a moment. */
  error?: string | null;
  /** A call (the menu offers Transcribe this call and Save audio only); false for a voice note. */
  call?: boolean;
  /** Chosen on the menu during this call. */
  choice?: CallChoice | null;
  /** "Recording this call · Don't record" (its first 5 s, "At the start of the call"). */
  banner?: boolean;
  /** What the system keeps out of this recording (2.0.6): the short line, its full sentence, and whether the other side
   * isn't being recorded (Open System Settings and Try again, never dismissed) or only not heard (dismissable). */
  warning?: { text: string; sentence: string; notRecorded: boolean } | null;
};

export type PillActions = {
  onCaptions: () => void;
  onPause: () => void;
  onStop: () => void;
  onScreens: () => void;
  onCapture: () => void;
  onLanguage: () => void;
  /** The menu's choice; `delete` is Stop and delete (also the banner's Don't record). */
  onChoice: (choice: CallChoice) => void;
  /** The warning row's actions. */
  onOpenSettings?: () => void;
  onRetrySystem?: () => void;
  onDismissWarning?: () => void;
};

/** How long the pill marks a kept screen (the flash, or the still highlight under Reduce motion). */
export const CAPTURE_MARK_MS = 1200;

/**
 * `screens:captured {count}` from the core: the latest kept screen, for the pill's mark. The mark is one short flash
 * (never a loop); under Reduce motion it is a still highlight instead (CSS), for the same time.
 */
export function useScreenCaptured(): { count: number; seq: number } | null {
  const [captured, setCaptured] = useState<{ count: number; seq: number } | null>(null);
  useEffect(() => {
    let live = true;
    const un = listen<{ count: number }>("screens:captured", (e) => {
      if (live) setCaptured((c) => ({ count: e.payload?.count ?? 0, seq: (c?.seq ?? 0) + 1 }));
    });
    return () => {
      live = false;
      void un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return captured;
}

export function RecordingPill({ model, actions, defaultOpen = false, onMenu, beforeOpen }: {
  model: PillModel;
  actions: PillActions;
  defaultOpen?: boolean;
  /** The menu opened or closed (the window grows only while it shows). */
  onMenu?: (open: boolean) => void;
  /** Grows the window before the menu is drawn, so it is never clipped for a frame. */
  beforeOpen?: () => Promise<unknown>;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const reportMenu = useRef(onMenu);
  reportMenu.current = onMenu;
  useEffect(() => reportMenu.current?.(open), [open]);
  const captured = useScreenCaptured();
  const [marked, setMarked] = useState(false);
  useEffect(() => {
    if (!captured) return;
    setMarked(true);
    const timer = window.setTimeout(() => setMarked(false), CAPTURE_MARK_MS);
    return () => window.clearTimeout(timer);
  }, [captured]);
  // Escape closes the menu (when the window has focus, as in a browser).
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);
  const status = model.paused ? `Paused ${model.elapsed}` : `Recording ${model.elapsed}`;
  const choose = (choice: CallChoice) => {
    setOpen(false);
    actions.onChoice(choice);
  };
  const items = PILL_MENU.filter((m) => model.call !== false || m.choice === "delete");

  return (
    <div className="el-pill-stack">
      <div className={`el-pill${marked ? " el-pill--captured" : ""}`} data-hit role="toolbar" aria-label="Recording controls" data-testid="recording-pill-v2">
        <span className="el-visually-hidden" role="status">
          {captured ? `Screen kept. ${captured.count} ${captured.count === 1 ? "screen" : "screens"} in this recording.` : ""}
        </span>
        <button type="button" className="el-pill__summary" aria-expanded={open} aria-controls="el-pill-controls" aria-haspopup="menu"
          aria-label={`${status}, ${model.name}. ${open ? "Hide" : "Show"} the controls`} onClick={() => {
            if (open || !beforeOpen) setOpen(!open);
            else void beforeOpen().finally(() => setOpen(true));
          }}>
          <Status kind={model.paused ? "idle" : "recording"}><span className="el-num">{model.elapsed}</span></Status>
          <span className="el-pill__sep" aria-hidden="true">·</span>
          <span className={`el-pill__name${model.error ? " el-pill__name--error" : ""}`} dir="auto">{model.error ?? model.name}</span>
        </button>
        {open && (
          <div className="el-pill__controls" id="el-pill-controls">
            <Button size="sm" variant="quiet" icon={Icons.CaptionsIcon} aria-label="Captions" aria-pressed={model.captions} title="Captions" onClick={actions.onCaptions} />
            <Button size="sm" variant="quiet" icon={model.paused ? PlayIcon : PauseIcon} aria-label={model.paused ? "Resume" : "Pause"} title={model.paused ? "Resume" : "Pause"} onClick={actions.onPause} />
            <Button size="sm" variant="quiet" icon={StopIcon} aria-label="Stop and save" title="Stop and save" onClick={actions.onStop} />
            {model.screens && (
              <>
                <Button size="sm" variant="quiet" icon={Icons.ScreenIcon} aria-label={model.screens.on ? `Screens on, ${model.screens.count} kept. Pause screens` : "Screens paused. Turn screens on"}
                  aria-pressed={model.screens.on} title={model.screens.on ? "Pause screens" : "Turn screens on"} onClick={actions.onScreens} />
                <Button size="sm" variant="quiet" icon={RecordDotIcon} aria-label="Capture the screen now" title="Capture now" disabled={!model.screens.on} onClick={actions.onCapture} />
              </>
            )}
            <Button size="sm" variant="quiet" aria-label={`Language: ${model.language}. Change`} title="Language" onClick={actions.onLanguage}>{model.language}</Button>
          </div>
        )}
      </div>
      {model.warning && (
        <div className={`el-pill-warning${model.warning.notRecorded ? " el-pill-warning--stacked" : ""}`} lang="en" data-hit role="alert" data-testid="missing-permission" title={model.warning.sentence}>
          <span className="el-pill-warning__line">
            <Icons.WarningIcon />
            <span className="el-pill-warning__text" dir="auto">{model.warning.text}</span>
          </span>
          {model.warning.notRecorded ? (
            // The sentence keeps its own line, whole; the two buttons go under it.
            <span className="el-pill-warning__buttons">
              <Button size="sm" variant="quiet" title={model.warning.sentence} onClick={actions.onOpenSettings}>Open System Settings</Button>
              <Button size="sm" variant="quiet" onClick={actions.onRetrySystem}>Try again</Button>
            </span>
          ) : (
            <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Dismiss warning" title="Dismiss" onClick={actions.onDismissWarning} />
          )}
        </div>
      )}
      {open ? (
        <div className="el-pill-menu" lang="en" role="menu" aria-label={CALL_WORDS.setting_title} data-hit data-testid="pill-menu">
          {items.map((m) => (
            <button key={m.choice} type="button" role={m.choice === "delete" ? "menuitem" : "menuitemradio"}
              aria-checked={m.choice === "delete" ? undefined : model.choice === m.choice}
              className={`el-pill-menu__item${m.choice === "delete" ? " el-pill-menu__item--danger" : ""}`} onClick={() => choose(m.choice)}>
              <span className="el-pill-menu__mark" aria-hidden="true">{model.choice === m.choice ? <Icons.CheckIcon /> : m.choice === "delete" ? <Icons.TrashIcon /> : null}</span>
              {m.label}
            </button>
          ))}
        </div>
      ) : model.banner ? (
        <div className="el-pill-banner" lang="en" data-hit role="status" data-testid="start-banner">
          <span dir="auto">{CALL_WORDS.start_banner}</span>
          <Button size="sm" variant="quiet" onClick={() => actions.onChoice("delete")}>{CALL_WORDS.dont_record}</Button>
        </div>
      ) : null}
    </div>
  );
}
