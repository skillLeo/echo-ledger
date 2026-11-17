import { useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type MutableRefObject, type Ref } from "react";
import { Button, Icon, type ButtonProps, type IconType } from "./Button";
import { ChevronEndIcon } from "./icons";

/**
 * One action in a menu. `group` puts a small header above the first item of each group (Organise, Export, Danger);
 * `items` makes it a sub-menu (Export ▸); `shortcut` shows the key on the end side ("⌘A", "Del").
 */
export type MenuItem = {
  label: string;
  onPick?: () => void;
  danger?: boolean;
  icon?: IconType;
  shortcut?: string;
  group?: string;
  items?: readonly MenuItem[];
  disabled?: boolean;
};

type ListProps = {
  label: string;
  items: readonly MenuItem[];
  id?: string;
  className: string;
  /** The item to focus first. */
  initial: number;
  /** Closes this level only: Esc, or ArrowLeft in a sub-menu (focus goes back to what opened it). */
  onBack: () => void;
  /** Closes the whole menu without giving focus back (Tab moves on). */
  onLeave: () => void;
  /** An item was picked: the whole menu closes, focus goes back to its opener, then the action runs. */
  onPick: (item: MenuItem) => void;
  style?: CSSProperties;
  listRef?: Ref<HTMLUListElement>;
};

/** The gap a menu keeps from the window's edges. */
const EDGE = 8;
const rtl = (el: Element | null) => !!el && getComputedStyle(el).direction === "rtl";

/** The list itself: arrow keys, Home and End move; Enter, Space or ArrowRight opens a sub-menu; Esc closes a level. */
function MenuList({ label, items, id, className, initial, onBack, onLeave, onPick, style, listRef }: ListProps) {
  const [active, setActive] = useState(initial);
  const [sub, setSub] = useState<number | null>(null);
  const own = useRef<HTMLUListElement | null>(null);
  const setRef = (el: HTMLUListElement | null) => {
    own.current = el;
    if (typeof listRef === "function") listRef(el);
    else if (listRef) (listRef as MutableRefObject<HTMLUListElement | null>).current = el;
  };
  // The list stays inside the window. A sub-menu with no room on its end side opens on the start side, and moves up
  // when it would run off the bottom. A button's menu with no room below opens upwards when there is more room above
  // (a ⋮ on the last rows), and a list without sub-menus scrolls inside the room it has.
  const [fit, setFit] = useState<{ flip?: boolean; up?: boolean; shift?: number; room?: number }>({});
  useLayoutEffect(() => {
    const el = own.current;
    if (!el || className.includes("el-menu--context")) return;
    const box = el.getBoundingClientRect();
    if (!box.height) return;
    const bottom = window.innerHeight - EDGE;
    if (className.includes("el-menu--sub")) {
      setFit({
        flip: box.right > window.innerWidth || box.left < 0,
        shift: box.bottom > bottom ? Math.min(box.bottom - bottom, Math.max(0, box.top - EDGE)) : undefined,
      });
      return;
    }
    if (box.bottom <= bottom) return;
    const anchor = el.parentElement?.getBoundingClientRect();
    const above = (anchor?.top ?? box.top) - EDGE;
    const below = bottom - box.top;
    const up = above > below;
    const scrolls = !items.some((i) => i.items);
    setFit({ up, room: scrolls && box.height > (up ? above : below) ? Math.floor(up ? above : below) : undefined });
  }, [className, items]);
  const fitted: CSSProperties = {
    ...style,
    ...(fit.shift ? { transform: `translateY(-${fit.shift}px)` } : {}),
    ...(fit.room ? { maxBlockSize: `${fit.room}px`, overflowY: "auto" } : {}),
  };
  useEffect(() => {
    if (sub !== null) return;
    own.current?.querySelectorAll<HTMLElement>(':scope > li > [role="menuitem"]')[active]?.focus();
  }, [active, sub]);

  const openSub = (i: number) => {
    if (items[i]?.items?.length && !items[i].disabled) setSub(i);
  };
  const choose = (i: number) => {
    const item = items[i];
    if (!item || item.disabled) return;
    if (item.items?.length) openSub(i);
    else onPick(item);
  };
  const keyDown = (e: KeyboardEvent<HTMLUListElement>) => {
    // The nearest list handles a key; a sub-menu's keys never reach the menu around it.
    e.stopPropagation();
    const last = items.length - 1;
    const forward = rtl(own.current) ? "ArrowLeft" : "ArrowRight";
    const backward = rtl(own.current) ? "ArrowRight" : "ArrowLeft";
    if (e.key === "ArrowDown") { e.preventDefault(); setActive((a) => (a >= last ? 0 : a + 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive((a) => (a <= 0 ? last : a - 1)); }
    else if (e.key === "Home") { e.preventDefault(); setActive(0); }
    else if (e.key === "End") { e.preventDefault(); setActive(last); }
    else if (e.key === "Enter" || e.key === " ") { e.preventDefault(); choose(active); }
    else if (e.key === forward) { e.preventDefault(); openSub(active); }
    else if (e.key === backward && className.includes("el-menu--sub")) { e.preventDefault(); onBack(); }
    else if (e.key === "Escape") { e.preventDefault(); onBack(); }
    else if (e.key === "Tab") onLeave();
  };

  return (
    <ul className={`${className}${items.some((i) => i.items) ? " el-menu--has-sub" : ""}`} role="menu" id={id} aria-label={label} ref={setRef}
      onKeyDown={keyDown} style={fitted} data-flip={fit.flip || undefined} data-up={fit.up || undefined}>
      {items.map((item, i) => {
        const header = item.group && item.group !== items[i - 1]?.group ? item.group : null;
        const subOpen = sub === i;
        return [
          header && <li key={`g-${header}`} role="presentation" className="el-menu__group el-caption">{header}</li>,
          <li key={item.label} role="none" className="el-menu__item">
            <div role="menuitem" tabIndex={i === active && sub === null ? 0 : -1} aria-disabled={item.disabled || undefined}
              aria-haspopup={item.items ? "menu" : undefined} aria-expanded={item.items ? subOpen : undefined}
              aria-keyshortcuts={item.shortcut ? keyNames(item.shortcut) : undefined}
              className={`el-menu__option${item.danger ? " el-menu__option--danger" : ""}`}
              data-active={i === active || undefined}
              onMouseEnter={() => { setActive(i); setSub(item.items ? i : null); }}
              onClick={() => (item.items ? setSub(subOpen ? null : i) : choose(i))}>
              {item.icon ? <Icon icon={item.icon} /> : <span className="el-menu__icon-space" aria-hidden="true" />}
              <span className="el-menu__label">{item.label}</span>
              {item.shortcut && <kbd className="el-menu__shortcut el-caption" aria-hidden="true">{item.shortcut}</kbd>}
              {item.items && <Icon icon={ChevronEndIcon} directional />}
            </div>
            {subOpen && item.items && (
              <MenuList label={item.label} items={item.items} className="el-menu el-menu--sub" initial={0}
                onBack={() => { setSub(null); }} onLeave={onLeave} onPick={onPick} />
            )}
          </li>,
        ];
      })}
    </ul>
  );
}

/** "⌘A" → "Meta+A", "Del" → "Delete", for aria-keyshortcuts. */
function keyNames(shortcut: string): string {
  return shortcut.replace("⌘", "Meta+").replace("Ctrl+", "Control+").replace(/^Del$/, "Delete").replace("⇧", "Shift+");
}

/**
 * A button that opens a list of actions (a project's menu, a recording's ⋮). The list is a menu: arrow keys, Home and
 * End move, Enter picks, Esc closes and gives focus back to the button; a press outside closes it.
 */
export function Menu({ label, items, button }: { label: string; items: readonly MenuItem[]; button: Omit<ButtonProps, "onClick" | "children"> & { text?: string } }) {
  const id = useId();
  const [open, setOpen] = useState<false | { at: number }>(false);
  const root = useRef<HTMLDivElement>(null);
  const opener = useRef<HTMLButtonElement>(null);

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
    item.onPick?.();
  };
  // ArrowDown or ArrowUp on the button opens the menu on its first or last item.
  const openerKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    if (e.key !== "ArrowDown" && e.key !== "ArrowUp") return;
    e.preventDefault();
    setOpen({ at: e.key === "ArrowDown" ? 0 : items.length - 1 });
  };
  const { text, ...buttonProps } = button;

  return (
    <div className="el-menu-anchor" ref={root}>
      <Button {...buttonProps} ref={opener} aria-label={text ? undefined : label} aria-haspopup="menu" aria-expanded={!!open} aria-controls={open ? `${id}-menu` : undefined}
        onClick={() => setOpen(open ? false : { at: 0 })} onKeyDown={openerKey}>{text}</Button>
      {open && (
        <MenuList label={label} items={items} id={`${id}-menu`} className="el-menu el-menu--end" initial={open.at}
          onBack={close} onLeave={() => setOpen(false)} onPick={pick} />
      )}
    </div>
  );
}

