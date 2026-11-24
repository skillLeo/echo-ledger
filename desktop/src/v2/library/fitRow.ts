// A toolbar row that never cuts a label (the owner's request, Mac 2.0.15), the title row's rule for the Library's row 2:
// the kinds' tabs on the start side, [Filters] and [Sort & display] on the end. When the row has no room for both whole,
// the end's labels go first (their icons stay, with tooltips and their names); if it still has no room, the tabs scroll
// sideways (no fade: no gradients, DS rule 1). A tab's words never wrap or get cut.
import { useLayoutEffect, useRef, useState, type RefObject } from "react";

/** The least gap between the tabs and the end's controls (--el-space-8). */
const GAP = 8;

/** What the row does at this width (pure): the end's labels shown or not, the tabs scrolling or not. */
export function fitRow(row: number, tabs: number, endLabelled: number, endIcons: number): { labels: boolean; scrolls: boolean } {
  if (tabs + GAP + endLabelled <= row) return { labels: true, scrolls: false };
  return { labels: false, scrolls: tabs + GAP + endIcons > row };
}

/** The row's state, measured as it resizes (in a test there is no layout: labels shown, nothing scrolls). */
export function useFitRow(row: RefObject<HTMLElement | null>, tabs: RefObject<HTMLElement | null>, end: RefObject<HTMLElement | null>, key: unknown) {
  const [fit, setFit] = useState({ labels: true, scrolls: false });
  // The end's widths with its labels and with its icons only, kept from when each was on screen.
  const widths = useRef({ labelled: 0, icons: 0 });
  useLayoutEffect(() => {
    const box = row.current;
    if (!box || typeof ResizeObserver === "undefined") return;
    const measure = () => {
      const t = tabs.current;
      const e = end.current;
      if (!t || !e) return;
      const now = e.getBoundingClientRect().width;
      if (fit.labels) widths.current.labelled = now;
      else widths.current.icons = now;
      // The icons alone: about the end less its labels' words (measured once seen; until then, a third of it).
      const icons = widths.current.icons || widths.current.labelled / 3;
      const next = fitRow(box.clientWidth, t.scrollWidth, widths.current.labelled || now, icons);
      if (next.labels !== fit.labels || next.scrolls !== fit.scrolls) setFit(next);
    };
    const watch = new ResizeObserver(measure);
    watch.observe(box);
    measure();
    return () => watch.disconnect();
  }, [row, tabs, end, key, fit]);
  return fit;
}
