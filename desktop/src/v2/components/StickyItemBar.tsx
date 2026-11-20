import { useEffect, useLayoutEffect, useRef, useState, type RefObject } from "react";
import { Button } from "./Button";
import { Menu, type MenuItem } from "./Menu";
import { ChevronStartIcon, MoreIcon } from "./icons";

/** The CSS variable the bar's drawn height is published as, for the sticky layers under it and scroll padding. */
export const ITEM_BAR_HEIGHT = "--item-bar-h";

/** The top bar's drawn height (--topbar-h on the document root, published by the shell), in pixels; 0 when unset. */
function topbarHeight(): number {
  if (typeof getComputedStyle !== "function") return 0;
  const n = parseFloat(getComputedStyle(document.documentElement).getPropertyValue("--topbar-h"));
  return Number.isFinite(n) && n > 0 ? n : 0;
}

/** The nearest ancestor that scrolls up and down (the page's own scroller), or null for the window. */
function scroller(el: Element): Element | null {
  for (let p = el.parentElement; p && p !== document.body && p !== document.documentElement; p = p.parentElement) {
    const o = getComputedStyle(p).overflowY;
    if (o === "auto" || o === "scroll") return p;
  }
  return null;
}

/**
 * Whether the item's own header has scrolled out (D5 3.3): watched with an IntersectionObserver on the sentinel inside
 * the page's scroller. Where the top bar covers the top of that scroller (a scroller that starts under the window's
 * top, the top bar sticky inside it), its height is taken off the top: the header is out of sight once it is under
 * the top bar. With no sentinel, or where IntersectionObserver is missing, the bar always shows.
 */
function useScrolledPast(sentinel?: RefObject<Element | null>): boolean {
  const watchable = !!sentinel && typeof IntersectionObserver === "function";
  const [past, setPast] = useState(!watchable);
  useEffect(() => {
    const el = sentinel?.current;
    if (!watchable || !el) {
      setPast(true);
      return;
    }
    const root = scroller(el);
    const top = Math.max(0, topbarHeight() - (root ? root.getBoundingClientRect().top : 0));
    const io = new IntersectionObserver(([entry]) => {
      if (!entry) return;
      // Out of sight above the view, not still below it (a page that hasn't reached the header yet).
      const viewTop = entry.rootBounds?.top ?? (root ? root.getBoundingClientRect().top : 0) + top;
      setPast(!entry.isIntersecting && entry.boundingClientRect.top < viewTop);
    }, { root, rootMargin: `${-top}px 0% 0% 0%` });
    io.observe(el);
    return () => io.disconnect();
  }, [sentinel, watchable]);
  return past;
}

/** Publishes an element's drawn height on the document root while mounted (0 while it is hidden), and clears it after. */
function usePublishedHeight(ref: RefObject<HTMLElement | null>, name: string, shown: boolean) {
  useLayoutEffect(() => {
    const root = document.documentElement;
    const el = ref.current;
    const publish = () => {
      const h = shown && el ? el.getBoundingClientRect().height : 0;
      root.style.setProperty(name, `${Math.round(h)}px`);
    };
    publish();
    if (!el || !shown) return;
    // The height grows with the text size (200 % text wraps nothing here, but the line is taller).
    if (typeof ResizeObserver === "function") {
      const ro = new ResizeObserver(publish);
      ro.observe(el);
      return () => ro.disconnect();
    }
    window.addEventListener("resize", publish);
    return () => window.removeEventListener("resize", publish);
  }, [ref, name, shown]);
  useLayoutEffect(() => () => { document.documentElement.style.removeProperty(name); }, [name]);
}

export type StickyItemBarProps = {
  /** The item's title, on one line with an ellipsis; its own direction (an Urdu title in an English window). */
  title: string;
  onBack: () => void;
  backLabel?: string;
  /** The item's header (or a mark at its end): the bar shows once it has scrolled out. Without one, the bar always shows. */
  sentinel?: RefObject<Element | null>;
  /** The item's ⋮ menu, the same items as the one in its header. */
  menu?: { label: string; items: readonly MenuItem[] | (() => readonly MenuItem[]) };
};

/**
 * The compact bar an item page (a recording, a project) keeps under the top bar once its own header has scrolled off:
 * Back, the title and the ⋮ menu (D5 3.3). It is solid surface with a hairline under it and no shadow, on the sticky
 * layer, and takes no room in the page (nothing below it moves when it appears). Its drawn height is published as
 * --item-bar-h, so the layers under it and the scroll padding stack on it. It sticks at --item-bar-top, which falls
 * back to the top bar's height (--topbar-h) and then to 0.
 */
export function StickyItemBar({ title, onBack, backLabel = "Back", sentinel, menu }: StickyItemBarProps) {
  const shown = useScrolledPast(sentinel);
  const bar = useRef<HTMLDivElement>(null);
  usePublishedHeight(bar, ITEM_BAR_HEIGHT, shown);
  return (
    <div className="el-itembar">
      <div className="el-itembar__bar" ref={bar} hidden={!shown} data-shown={shown || undefined}>
        <Button variant="quiet" size="sm" icon={ChevronStartIcon} directional aria-label={backLabel} title={backLabel} onClick={onBack} />
        <span className="el-itembar__title el-body-strong" dir="auto" title={title}>{title}</span>
        {menu && <Menu label={menu.label} items={menu.items} button={{ variant: "quiet", size: "sm", icon: MoreIcon }} />}
      </div>
    </div>
  );
}
