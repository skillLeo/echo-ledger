import type { ReactNode } from "react";
import { Button } from "./Button";
import { BackIcon, ForwardIcon, PauseIcon, PlayIcon } from "./icons";

/** 1104 → "18:24", 4000 → "1:06:40": durations read left-to-right in every language. */
export function clock(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const pad = (n: number) => String(n).padStart(2, "0");
  return h > 0 ? `${h}:${pad(m)}:${pad(s % 60)}` : `${m}:${pad(s % 60)}`;
}

export type PlayerProps = {
  playing: boolean;
  position: number;
  duration: number;
  onToggle: () => void;
  onSeek: (seconds: number) => void;
  labels?: { play: string; pause: string; seek: string; of: string };
  /** More controls after the time (speed). */
  end?: ReactNode;
  /** Back and forward 10 seconds beside Play, named by `skipLabels`. */
  onSkip?: (seconds: number) => void;
  skipLabels?: { back: string; forward: string };
};

/** Play or pause, the position slider and the time. Media controls never mirror in right-to-left. */
export function Player({
  playing, position, duration, onToggle, onSeek, labels = { play: "Play", pause: "Pause", seek: "Position", of: "of" }, end, onSkip,
  skipLabels = { back: "Back 10 seconds", forward: "Forward 10 seconds" },
}: PlayerProps) {
  return (
    <div className="el-player">
      {onSkip && <Button variant="quiet" icon={BackIcon} aria-label={skipLabels.back} title={skipLabels.back} onClick={() => onSkip(-10)} />}
      <Button variant="primary" className="el-player__play" icon={playing ? PauseIcon : PlayIcon} aria-label={playing ? labels.pause : labels.play} onClick={onToggle} />
      {onSkip && <Button variant="quiet" icon={ForwardIcon} aria-label={skipLabels.forward} title={skipLabels.forward} onClick={() => onSkip(10)} />}
      <div className="el-player__track">
        <input type="range" className="el-player__range" min={0} max={duration} step={1} value={position} aria-label={labels.seek}
          aria-valuetext={`${clock(position)} ${labels.of} ${clock(duration)}`} onChange={(e) => onSeek(Number(e.target.value))} />
        <span className="el-player__time el-num">{clock(position)} / {clock(duration)}</span>
      </div>
      {end}
    </div>
  );
}

export type Segment = { speaker: string; start: number; text: string };

/** A transcript: who spoke, when, and what; the line being played is marked. */
export function Transcript({ label, segments, activeIndex, lang, after, onPlayLine, playLabel = (s) => `Play from ${clock(s.start)}`, note }: {
  label: string;
  segments: Segment[];
  activeIndex?: number;
  lang?: string;
  /** What follows a line inside it (the screens shown at its moment). */
  after?: (index: number) => ReactNode;
  /** A line's time becomes a button that plays from it. */
  onPlayLine?: (index: number) => void;
  playLabel?: (segment: Segment) => string;
  /** A small note after a line's words ("original words" on a line a view kept as heard). */
  note?: (index: number) => ReactNode;
}) {
  return (
    <ol className={`el-transcript${onPlayLine ? " el-transcript--play" : ""}`} aria-label={label} lang={lang}>
      {segments.map((s, i) => (
        <li key={`${s.start}-${i}`} className={`el-segment${i === activeIndex ? " el-segment--active" : ""}`} aria-current={i === activeIndex || undefined}>
          <span className="el-segment__who">
            <span className="el-segment__speaker" dir="auto">{s.speaker}</span>
            {onPlayLine ? (
              <button type="button" className="el-segment__time el-segment__play el-num" aria-label={`${playLabel(s)} · ${s.speaker}`} onClick={() => onPlayLine(i)}>{clock(s.start)}</button>
            ) : <span className="el-segment__time el-num">{clock(s.start)}</span>}
          </span>
          <p className="el-segment__text" dir="auto" onClick={onPlayLine ? () => { if (!window.getSelection()?.toString()) onPlayLine(i); } : undefined}>
            {s.text}{note?.(i)}
          </p>
          {after?.(i)}
        </li>
      ))}
    </ol>
  );
}
