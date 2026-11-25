// The rail's keyboard (FIX-06): one Tab stop for the whole rail (roving tabindex), and ↑ ↓ Home End to go from item to
// item: Record, its menu, the places (⋯ More), Setup, Help, Settings and the avatar. The stop is the item last gone to,
// else the page's own (aria-current), else Record. ↑ ↓ never open a menu here (a menu's button opens with Enter or Space).
import { useLayoutEffect, useRef, type FocusEvent, type KeyboardEvent, type RefObject } from "react";

/** The rail's items a person can go to, in order. */
export function railStops(rail: HTMLElement | null): HTMLButtonElement[] {
  if (!rail) return [];
  return [...rail.querySelectorAll<HTMLButtonElement>("button")].filter((b) => !b.disabled && !b.closest("[hidden], [aria-hidden='true']"));
}

export function useRailKeys(rail: RefObject<HTMLElement | null>) {
  const active = useRef<HTMLElement | null>(null);
  const apply = () => {
    const stops = railStops(rail.current);
    const chosen = (active.current && stops.includes(active.current as HTMLButtonElement) ? active.current : null)
      ?? stops.find((b) => b.getAttribute("aria-current") === "page") ?? stops[0];
    for (const b of stops) b.tabIndex = b === chosen ? 0 : -1;
  };
  // After every render: items come and go (More, Setup and Help in a short window, the avatar once the account is read).
  useLayoutEffect(apply);
  const onFocus = (e: FocusEvent<HTMLElement>) => {
    if (!railStops(rail.current).includes(e.target as HTMLButtonElement)) return;
    active.current = e.target as HTMLElement;
    apply();
  };
  const onKeyDownCapture = (e: KeyboardEvent<HTMLElement>) => {
    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || !["ArrowDown", "ArrowUp", "Home", "End"].includes(e.key)) return;
    const stops = railStops(rail.current);
    const at = stops.indexOf(e.target as HTMLButtonElement);
    if (at < 0) return;
    // Before a menu's button hears it (its ↓ would open the menu).
    e.preventDefault();
    e.stopPropagation();
    const next = e.key === "Home" ? 0 : e.key === "End" ? stops.length - 1 : Math.max(0, Math.min(stops.length - 1, at + (e.key === "ArrowDown" ? 1 : -1)));
    active.current = stops[next];
    apply();
    stops[next].focus();
  };
  return { onFocus, onKeyDownCapture };
}
