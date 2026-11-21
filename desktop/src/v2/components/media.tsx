import type { CSSProperties, MouseEvent, ReactNode } from "react";
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
    // dir="ltr": skip back, play, skip forward and the slider read left to right in every language (D4 7).
    <div className="el-player" dir="ltr">
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
export function Transcript({ label, segments, activeIndex, lang, after, onPlayLine, playLabel = (s) => `Play from ${clock(s.start)}`, note, renderText, lineTitle }: {
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
  /** A line's words drawn another way (Find's marks, the line being edited). */
  renderText?: (index: number) => ReactNode | undefined;
  /** A line's tooltip and its dotted look (the engine was not sure of the whole line). */
  lineTitle?: (index: number) => string | undefined;
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
          <p className={`el-segment__text${lineTitle?.(i) ? " el-segment__text--unsure" : ""}`} dir="auto" title={lineTitle?.(i)}
            onClick={onPlayLine ? () => { if (!window.getSelection()?.toString()) onPlayLine(i); } : undefined}>
            {renderText?.(i) ?? s.text}{note?.(i)}
          </p>
          {after?.(i)}
        </li>
      ))}
    </ol>
  );
}

/** One bar of a waveform: its height (0 to 1) and the side that talks most in it (1 me, 2 the other side). */
export type WaveBar = { level: number; channel: 1 | 2 };
/** A mark on the waveform (a bookmark): where, and its name for the tooltip. */
export type WaveMark = { id: string | number; atMs: number; title: string };

/**
 * Who speaks when (bars coloured by side), the marks (bookmarks) and how far it has played, as classic's player. A press
 * seeks there. It draws for the pointer only: the Player's Position slider is the control for the keyboard and screen
 * readers, so the waveform itself is hidden from them.
 */
export function Waveform({ bars, marks = [], durationMs, nowMs, onSeek }: {
  bars: readonly WaveBar[];
  marks?: readonly WaveMark[];
  durationMs: number;
  nowMs: number;
  onSeek: (ms: number) => void;
}) {
  if (!(durationMs > 0) || (bars.length === 0 && marks.length === 0)) return null;
  const share = (ms: number) => Math.min(100, Math.max(0, (ms / durationMs) * 100));
  const played = share(nowMs);
  const seekAt = (e: MouseEvent<HTMLDivElement>) => {
    const box = e.currentTarget.getBoundingClientRect();
    if (box.width > 0) onSeek(Math.max(0, Math.min(durationMs, ((e.clientX - box.left) / box.width) * durationMs)));
  };
  const at = (name: string, value: string) => ({ [name]: value }) as CSSProperties;
  return (
    <div className="el-wave" dir="ltr" aria-hidden="true" data-testid="waveform" onClick={seekAt}>
      {bars.map((b, i) => (
        <span key={i} className={`el-wave__bar el-wave__bar--${b.channel}${((i + 0.5) / bars.length) * 100 <= played ? " el-wave__bar--played" : ""}`}
          style={at("--el-wave-level", `${Math.round(b.level * 100)}%`)} />
      ))}
      {marks.map((m) => <span key={m.id} className="el-wave__mark" title={m.title} data-mark={m.id} style={at("--el-wave-at", String(share(m.atMs) / 100))} />)}
    </div>
  );
}
