// Back to top (PLAN_V2_1_POLISH UX-25): a 40 px round ↑ at a long list's bottom end corner, 16 px in. It shows once
// the list has been scrolled more than one screen down and fades at the top; a press, Home, ⌘↑ or Ctrl+Home scrolls
// smoothly to the top and puts focus on the first row. One component for every long list (the Library and its Trash,
// a project's recordings, Activity, Shared): put it after the list, inside the box that scrolls.
import { useCallback, useEffect, useRef, useState } from "react";
import { Button } from "./Button";
import { ArrowUpIcon } from "./icons";

/** The box that scrolls `el` (the shell's content area): its nearest ancestor that scrolls up and down. */
export function scrollRootOf(el: Element | null): HTMLElement | null {
  for (let at = el?.parentElement ?? null; at; at = at.parentElement) {
    const y = getComputedStyle(at).overflowY;
    if (y === "auto" || y === "scroll") return at;
  }
  return (document.scrollingElement as HTMLElement | null) ?? null;
}

/** Keys a field, a dialog or a menu keeps for itself. */
const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName) || !!target.closest('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]'));

export type BackToTopProps = {
  /** The first row, focused at the top (within the box that scrolls). */
  first?: string;
};

export function BackToTop({ first = "[data-row-id]" }: BackToTopProps) {
  const ref = useRef<HTMLDivElement>(null);
  const [shown, setShown] = useState(false);
  const scroller = useCallback(() => scrollRootOf(ref.current), []);
  const toTop = useCallback(() => {
    const box = scroller();
    if (!box) return;
    const smooth = !window.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
    if (typeof box.scrollTo === "function") box.scrollTo({ top: 0, behavior: smooth ? "smooth" : "auto" });
    else box.scrollTop = 0;
    box.querySelector<HTMLElement>(first)?.focus({ preventScroll: true });
    setShown(false);
  }, [first, scroller]);
  useEffect(() => {
    const box = scroller();
    if (!box) return;
    const update = () => setShown(box.scrollTop > box.clientHeight);
    update();
    box.addEventListener("scroll", update, { passive: true });
    // Home, ⌘↑ (Mac) and Ctrl+Home (Windows), from the list or nothing focused; never while typing or in a menu.
    const onKey = (e: KeyboardEvent) => {
      const top = (e.key === "Home" && !e.altKey && !e.shiftKey && !e.metaKey) || (e.key === "ArrowUp" && e.metaKey && !e.altKey && !e.shiftKey && !e.ctrlKey);
      if (!top || e.defaultPrevented || typing(e.target)) return;
      const inside = e.target === document.body || (e.target instanceof Node && box.contains(e.target));
      if (!inside || document.querySelector('[aria-modal="true"]')) return;
      e.preventDefault();
      toTop();
    };
    document.addEventListener("keydown", onKey);
    return () => {
      box.removeEventListener("scroll", update);
      document.removeEventListener("keydown", onKey);
    };
  }, [scroller, toTop]);
  return (
    <div className="el-back-top" ref={ref} data-shown={shown ? "true" : "false"}>
      <Button variant="secondary" icon={ArrowUpIcon} className="el-back-top__button" aria-label="Back to top" title="Back to top"
        aria-keyshortcuts="Home Meta+ArrowUp Control+Home" aria-hidden={shown ? undefined : true} tabIndex={shown ? undefined : -1} onClick={toTop} />
    </div>
  );
}
