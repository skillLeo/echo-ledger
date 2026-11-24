// A panel's edge that sets its width (UX-03, PLAN_V2_1_POLISH): the place's panel (200 to 360 px) and the Library's
// filters (280 to 420 px). Dragged with the pointer, which it captures, so the moves and the end are heard even off the
// window; while it moves the width is set on the panel itself, so the app doesn't redraw on every move. Let go below
// `closeBelow` and the panel snaps closed, keeping the width it had. From the keyboard (it takes focus): ←/→ 8 px,
// Shift 32 px, Home and End the least and the most, Enter the usual width; a double click puts the usual width back too.
// Right to left the arrows mirror with the page.
import type { KeyboardEvent as ReactKeyboardEvent, PointerEvent as ReactPointerEvent } from "react";

/** A width in its range (pure). */
export function clampWidth(width: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, Math.round(width)));
}

/** What a drag that ends at `raw` px does (pure): the panel closes below `closeBelow`, else keeps the width in range. */
export function dragEnd(raw: number, min: number, max: number, closeBelow?: number): { close: true } | { width: number } {
  return closeBelow !== undefined && raw < closeBelow ? { close: true } : { width: clampWidth(raw, min, max) };
}

/** The keyboard's step (pure): 8 px, 32 px with Shift. */
export const stepOf = (shift: boolean) => (shift ? 32 : 8);

export function ResizeEdge({ label, width, min, max, closeBelow, side, cssVar, onWidth, onReset, onClose }: {
  /** Its name ("Resize the Library panel"). */
  label: string;
  /** The width now (the usual one until one is set). */
  width: number;
  min: number;
  max: number;
  /** Let go below this and the panel closes (onClose). */
  closeBelow?: number;
  /** Which of the panel's edges it is: its end (the place's panel) or its start (the filters, at the page's end). */
  side: "start" | "end";
  /** The panel's own CSS variable for its width, set while dragging. */
  cssVar: string;
  onWidth: (width: number) => void;
  onReset: () => void;
  onClose?: () => void;
}) {
  // Wider is the way the edge faces: outward for the end, inward for the start; right to left mirrors both.
  const outward = (el: Element) => (getComputedStyle(el).direction === "rtl" ? -1 : 1) * (side === "end" ? 1 : -1);
  const start = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (e.button !== 0) return;
    e.preventDefault();
    const edge = e.currentTarget;
    const panel = edge.parentElement;
    const sign = outward(edge);
    const from = e.clientX;
    let raw = width;
    // The panel's own width as the app set it (none: the usual one), put back once let go.
    const before = panel?.style.getPropertyValue(cssVar) ?? "";
    const show = (w: number, closing: boolean) => {
      panel?.style.setProperty(cssVar, `${w}px`);
      if (closing) panel?.setAttribute("data-closing", "true");
      else panel?.removeAttribute("data-closing");
      edge.setAttribute("aria-valuenow", String(w));
    };
    try {
      edge.setPointerCapture?.(e.pointerId);
    } catch {
      // A pointer that is gone already can't be captured.
    }
    const move = (m: PointerEvent) => {
      raw = width + sign * (m.clientX - from);
      show(clampWidth(raw, min, max), closeBelow !== undefined && raw < closeBelow);
    };
    const end = (keep: boolean) => {
      edge.removeEventListener("pointermove", move);
      edge.removeEventListener("pointerup", up);
      edge.removeEventListener("pointercancel", cancel);
      document.body.classList.remove("el-resizing");
      // The panel's own width goes back to what the app keeps; the app then sets the new one (or closes it).
      if (before) panel?.style.setProperty(cssVar, before);
      else panel?.style.removeProperty(cssVar);
      panel?.removeAttribute("data-closing");
      edge.setAttribute("aria-valuenow", String(width));
      if (!keep) return;
      const done = dragEnd(raw, min, max, closeBelow);
      if ("close" in done) onClose?.();
      else if (done.width !== width) onWidth(done.width);
    };
    const up = () => end(true);
    const cancel = () => end(false);
    document.body.classList.add("el-resizing");
    edge.addEventListener("pointermove", move);
    edge.addEventListener("pointerup", up);
    edge.addEventListener("pointercancel", cancel);
  };
  const key = (e: ReactKeyboardEvent<HTMLDivElement>) => {
    const sign = outward(e.currentTarget);
    const step = stepOf(e.shiftKey);
    if (e.key === "ArrowRight" || e.key === "ArrowLeft") onWidth(clampWidth(width + (e.key === "ArrowRight" ? 1 : -1) * sign * step, min, max));
    else if (e.key === "Home") onWidth(min);
    else if (e.key === "End") onWidth(max);
    else if (e.key === "Enter") onReset();
    else return;
    e.preventDefault();
  };
  return (
    <div className={`el-resize-edge el-resize-edge--${side}`} role="separator" aria-orientation="vertical" aria-label={label} tabIndex={0}
      aria-valuemin={min} aria-valuemax={max} aria-valuenow={width} title="Drag to resize. Double-click for the usual width."
      data-tauri-drag-region="false" onPointerDown={start} onDoubleClick={onReset} onKeyDown={key} />
  );
}
