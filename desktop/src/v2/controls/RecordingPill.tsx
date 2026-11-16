// The recording pill in the new look (ui_v2.recording_controls, v1.8 P1.3): "● 02:14 · Omar"; a press opens the
// controls: Captions, Pause, Stop, Screens on/off and Capture now, Language, Discard. Captions are off by default (the
// core's choice). Media controls never mirror. The window never takes focus, so everything works on click; [data-hit]
// marks what the core keeps clickable.
import { useState } from "react";
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
};

export type PillActions = {
  onCaptions: () => void;
  onPause: () => void;
  onStop: () => void;
  onScreens: () => void;
  onCapture: () => void;
  onLanguage: () => void;
  onDiscard: () => void;
};

export function RecordingPill({ model, actions, defaultOpen = false }: { model: PillModel; actions: PillActions; defaultOpen?: boolean }) {
  const [open, setOpen] = useState(defaultOpen);
  const [confirming, setConfirming] = useState(false);
  const status = model.paused ? `Paused ${model.elapsed}` : `Recording ${model.elapsed}`;

  return (
    <div className="el-pill" data-hit role="toolbar" aria-label="Recording controls" data-testid="recording-pill-v2">
      <button type="button" className="el-pill__summary" aria-expanded={open} aria-controls="el-pill-controls"
        aria-label={`${status}, ${model.name}. ${open ? "Hide" : "Show"} the controls`} onClick={() => { setOpen(!open); setConfirming(false); }}>
        <Status kind={model.paused ? "idle" : "recording"}><span className="el-num">{model.elapsed}</span></Status>
        <span className="el-pill__sep" aria-hidden="true">·</span>
        <span className={`el-pill__name${model.error ? " el-pill__name--error" : ""}`} dir="auto">{model.error ?? model.name}</span>
      </button>
      {open && !confirming && (
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
          <Button size="sm" variant="quiet" icon={Icons.TrashIcon} aria-label="Discard this recording" title="Discard" onClick={() => setConfirming(true)} />
        </div>
      )}
      {confirming && (
        <div className="el-pill__controls" role="group" aria-label="Discard this recording?">
          <span className="el-pill__ask">Discard this recording?</span>
          <Button size="sm" variant="danger" onClick={() => { setConfirming(false); actions.onDiscard(); }}>Discard</Button>
          <Button size="sm" variant="quiet" onClick={() => setConfirming(false)}>Keep recording</Button>
        </div>
      )}
    </div>
  );
}
