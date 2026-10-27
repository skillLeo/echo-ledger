import { useEffect, useLayoutEffect, useReducer, useRef, useState, type CSSProperties } from "react";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import { announceReady, api, listenTo, subscribeCaptions, type CaptionEvent, type CaptionSize, type CaptionStyle } from "../../lib/ipc";
import { useHitRegions } from "../../lib/hitRegions";
import { CloseIcon, CopyIcon, GripIcon } from "../../components/Icons";
import { devanagariToUrdu } from "../../lib/devanagari";

/** Final lines kept for scrolling back; older ones are dropped. */
export const MAX_CAPTION_LINES = 200;
/** How close to the end (CSS px) still counts as "at the live end", so auto-scroll keeps following. */
export const FOLLOW_THRESHOLD_PX = 8;
/** Scroll distance (CSS px) per hit-region re-measure bucket; about one caption line. */
const SCROLL_BUCKET_PX = 24;

export interface CaptionLine {
  id: number;
  channel: number;
  speaker: string;
  text: string;
}

export interface CaptionState {
  lines: CaptionLine[];
  partial: Record<number, string>;
  live: boolean;
  message: string | null;
  /** Id for the next final line (stable React keys while old lines are dropped). */
  nextId: number;
}

export const initialCaptions: CaptionState = { lines: [], partial: {}, live: false, message: null, nextId: 0 };

/** Pure reducer (unit-tested): keeps the last MAX_CAPTION_LINES final lines plus the current partial per speaker. */
export function captionsReducer(state: CaptionState, event: CaptionEvent): CaptionState {
  switch (event.kind) {
    case "partial":
      // Display only: Hindi letters from an engine that guessed Hindi are shown in Urdu script.
      return { ...state, partial: { ...state.partial, [event.channel]: devanagariToUrdu(event.text) } };
    case "final": {
      const line = { id: state.nextId, channel: event.channel, speaker: event.speaker, text: devanagariToUrdu(event.text) };
      const lines = [...state.lines, line].slice(-MAX_CAPTION_LINES);
      const partial = { ...state.partial };
      delete partial[event.channel];
      return { ...state, lines, partial, nextId: state.nextId + 1 };
    }
    case "status":
      return { ...state, live: event.live, message: event.message };
    case "clear":
      return initialCaptions;
  }
}

/** The "Caption lines" setting: 2, 3 or 5 visible lines; anything else shows 3. */
export function visibleLines(value: unknown): 2 | 3 | 5 {
  return value === 2 || value === 3 || value === 5 ? value : 3;
}

/** Caption text size in CSS px for the "Text size" setting (the overlay window scales with it). */
export function captionFontPx(size: CaptionSize | undefined): number {
  return size === "small" ? 13 : size === "large" ? 19 : 15;
}

/** The overlay's style variables: text size, card opacity (over the theme's glass colour) and speaker colours (theme colours when unset). */
export function captionStyleVars(style: CaptionStyle | null): CSSProperties {
  const opacity = Math.min(100, Math.max(40, style?.opacity ?? 85)) / 100;
  const vars: Record<string, string> = {
    "--caption-font": `${captionFontPx(style?.size)}px`,
    "--caption-alpha": `${opacity}`,
  };
  if (style?.meColor) vars["--caption-me"] = style.meColor;
  if (style?.clientColor) vars["--caption-client"] = style.clientColor;
  return vars as CSSProperties;
}

/** True when the captions should hide: auto-hide is on and nothing new arrived for that many seconds. */
export function isIdle(autoHideSeconds: number, lastActivity: number, now: number): boolean {
  return autoHideSeconds > 0 && now - lastActivity >= autoHideSeconds * 1000;
}

/** True when a scroll area shows its last pixels (within the follow threshold). */
export function isAtEnd(el: Pick<HTMLElement, "scrollTop" | "scrollHeight" | "clientHeight">): boolean {
  return el.scrollHeight - el.scrollTop - el.clientHeight <= FOLLOW_THRESHOLD_PX;
}

/**
 * Translucent caption overlay: never focused. The header (drag, copy, close) never scrolls away; the caption body
 * shows 2, 3 or 5 lines and scrolls. Click-through except the toolbar, the scroll area and "Jump to live".
 */
