// A ⋯ button with a small menu of actions (a project's menu, since v1.5). It closes on a pick, a click outside or
// Escape, like the Library row menu.
import { useEffect, useRef, useState } from "react";
import { DotsIcon } from "../../components/Icons";

export interface MenuItem {
  label: string;
  onPick: () => void;
  danger?: boolean;
}

export function MenuButton({ label, items, className = "" }: { label: string; items: readonly (MenuItem | null | false)[]; className?: string }) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    if (!open) return;
    const outside = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    };
    const escape = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", outside);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);
  const shown = items.filter((i): i is MenuItem => !!i);
  return (
    <div className={`menu-button ${className}`.trim()} ref={box}>
      <button type="button" className="btn ghost icon-only" aria-label={label} title={label} aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen(!open)}>
        <DotsIcon size={18} />
      </button>
      {open && (
        <div className="menu-pop card" role="menu" aria-label={label}>
          {shown.map((item) => (
            <button key={item.label} type="button" role="menuitem" className={`btn ghost small ${item.danger ? "danger" : ""}`.trim()}
              onClick={() => { setOpen(false); item.onPick(); }}>{item.label}</button>
          ))}
        </div>
      )}
    </div>
  );
}