/**
 * The same menu at the pointer (a right-click on a row) or under a focused row (Shift+F10, the context-menu key).
 * It stays inside the window; Esc, a press outside or a pick closes it, and focus goes back to where it was.
 */
export function ContextMenu({ label, items, at, onClose }: { label: string; items: readonly MenuItem[]; at: { x: number; y: number }; onClose: () => void }) {
  const list = useRef<HTMLUListElement | null>(null);
  const back = useRef<HTMLElement | null>(document.activeElement as HTMLElement | null);
  const [place, setPlace] = useState(at);
  useLayoutEffect(() => {
    const el = list.current;
    if (!el) return;
    const box = el.getBoundingClientRect();
    setPlace({
      x: Math.max(0, Math.min(at.x, window.innerWidth - box.width)),
      y: Math.max(0, Math.min(at.y, window.innerHeight - box.height)),
    });
  }, [at]);
  useEffect(() => {
    const away = (e: PointerEvent) => {
      if (!list.current?.contains(e.target as Node)) onClose();
    };
    document.addEventListener("pointerdown", away);
    return () => document.removeEventListener("pointerdown", away);
  }, [onClose]);
  const close = () => {
    onClose();
    back.current?.focus();
  };
  return (
    <MenuList label={label} items={items} className="el-menu el-menu--context" initial={0} listRef={list}
      style={{ left: place.x, top: place.y }}
      onBack={close} onLeave={onClose} onPick={(item) => { close(); item.onPick?.(); }} />
  );
}

/** Where a context menu opens for a keyboard user: under the start of the focused element. */
export function menuPointFor(el: Element): { x: number; y: number } {
  const box = el.getBoundingClientRect();
  return { x: box.left + box.width / 4, y: box.bottom };
}

