// A button with a menu under it (v1.6 shell: the workspace switcher, "+", the avatar). The menu closes on a choice, a
// click elsewhere or Esc (focus goes back to its button); ↑ ↓ move between its items, Home / End jump to the ends.
import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { CheckIcon } from "./ControlIcons";

export interface MenuItem {
  id: string;
  label: ReactNode;
  /** The accessible name when the label is more than words. */
  name?: string;
  icon?: ReactNode;
  /** A key that does the same, shown at the end. */
  hint?: string;
  danger?: boolean;
  disabled?: boolean;
  /** A ticked choice (a menuitemradio). */
  checked?: boolean;
  onPick: () => void;
}

export function Menu({ button, label, items, header, footer, align = "right", className = "", buttonClass = "", title }: {
  /** What the button shows. */
  button: ReactNode;
  /** The button's and the menu's accessible name. */
  label: string;
  items: readonly (MenuItem | "separator" | false | null | undefined)[];
  /** Shown above the items (who is signed in, say). */
  header?: ReactNode;
  footer?: ReactNode;
  align?: "left" | "right";
  className?: string;
  buttonClass?: string;
  title?: string;
}) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement | null>(null);
  const buttonRef = useRef<HTMLButtonElement | null>(null);
  const menuId = useId();
  useEffect(() => {
    if (!open) return;
    const outside = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    };
    const keys = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        e.preventDefault();
        e.stopPropagation();
        setOpen(false);
        buttonRef.current?.focus();
        return;
      }
      if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(e.key)) return;
      const all = [...(box.current?.querySelectorAll<HTMLElement>("[role^=menuitem]:not(:disabled)") ?? [])];
      if (all.length === 0) return;
      e.preventDefault();
      const at = all.indexOf(document.activeElement as HTMLElement);
      const next = e.key === "Home" ? 0 : e.key === "End" ? all.length - 1
        : e.key === "ArrowDown" ? (at + 1) % all.length : (at - 1 + all.length) % all.length;
      all[next]?.focus();
    };
    document.addEventListener("mousedown", outside);
    document.addEventListener("keydown", keys, true);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("keydown", keys, true);
    };
  }, [open]);
  const shown = items.filter((i): i is MenuItem | "separator" => !!i);
  return (
    <div className={`menu-button shell-menu ${className}`} ref={box}>
      <button ref={buttonRef} type="button" className={buttonClass} aria-label={label} title={title ?? label} aria-haspopup="menu" aria-expanded={open}
        aria-controls={open ? menuId : undefined} onClick={() => setOpen((o) => !o)}>
        {button}
      </button>
      {open && (
        <div id={menuId} className={`menu-pop card shell-pop ${align === "left" ? "left" : ""}`} role="menu" aria-label={label}>
          {/* A button in the header marked data-menu-close (the avatar menu's "who you are") closes the menu too. */}
          {header && <div className="menu-head" onClick={(e) => { if ((e.target as HTMLElement).closest("[data-menu-close]")) setOpen(false); }}>{header}</div>}
          {shown.map((item, i) => (item === "separator" ? <div key={`sep-${i}`} className="menu-sep" role="separator" /> : (
            <button key={item.id} type="button" role={item.checked === undefined ? "menuitem" : "menuitemradio"} aria-checked={item.checked}
              aria-label={item.name} className={`btn ghost small menu-item ${item.danger ? "danger" : ""}`} disabled={item.disabled}
              onClick={() => { setOpen(false); item.onPick(); }}>
              {item.icon && <span className="menu-icon" aria-hidden="true">{item.icon}</span>}
              <span className="menu-label">{item.label}</span>
              {item.hint && <span className="kbd" aria-hidden="true">{item.hint}</span>}
              {item.checked && <span className="menu-check" aria-hidden="true"><CheckIcon size={14} /></span>}
            </button>
          )))}
          {footer && <div className="menu-foot">{footer}</div>}
        </div>
      )}
    </div>
  );
}
