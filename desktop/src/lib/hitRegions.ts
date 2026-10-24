// Reports the interactive parts of an overlay window ([data-hit] elements) to Rust in physical pixels, so
// the 30 Hz cursor poll can make everything else click-through.
//
// The overlay is bottom-anchored, so its buttons MOVE whenever the caption text wraps onto another line. Regions are
// therefore re-measured on every DOM change (throttled to one measurement per frame) plus a slow safety timer, and
// each region is padded a few pixels so buttons are easy to hit. A stale region means clicks pass straight through.
import { useEffect } from "react";
import { api, type RectPx } from "./ipc";

/** Extra hit area around each interactive element, in CSS pixels. */
export const HIT_PADDING = 6;

export function collectRegions(root: ParentNode, dpr: number, pad = 0): RectPx[] {
  return Array.from(root.querySelectorAll<HTMLElement>("[data-hit]"))
    .map((el) => el.getBoundingClientRect())
    .filter((r) => r.width > 0 && r.height > 0)
    .map((r) => ({ x: (r.left - pad) * dpr, y: (r.top - pad) * dpr, w: (r.width + 2 * pad) * dpr, h: (r.height + 2 * pad) * dpr }));
}

export function useHitRegions(deps: unknown[]): void {
  useEffect(() => {
    let last = "";
    let frame = 0;
    const report = () => {
      const regions = collectRegions(document, window.devicePixelRatio || 1, HIT_PADDING);
      const key = JSON.stringify(regions);
      if (key !== last) {
        last = key;
        api.setHitRegions(regions).catch(() => undefined);
      }
    };
    const schedule = () => {
      if (frame) return;
      frame = window.requestAnimationFrame(() => {
        frame = 0;
        report();
      });
    };
    report();
    const resize = new ResizeObserver(schedule);
    resize.observe(document.body);
    const mutations = new MutationObserver(schedule);
    mutations.observe(document.body, { subtree: true, childList: true, characterData: true, attributes: true });
    window.addEventListener("resize", schedule);
    const safetyTimer = window.setInterval(report, 750);
    return () => {
      resize.disconnect();
      mutations.disconnect();
      window.removeEventListener("resize", schedule);
      window.clearInterval(safetyTimer);
      if (frame) window.cancelAnimationFrame(frame);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
}
