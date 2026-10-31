// "While anything shows pending / working / extracting, ask again about every 5 s and stop when done": one hook for the
// workspace, the call notes and the brief, so every screen polls the same way (and not behind a hidden window, C9).
import { useEffect, useRef } from "react";
import { useWindowShown } from "./visibility";

export const POLL_MS = 5_000;

/** Calls `tick` every `ms` while `active` is true and the window is on screen; a slow answer delays the next try. */
export function usePoll(active: boolean, tick: () => Promise<unknown>, ms: number = POLL_MS): void {
  const shown = useWindowShown();
  const latest = useRef(tick);
  latest.current = tick;
  useEffect(() => {
    if (!active || !shown) return;
    let alive = true;
    let timer = 0;
    const next = () => {
      timer = window.setTimeout(() => {
        latest.current()
          .catch(() => undefined)
          .finally(() => {
            if (alive) next();
          });
      }, ms);
    };
    next();
    return () => {
      alive = false;
      window.clearTimeout(timer);
    };
  }, [active, shown, ms]);
}
