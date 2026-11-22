// The Library header's actions when the needs-action button joins them: where the row has no room for it, the
// least-used actions fold into one quiet More (⋯) menu, Select first, then Sync, then Trash. Import and Record and the
// needs button always stay. The header keeps the lines it has without the needs button (its title and actions on one
// row where they fit, else the actions on one row under the title), measured from the actions as drawn.
import { useLayoutEffect, useRef, useState, type RefObject } from "react";

/** What may fold into More, in the order it folds. */
export const FOLD_ORDER = ["select", "sync", "trash"] as const;
export type Foldable = (typeof FOLD_ORDER)[number];

export type HeaderRoom = {
  /** The header's width. */
  room: number;
  /** What the titles take beside the actions: their flex basis and the header's gap. */
  beside: number;
  /** The gap between two actions. */
  gap: number;
  /** Each action shown without the needs button, as [key, width], in the row's order. */
  base: readonly (readonly [string, number])[];
  /** The needs button's width. */
  needs: number;
  /** The More button's width. */
  more: number;
};

/** The header's lines for a row of actions this wide: 1 beside the titles, 2 on a row of their own, 3 when it wraps. */
export function headerLines(row: number, room: number, beside: number): number {
  return beside + row <= room ? 1 : row <= room ? 2 : 3;
}

/** A row of actions of these widths, with the gaps between them. */
export function rowWidth(widths: readonly number[], gap: number): number {
  return widths.length ? widths.reduce((sum, w) => sum + w, 0) + gap * (widths.length - 1) : 0;
}

/**
 * How many of the foldable actions shown go into More (pure), in [FOLD_ORDER]: the fewest that give the header no more
 * lines than it has without the needs button, with its actions on one row. All of them when even that is too wide.
 */
export function foldCount({ room, beside, gap, base, needs, more }: HeaderRoom): number {
  const foldable = FOLD_ORDER.filter((key) => base.some(([k]) => k === key));
  const without = Math.min(headerLines(rowWidth(base.map(([, w]) => w), gap), room, beside), 2);
  for (let k = 0; k <= foldable.length; k++) {
    const folded = new Set<string>(foldable.slice(0, k));
    const widths = [needs, ...base.filter(([key]) => !folded.has(key)).map(([, w]) => w), ...(k > 0 ? [more] : [])];
    if (headerLines(rowWidth(widths, gap), room, beside) <= without) return k;
  }
  return foldable.length;
}

const px = (value: string) => parseFloat(value) || 0;

/**
 * Which of `shown` (the foldable actions the header has now) fold into More. `keys` names the header's actions in the
 * order they are drawn, as the last render drew them ("needs", "trash", "more", …); every action's width is kept from
 * when it was last drawn, so a folded one still counts at its own width. Off (nothing folds) without the needs button.
 * Measured before the paint, and again when the header's width changes.
 */
export function useHeaderFold(head: RefObject<HTMLElement | null>, keys: RefObject<readonly string[]>, shown: readonly Foldable[], on: boolean, watch = ""): ReadonlySet<Foldable> {
  const [count, setCount] = useState(0);
  const widths = useRef(new Map<string, number>());
  const present = FOLD_ORDER.filter((key) => shown.includes(key));
  const presentKey = present.join();
  useLayoutEffect(() => {
    const el = head.current;
    if (!on || !el) {
      setCount(0);
      return;
    }
    const fit = () => {
      const actions = el.querySelector<HTMLElement>(":scope > .el-page__actions");
      const titles = el.querySelector<HTMLElement>(":scope > .el-page__titles");
      if (!actions || !titles) return;
      const drawn = keys.current ?? [];
      let height = 0;
      [...actions.children].forEach((child, i) => {
        const box = child.getBoundingClientRect();
        if (drawn[i] && box.width > 0) widths.current.set(drawn[i], box.width);
        height = Math.max(height, box.height);
      });
      // A newly shown action folded before it was ever drawn: everything unfolds once, to be measured.
      if (present.some((key) => !widths.current.has(key))) {
        setCount(0);
        return;
      }
      const width = (key: string) => widths.current.get(key) ?? 0;
      // Every action but the needs button and More, drawn or folded.
      const others = drawn.filter((key) => key !== "needs" && key !== "more" && !present.includes(key as Foldable));
      const base = [...present, ...others].map((key) => [key, width(key)] as const);
      setCount(foldCount({
        room: el.clientWidth + 0.5,
        beside: px(getComputedStyle(titles).flexBasis) + px(getComputedStyle(el).columnGap),
        gap: px(getComputedStyle(actions).columnGap),
        base,
        needs: width("needs"),
        // An icon button is as wide as it is tall.
        more: widths.current.get("more") ?? height,
      }));
    };
    fit();
    if (typeof ResizeObserver === "function") {
      // Only a new width counts: folding changes the header's height, and that alone must not measure again.
      let last = el.clientWidth;
      const ro = new ResizeObserver(() => {
        if (el.clientWidth === last) return;
        last = el.clientWidth;
        fit();
      });
      ro.observe(el);
      return () => ro.disconnect();
    }
    window.addEventListener("resize", fit);
    return () => window.removeEventListener("resize", fit);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [on, presentKey, count, watch]);
  return new Set(on ? present.slice(0, count) : []);
}
