// A toolbar row that never cuts a label (the owner's request, Mac 2.0.15), the title row's rule for the Library's row 2:
// the kinds' tabs on the start side, [Filters] and [Sort & display] on the end. When the row has no room for both whole,
// the end's labels go first (their icons stay, with tooltips and their names); if it still has no room, the tabs scroll
// sideways (no fade: no gradients, DS rule 1). A tab's words never wrap or get cut.
import { useEffect, useLayoutEffect, useRef, useState, type RefObject } from "react";

/** The least gap between the tabs and the end's controls (--el-space-8). */
const GAP = 8;

/** What the row does at this width (pure): the end's labels shown or not, the tabs scrolling or not. */
export function fitRow(row: number, tabs: number, endLabelled: number, endIcons: number): { labels: boolean; scrolls: boolean } {
  if (tabs + GAP + endLabelled <= row) return { labels: true, scrolls: false };
  return { labels: false, scrolls: tabs + GAP + endIcons > row };
}

/**
 * The row's state, measured whenever any of its parts changes size: the row (the window), each tab (its count's words:
 * 9 → 10 widens it with no resize of the row), and the end (its labels, the Filters count). In a test with no
 * ResizeObserver there is no layout: labels shown, nothing scrolls.
 */
export function useFitRow(row: RefObject<HTMLElement | null>, tabs: RefObject<HTMLElement | null>, end: RefObject<HTMLElement | null>) {
  const [fit, setFit] = useState({ labels: true, scrolls: false });
  const shown = useRef(fit);
  shown.current = fit;
  // The end's widths with its labels and with its icons only, kept from when each was on screen.
  const widths = useRef({ labelled: 0, icons: 0 });
  const measure = useRef(() => {});
  measure.current = () => {
    const box = row.current;
    const t = tabs.current;
    const e = end.current;
    if (!box || !t || !e) return;
    const now = e.getBoundingClientRect().width;
    if (shown.current.labels) widths.current.labelled = now;
    else {
      // The icons changed (the Filters count came or went): the labelled end changed by as much.
      if (widths.current.icons && widths.current.labelled) widths.current.labelled += now - widths.current.icons;
      widths.current.icons = now;
    }
    // The icons alone: about the end less its labels' words (measured once seen; until then, a third of it).
    const icons = widths.current.icons || widths.current.labelled / 3;
    const next = fitRow(box.clientWidth, t.scrollWidth, widths.current.labelled || now, icons);
    if (next.labels !== shown.current.labels || next.scrolls !== shown.current.scrolls) setFit(next);
  };
  // What is watched: checked after every render, since the row is put back after a selection (new elements) and a
  // tab comes and goes (Other). Only a change of elements watches again; the measuring is the observer's.
  const watched = useRef<{ observer: ResizeObserver; elements: Element[] } | null>(null);
  useLayoutEffect(() => {
    if (typeof ResizeObserver === "undefined") return;
    const elements = [row.current, end.current, ...Array.from(tabs.current?.children ?? [])].filter((el): el is Element => !!el);
    const before = watched.current?.elements;
    if (before && before.length === elements.length && before.every((el, i) => el === elements[i])) return;
    watched.current?.observer.disconnect();
    const observer = new ResizeObserver(() => measure.current());
    for (const el of elements) observer.observe(el);
    watched.current = { observer, elements };
  });
  useEffect(() => () => watched.current?.observer.disconnect(), []);
  return fit;
}
