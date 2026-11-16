// A chip with a word and a select ("Project ▾", "Language ▾"): the whole chip is the select (D-121), so a click on its
// word opens it too. The word is drawn over the select's start, which leaves it that much room.
import { useLayoutEffect, useRef } from "react";

/** The chip's ref: it sets --chip-key-w to its word's width (again when the text size changes). */
export function useChipKey<T extends HTMLElement>() {
  const chip = useRef<T | null>(null);
  useLayoutEffect(() => {
    const el = chip.current;
    const key = el?.querySelector<HTMLElement>(".chip-key");
    if (!el || !key) return;
    const measure = () => el.style.setProperty("--chip-key-w", `${Math.ceil(key.getBoundingClientRect().width)}px`);
    measure();
    if (typeof ResizeObserver === "undefined") return;
    const observer = new ResizeObserver(measure);
    observer.observe(key);
    return () => observer.disconnect();
  }, []);
  return chip;
}
