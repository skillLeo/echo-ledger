import { useEffect, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from "react";
import { Icon } from "./Button";
import { LabelDot } from "./Label";
import { menuPointFor } from "./Menu";
import { CheckIcon, PlusIcon } from "./icons";

export type ListChipProps = {
  /** The list's name, or a fixed chip's word (All, Unread, Important). */
  name: string;
  /** The label's stored colour (#rrggbb), or null when it has none. Fixed chips have no colour. */
  colour?: string | null;
  /** The list being shown: one chip in the row is selected at a time. */
  selected: boolean;
  onClick: () => void;
  /**
   * The list's own menu (Rename…, Change colour…, Move, Delete list…), opened by a right-click at the pointer or by
   * Shift+F10 and the context-menu key under the chip. Fixed chips have no menu.
   */
  onMenu?: (at: { x: number; y: number }) => void;
  /** All, Unread and Important: no dot, no menu; they can't be renamed, recoloured, moved or deleted. */
  fixed?: boolean;
  /** How many are unread, on the Unread chip only, and only when above 0. A list chip never shows a count. */
  count?: number;
};

/**
 * One chip of the Library's list row: the label's colour dot and its name, or a fixed chip's word alone. Selected, it
 * takes the accent-soft fill and accent text with a check, as a pressed filter chip does. It is a toggle button
 * (aria-pressed); its menu opens on a right-click or from the keyboard.
 */
export function ListChip({ name, colour = null, selected, onClick, onMenu, fixed = false, count }: ListChipProps) {
  const menu = fixed ? undefined : onMenu;
  const showCount = fixed && count !== undefined && count > 0;
  const context = menu ? (e: MouseEvent<HTMLButtonElement>) => { e.preventDefault(); menu({ x: e.clientX, y: e.clientY }); } : undefined;
  const key = menu ? (e: KeyboardEvent<HTMLButtonElement>) => {
    if (e.key === "ContextMenu" || (e.shiftKey && e.key === "F10")) {
      e.preventDefault();
      menu(menuPointFor(e.currentTarget));
    }
  } : undefined;
  return (
    <button type="button" className={`el-chip el-listchip${fixed ? " el-listchip--fixed" : ""}`} aria-pressed={selected} onClick={onClick}
      onContextMenu={context} onKeyDown={key} aria-keyshortcuts={menu ? "Shift+F10" : undefined}>
      {selected && <Icon icon={CheckIcon} />}
      {!fixed && <LabelDot name={name} colour={colour} decorative />}
      <span className="el-listchip__name" dir="auto">{name}</span>
      {showCount && <>{" "}<span className="el-chip__count el-num">{count}</span></>}
    </button>
  );
}

/**
 * The window width from which the list row wraps onto more lines instead of scrolling sideways (D14 §1). There is no
 * window breakpoint at this width in tokens.css, so the row reads the window here rather than in a media query.
 */
export const LIST_ROW_WRAP_FROM = 1280;

/** Whether the window is at least `width` CSS pixels wide, kept up to date as it is resized. */
function useWindowAtLeast(width: number): boolean {
  const read = () => typeof window !== "undefined" && window.innerWidth >= width;
  const [on, setOn] = useState(read);
  useEffect(() => {
    const update = () => setOn(read());
    update();
    window.addEventListener("resize", update);
    return () => window.removeEventListener("resize", update);
    // `read` only closes over `width`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [width]);
  return on;
}

const rtl = (el: Element) => {
  const computed = typeof getComputedStyle === "function" ? getComputedStyle(el).direction : "";
  if (computed) return computed === "rtl";
  return el.closest('[dir="rtl"], [dir="ltr"]')?.getAttribute("dir") === "rtl";
};

export type ListChipRowProps = {
  /** The row's name for assistive tools ("Lists"). */
  label: string;
  /** The chips: All, Unread, Important, then the person's lists in the order they were made. */
  children: ReactNode;
  /** "+": make a new list. */
  onAdd: () => void;
  /** The "+" button's name and tooltip ("New list"). */
  addLabel: string;
};

/**
 * The Library's list chips in one row, with "+" always last (at the left end right to left). Below 1280 px the row
 * scrolls sideways, with no wrapping and no fade; from 1280 px it wraps onto a second line when it needs one. The
 * selected chip is scrolled into view whenever the selection changes. A vertical wheel scrolls the row sideways while
 * it can move.
 */
export function ListChipRow({ label, children, onAdd, addLabel }: ListChipRowProps) {
  const wrap = useWindowAtLeast(LIST_ROW_WRAP_FROM);
  const scroller = useRef<HTMLDivElement>(null);
  const shown = useRef<Element | null>(null);

  // After every render: when another chip is now the selected one, bring it into the row's view (the row alone moves,
  // never the page).
  useEffect(() => {
    const row = scroller.current;
    const chip = row?.querySelector('[aria-pressed="true"]') ?? null;
    if (!row || !chip || chip === shown.current) return;
    shown.current = chip;
    if (wrap) return;
    const box = chip.getBoundingClientRect();
    const port = row.getBoundingClientRect();
    if (box.left < port.left) row.scrollLeft -= port.left - box.left;
    else if (box.right > port.right) row.scrollLeft += box.right - port.right;
  });

  useEffect(() => {
    const row = scroller.current;
    if (!row || wrap) return;
    const wheel = (e: WheelEvent) => {
      if (Math.abs(e.deltaY) <= Math.abs(e.deltaX) || row.scrollWidth <= row.clientWidth) return;
      const step = rtl(row) ? -e.deltaY : e.deltaY;
      const before = row.scrollLeft;
      row.scrollLeft += step;
      if (row.scrollLeft !== before) e.preventDefault();
    };
    row.addEventListener("wheel", wheel, { passive: false });
    return () => row.removeEventListener("wheel", wheel);
  }, [wrap]);

  return (
    <div className={`el-listchips${wrap ? " el-listchips--wrap" : ""}`} role="group" aria-label={label}>
      <div className="el-listchips__scroll" ref={scroller}>{children}</div>
      <button type="button" className="el-chip el-listchips__add" aria-label={addLabel} title={addLabel} onClick={onAdd}>
        <Icon icon={PlusIcon} />
      </button>
    </div>
  );
}
