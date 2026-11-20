// A bar that sticks over what scrolls behind it (a Library group header, the recording's player) gives its height to
// the window as a CSS variable, measured as drawn (it grows with the text size and wraps in a narrow window). The
// scroll areas use it as their scroll padding (styles.css), so what the keyboard, a link or Follow brings into view
// stops beside the bar, never under it.
import { useLayoutEffect, type RefObject } from "react";

/** How many mounted bars give each variable: the last one to go removes it. */
const givers = new Map<string, Set<HTMLElement>>();

function write(name: string) {
  const root = document.documentElement;
  const bars = givers.get(name);
  if (!bars || bars.size === 0) {
    root.style.removeProperty(name);
    return;
  }
  const tallest = Math.max(...[...bars].map((el) => el.getBoundingClientRect().height));
  root.style.setProperty(name, `${Math.ceil(tallest)}px`);
}

/** Keeps `name` (e.g. "--player-dock-h") set to this element's drawn height while it is mounted. */
export function useStickyHeight(ref: RefObject<HTMLElement | null>, name: string): void {
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const bars = givers.get(name) ?? new Set<HTMLElement>();
    givers.set(name, bars);
    bars.add(el);
    write(name);
    const observer = typeof ResizeObserver === "undefined" ? null : new ResizeObserver(() => write(name));
    observer?.observe(el);
    return () => {
      observer?.disconnect();
      bars.delete(el);
      write(name);
    };
  }, [ref, name]);
}
