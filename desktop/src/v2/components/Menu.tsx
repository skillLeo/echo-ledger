import { useEffect, useId, useRef, useState, type KeyboardEvent } from "react";
import { Button, type ButtonProps } from "./Button";

export type MenuItem = { label: string; onPick: () => void; danger?: boolean };

/**
 * A button that opens a short list of actions (a project's menu). The list is a menu: arrow keys, Home and End move,
 * Enter picks, Esc closes and gives focus back to the button; a press outside closes it.
 */
export function Menu({ label, items, button }: { label: string; items: readonly MenuItem[]; button: Omit<ButtonProps, "onClick" | "children"> & { text?: string } }) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const root = useRef<HTMLDivElement>(null);
  const opener = useRef<HTMLButtonElement>(null);
  const list = useRef<HTMLUListElement>(null);

  useEffect(() => {
    if (!open) return;
    list.current?.querySelectorAll<HTMLElement>('[role="menuitem"]')[active]?.focus();
  }, [open, active]);
  useEffect(() => {
    if (!open) return;
    const away = (e: PointerEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("pointerdown", away);
    return () => document.removeEventListener("pointerdown", away);
  }, [open]);

  const close = () => {
    setOpen(false);
    opener.current?.focus();
  };
  // A pick closes the menu and gives focus back to its button before the action (a dialog then returns it there).
  const pick = (item: MenuItem) => {
    close();
    item.onPick();
  };
  // ArrowDown or ArrowUp on the button opens the menu on its first or last item.
  const openerKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    if (e.key !== "ArrowDown" && e.key !== "ArrowUp") return;
    e.preventDefault();
    setActive(e.key === "ArrowDown" ? 0 : items.length - 1);
    setOpen(true);
  };
  const keyDown = (e: KeyboardEvent<HTMLUListElement>) => {
    const last = items.length - 1;
    if (e.key === "ArrowDown") { e.preventDefault(); setActive((a) => (a >= last ? 0 : a + 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive((a) => (a <= 0 ? last : a - 1)); }
    else if (e.key === "Home") { e.preventDefault(); setActive(0); }
    else if (e.key === "End") { e.preventDefault(); setActive(last); }
    else if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); close(); }
    else if (e.key === "Tab") setOpen(false);
  };
  const { text, ...buttonProps } = button;

  return (
    <div className="el-menu-anchor" ref={root}>
      <Button {...buttonProps} ref={opener} aria-label={text ? undefined : label} aria-haspopup="menu" aria-expanded={open} aria-controls={open ? `${id}-menu` : undefined}
        onClick={() => { setActive(0); setOpen(!open); }} onKeyDown={openerKey}>{text}</Button>
      {open && (
        <ul className="el-menu el-menu--end" role="menu" id={`${id}-menu`} aria-label={label} ref={list} onKeyDown={keyDown}>
          {items.map((item, i) => (
            <li key={item.label} role="menuitem" tabIndex={i === active ? 0 : -1} className={`el-menu__option${item.danger ? " el-menu__option--danger" : ""}`}
              data-active={i === active || undefined} onMouseEnter={() => setActive(i)}
              onClick={() => pick(item)}
              onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); pick(item); } }}>
              {item.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
