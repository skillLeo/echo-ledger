// Reports the interactive parts of an overlay window ([data-hit] elements) to Rust in physical pixels, so
// the 30 Hz cursor poll can make everything else click-through. Sent only when layout changes.
import { useEffect } from "react";
import { api, type RectPx } from "./ipc";

export function collectRegions(root: ParentNode, dpr: number): RectPx[] {
  return Array.from(root.querySelectorAll<HTMLElement>("[data-hit]"))
    .map((el) => el.getBoundingClientRect())
    .filter((r) => r.width > 0 && r.height > 0)
    .map((r) => ({ x: r.left * dpr, y: r.top * dpr, w: r.width * dpr, h: r.height * dpr }));
}

export function useHitRegions(deps: unknown[]): void {
  useEffect(() => {
    let last = "";
    const report = () => {
      const regions = collectRegions(document, window.devicePixelRatio || 1);
      const key = JSON.stringify(regions);
      if (key !== last) {
        last = key;
        api.setHitRegions(regions).catch(() => undefined);
      }
    };
    report();
    const observer = new ResizeObserver(report);
    observer.observe(document.body);
    window.addEventListener("resize", report);
    return () => {
      observer.disconnect();
      window.removeEventListener("resize", report);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
}
