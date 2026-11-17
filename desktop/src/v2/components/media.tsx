import type { ReactNode } from "react";
import { Button } from "./Button";
import { PauseIcon, PlayIcon } from "./icons";

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
};

/** Play or pause, the position slider and the time. Media controls never mirror in right-to-left. */
export function Player({ playing, position, duration, onToggle, onSeek, labels = { play: "Play", pause: "Pause", seek: "Position", of: "of" }, end }: PlayerProps) {
  return (
    <div className="el-player">
      <Button variant="primary" className="el-player__play" icon={playing ? PauseIcon : PlayIcon} aria-label={playing ? labels.pause : labels.play} onClick={onToggle} />
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
export function Transcript({ label, segments, activeIndex, lang, after }: {
  label: string;
  segments: Segment[];
  activeIndex?: number;
  lang?: string;
  /** What follows a line inside it (the screens shown at its moment). */
  after?: (index: number) => ReactNode;
}) {
  return (
    <ol className="el-transcript" aria-label={label} lang={lang}>
      {segments.map((s, i) => (
        <li key={`${s.start}-${i}`} className={`el-segment${i === activeIndex ? " el-segment--active" : ""}`} aria-current={i === activeIndex || undefined}>
          <span className="el-segment__who">
            <span className="el-segment__speaker" dir="auto">{s.speaker}</span>
            <span className="el-segment__time el-num">{clock(s.start)}</span>
          </span>
          <p className="el-segment__text" dir="auto">{s.text}</p>
          {after?.(i)}
        </li>
      ))}
    </ol>
  );
}
