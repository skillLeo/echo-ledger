// Zoom, like Chrome (UX-07, v21_zoom): the title row's chip while the zoom isn't 100% ("⌕ 110%"; its menu steps it and
// resets it, as Chrome's address bar does), and the bubble each change shows near the top for 2 s ("110% · Reset"; a
// new change replaces it, there are never two).
import { useEffect, useState } from "react";
import { Icons } from "../components";
import { Menu } from "../components/Menu";
import { ZOOM_DEFAULT, ZOOM_EVENT, ZOOM_STEPS, useZoom, zoomBy } from "../../lib/zoom";

/** How long the bubble stays. */
export const BUBBLE_MS = 2000;

/** The stepping row of a zoom menu (the chip's and the avatar's): [−] 110% [+]. */
export function zoomStepper(level: number) {
  return {
    value: `${level}%`,
    down: { label: "Zoom out", onPick: () => void zoomBy(-1), disabled: level <= ZOOM_STEPS[0] },
    up: { label: "Zoom in", onPick: () => void zoomBy(1), disabled: level >= ZOOM_STEPS[ZOOM_STEPS.length - 1] },
  };
}

export function ZoomChip() {
  const level = useZoom();
  if (level === ZOOM_DEFAULT) return null;
  return (
    <Menu label={`Zoom, ${level}%`} align="end"
      items={[{ label: "Zoom", stepper: zoomStepper(level) }, { label: "Reset to 100%", onPick: () => void zoomBy(0) }]}
      button={{ variant: "quiet", size: "sm", icon: Icons.ZoomIcon, text: `${level}%`, className: "el-zoom-chip", title: `Zoom ${level}%`, "aria-label": `Zoom, ${level}%` }} />
  );
}

export function ZoomBubble() {
  const [shown, setShown] = useState<number | null>(null);
  useEffect(() => {
    let timer = 0;
    const changed = (e: Event) => {
      setShown((e as CustomEvent<number>).detail);
      window.clearTimeout(timer);
      timer = window.setTimeout(() => setShown(null), BUBBLE_MS);
    };
    window.addEventListener(ZOOM_EVENT, changed);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener(ZOOM_EVENT, changed);
    };
  }, []);
  return (
    <div className="el-zoom-bubble" role="status" aria-live="polite" data-shown={shown !== null || undefined}>
      {shown !== null && (
        <>
          <span className="el-num">{shown}%</span>
          {shown !== ZOOM_DEFAULT && <>{" · "}<button type="button" className="el-zoom-bubble__reset" onClick={() => void zoomBy(0)}>Reset</button></>}
        </>
      )}
    </div>
  );
}