export default function OverlayApp() {
  const [state, dispatch] = useReducer(captionsReducer, initialCaptions);
  const [visible, setVisible] = useState(false);
  const [lines, setLines] = useState<2 | 3 | 5>(3);
  const [style, setStyle] = useState<CaptionStyle | null>(null);
  const [lastActivity, setLastActivity] = useState(() => Date.now());
  const [idle, setIdle] = useState(false);
  const [following, setFollowing] = useState(true);
  const [scrollBucket, setScrollBucket] = useState(0);
  const bodyRef = useRef<HTMLDivElement>(null);
  const followingRef = useRef(true);

  useEffect(() => {
    let alive = true;
    const subscribed = subscribeCaptions((e) => {
      dispatch(e);
      if (e.kind === "status" && e.live) setVisible(true);
      if (e.kind === "partial" || e.kind === "final" || (e.kind === "status" && e.live)) setLastActivity(Date.now());
    }).catch(() => undefined);
    const un = listenTo("overlay:visibility", (shown) => setVisible(shown));
    announceReady([subscribed, un], () => alive);
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  useEffect(() => {
    let changedMeanwhile = false;
    let alive = true;
    api
      .settings()
      .then((s) => {
        if (alive && !changedMeanwhile) {
          setLines(visibleLines(s.captionLines));
          setStyle(s.captionStyle ?? null);
        }
      })
      .catch(() => undefined);
    const un = listenTo("settings:changed", (s) => {
      changedMeanwhile = true;
      setLines(visibleLines(s.captionLines));
      setStyle(s.captionStyle ?? null);
    });
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  // Auto-hide: the card fades after the chosen quiet time and comes back with the next caption.
  const autoHide = style?.autoHideSeconds ?? 0;
  useEffect(() => {
    setIdle(false);
    if (!autoHide || !visible) return;
    const timer = window.setTimeout(() => setIdle(isIdle(autoHide, lastActivity, Date.now())), autoHide * 1000);
    return () => window.clearTimeout(timer);
  }, [autoHide, lastActivity, visible]);

  const scrollToEnd = () => {
    const body = bodyRef.current;
    if (body) body.scrollTop = body.scrollHeight;
  };

  const follow = (value: boolean) => {
    followingRef.current = value;
    setFollowing(value);
  };

  // New text, a different line count or showing the card: stay on the live end unless the user scrolled up.
  useLayoutEffect(() => {
    if (followingRef.current) scrollToEnd();
  }, [state, lines, visible]);

  // Showing the captions again always starts at the live end.
  useLayoutEffect(() => {
    if (!visible) return;
    follow(true);
    scrollToEnd();
  }, [visible]);

  // The body can also shrink when the window does; keep the live end in view while following.
  useEffect(() => {
    const body = bodyRef.current;
    if (!body) return;
    const keepEnd = () => {
      if (followingRef.current) scrollToEnd();
    };
    const resize = new ResizeObserver(keepEnd);
    resize.observe(body);
    window.addEventListener("resize", keepEnd);
    return () => {
      resize.disconnect();
      window.removeEventListener("resize", keepEnd);
    };
  }, []);

  const onScroll = () => {
    const body = bodyRef.current;
    if (!body) return;
    const atEnd = isAtEnd(body);
    if (atEnd !== followingRef.current) follow(atEnd);
    setScrollBucket(Math.round(body.scrollTop / SCROLL_BUCKET_PX));
  };

  const jumpToLive = () => {
    follow(true);
    scrollToEnd();
  };

  useHitRegions([visible, idle, state.lines.length, lines, scrollBucket, following]);

  const startDrag = async () => {
    await getCurrentWebviewWindow().startDragging().catch(() => undefined);
    window.setTimeout(() => api.snapOverlay().catch(() => undefined), 400);
  };

  const partials = Object.entries(state.partial);
  const compact = !!style?.compact;
  const shown = visible && !idle;
  return (
    <div className={`overlay-root ${visible ? "" : "overlay-hidden"}${idle ? " overlay-idle" : ""}${compact ? " compact" : ""}`} style={captionStyleVars(style)}>
      <div className="overlay-card" data-testid="caption-card">
        <div className="overlay-head" data-testid="caption-header">
          <span className={`status-dot ${state.live ? "live" : ""}`} />
          <span>{state.live ? "Live" : "Captions"}</span>
          <div className="overlay-tools" data-hit={!idle || undefined}>
            <button className="icon-btn grip" title="Drag" onMouseDown={startDrag}><GripIcon size={14} /></button>
            <button className="icon-btn" title="Copy last 2 minutes" onClick={() => api.copyLastMinutes(2).catch(() => undefined)}><CopyIcon size={13} /></button>
            <button className="icon-btn" title="Hide captions" aria-label="Hide captions" onClick={() => api.hideCaptions().catch(() => undefined)}><CloseIcon size={14} /></button>
          </div>
        </div>
        <div
          className="overlay-body"
          data-testid="caption-body"
          data-hit={shown || undefined}
          ref={bodyRef}
          onScroll={onScroll}
          style={{ maxHeight: `calc(${lines} * var(--caption-line-height))` }}
        >
          {state.message && <div className="caption-line small caption-message">{state.message}</div>}
          {state.lines.length === 0 && partials.length === 0 && !state.message && <div className="caption-line partial">Listening…</div>}
          {state.lines.map((line) => (
            <div className={`caption-line ch-${line.channel === 2 ? 2 : 1}`} key={line.id}><span className={`who spk-${line.channel === 2 ? 2 : 1}`}>{line.speaker}</span>{line.text}</div>
          ))}
          {partials.map(([channel, text]) => (
            <div className={`caption-line partial ch-${channel === "2" ? 2 : 1}`} key={`p${channel}`}><span className={`who spk-${channel === "2" ? 2 : 1}`}>{channel === "2" ? "Client" : "Me"}</span>{text}</div>
          ))}
        </div>
        {shown && !following && (
          <button className="overlay-jump" data-hit onClick={jumpToLive}>Jump to live</button>
        )}
      </div>
    </div>
  );
}
