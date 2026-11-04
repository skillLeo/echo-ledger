// Moving recordings by dragging them onto a project or the Inbox in the sidebar (since v1.5). This uses pointer events,
// not the page's drag and drop: the window takes dropped files itself, and on Windows that switches the page's own drag
// and drop off. A row starts a drag once the pointer has moved a few pixels with the button down; drop targets are the
// elements carrying `data-drop-project` (a project's id, empty for the Inbox).

/** How far the pointer moves before a press on a row becomes a drag. */
export const DRAG_THRESHOLD_PX = 6;
export const DROP_ATTRIBUTE = "data-drop-project";

/** What is being dragged: the recordings and the words on the card under the pointer. */
export interface RecordingDrag {
  ids: string[];
  label: string;
}

/** The drop target under a point: its element and project (null = the Inbox); null when there is none. */
export function dropTargetAt(x: number, y: number, doc: Document = document): { element: HTMLElement; projectId: string | null } | null {
  const hit = typeof doc.elementFromPoint === "function" ? doc.elementFromPoint(x, y) : null;
  const target = hit ? (hit.closest(`[${DROP_ATTRIBUTE}]`) as HTMLElement | null) : null;
  if (!target) return null;
  const value = target.getAttribute(DROP_ATTRIBUTE) ?? "";
  return { element: target, projectId: value === "" ? null : value };
}

/** "Moving “Kick-off”", "Moving 3 recordings". */
export function dragLabel(titles: readonly (string | null | undefined)[]): string {
  return titles.length === 1 ? `Moving “${titles[0] || "recording"}”` : `Moving ${titles.length} recordings`;
}

let endedAt = 0;

/** True for the click that ends a drag (asked once): the row it started on must not open. */
export function dragJustEnded(now: number = Date.now()): boolean {
  const ended = now - endedAt < 400;
  endedAt = 0;
  return ended;
}

/**
 * Watches a pointer pressed on a row. Past the threshold a card follows the pointer and the drop target under it is
 * marked (`drop-over`); releasing over a target calls `onDrop`, Escape or releasing elsewhere drops nothing.
 */
export function beginRecordingDrag(
  start: { clientX: number; clientY: number; button: number; pointerId?: number },
  drag: () => RecordingDrag | null,
  onDrop: (ids: string[], projectId: string | null) => void,
  doc: Document = document,
): void {
  if (start.button !== 0) return;
  const view = doc.defaultView ?? window;
  let moving: RecordingDrag | null = null;
  let ghost: HTMLElement | null = null;
  let over: HTMLElement | null = null;
  const mark = (target: HTMLElement | null) => {
    if (over === target) return;
    over?.classList.remove("drop-over");
    target?.classList.add("drop-over");
    over = target;
  };
  const finish = () => {
    view.removeEventListener("pointermove", onMove, true);
    view.removeEventListener("pointerup", onUp, true);
    view.removeEventListener("pointercancel", onCancel, true);
    view.removeEventListener("keydown", onKey, true);
    mark(null);
    ghost?.remove();
    doc.body.classList.remove("dragging-recordings");
    if (moving) endedAt = Date.now();
  };
  const onMove = (e: PointerEvent) => {
    // The button was let go where the page did not hear it (over another window): nothing is dropped, ever.
    if (e.buttons === 0) {
      moving = null;
      finish();
      return;
    }
    if (!moving) {
      if (Math.hypot(e.clientX - start.clientX, e.clientY - start.clientY) < DRAG_THRESHOLD_PX) return;
      moving = drag();
      if (!moving || moving.ids.length === 0) {
        moving = null;
        finish();
        return;
      }
      ghost = doc.createElement("div");
      ghost.className = "drag-ghost";
      ghost.setAttribute("aria-hidden", "true");
      ghost.textContent = moving.label;
      doc.body.appendChild(ghost);
      doc.body.classList.add("dragging-recordings");
    }
    e.preventDefault();
    if (ghost) ghost.style.transform = `translate(${e.clientX + 14}px, ${e.clientY + 12}px)`;
    mark(dropTargetAt(e.clientX, e.clientY, doc)?.element ?? null);
  };
  const onUp = (e: PointerEvent) => {
    const target = moving ? dropTargetAt(e.clientX, e.clientY, doc) : null;
    const ids = moving?.ids ?? [];
    finish();
    if (target && ids.length > 0) onDrop(ids, target.projectId);
  };
  const onCancel = () => finish();
  const onKey = (e: KeyboardEvent) => {
    if (e.key !== "Escape" || !moving) return;
    e.preventDefault();
    moving = null;
    finish();
  };
  view.addEventListener("pointermove", onMove, true);
  view.addEventListener("pointerup", onUp, true);
  view.addEventListener("pointercancel", onCancel, true);
  view.addEventListener("keydown", onKey, true);
}
