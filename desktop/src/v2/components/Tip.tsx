// A tooltip (DES-01): after 500 ms of hover, or at once on keyboard focus, beside its control (the end side), above
// everything; Esc, leaving, a press or blur hide it. The control keeps its own accessible name and shortcut
// (aria-label, aria-keyshortcuts): the tooltip shows them, it is not how they are said.
import { useEffect, useRef, useState, type FocusEvent, type PointerEvent, type ReactNode } from "react";
import { Layer } from "./Menu";

export const TIP_DELAY_MS = 500;
/** The gap between a control and its tooltip. */
const GAP = 8;

type TipProps = {
  onPointerEnter: (e: PointerEvent<HTMLElement>) => void;
  onPointerLeave: () => void;
  onPointerDown: () => void;
  onFocus: (e: FocusEvent<HTMLElement>) => void;
  onBlur: () => void;
};

const keyboardFocus = (el: HTMLElement) => {
  try {
    return el.matches(":focus-visible");
  } catch {
    return true;
  }
};

/** The props for the control, and the tooltip to render beside it (null while hidden). */
export function useTip(text: string): [TipProps, ReactNode] {
  const [at, setAt] = useState<{ el: HTMLElement; box: DOMRect } | null>(null);
  const timer = useRef(0);
  const show = (el: HTMLElement) => setAt({ el, box: el.getBoundingClientRect() });
  const hide = () => {
    window.clearTimeout(timer.current);
    setAt(null);
  };
  useEffect(() => () => window.clearTimeout(timer.current), []);
  useEffect(() => {
    if (!at) return;
    // Esc hides it and nothing else changes (the key still reaches what else listens: a flyout closes too).
    const esc = (e: KeyboardEvent) => {
      if (e.key === "Escape") setAt(null);
    };
    document.addEventListener("keydown", esc, true);
    return () => document.removeEventListener("keydown", esc, true);
  }, [at]);
  const props: TipProps = {
    onPointerEnter: (e) => {
      const el = e.currentTarget;
      window.clearTimeout(timer.current);
      timer.current = window.setTimeout(() => show(el), TIP_DELAY_MS);
    },
    onPointerLeave: hide,
    onPointerDown: hide,
    onFocus: (e) => {
      if (keyboardFocus(e.currentTarget)) show(e.currentTarget);
    },
    onBlur: hide,
  };
  let tip: ReactNode = null;
  if (at) {
    const rtl = getComputedStyle(at.el).direction === "rtl";
    const top = at.box.top + at.box.height / 2;
    const style = rtl ? { top, right: window.innerWidth - at.box.left + GAP } : { top, left: at.box.right + GAP };
    tip = <Layer from={at.el}><span role="tooltip" className="el-tip" style={style}>{text}</span></Layer>;
  }
  return [props, tip];
}
