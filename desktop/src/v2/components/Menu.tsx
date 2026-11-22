import { Fragment, useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type MutableRefObject, type ReactNode, type Ref } from "react";
import { Button, Icon, type ButtonProps, type IconType } from "./Button";
import { CheckIcon, ChevronDownIcon, ChevronEndIcon } from "./icons";

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
  /** A filter's tick (menuitemcheckbox) or one choice of several (menuitemradio, with `radio`). */
  checked?: boolean;
  radio?: boolean;
  /** Picking it leaves the menu open (ticking several filters in a row). */
  keepOpen?: boolean;
  /** A number on the end side (how many recordings a filter value has). */
  count?: number;
  /** A hairline above this item, setting it and those after it apart without a header (Delete list…, New list…). */
  separator?: boolean;
};

const ITEM_ROLES = '[role="menuitem"], [role="menuitemcheckbox"], [role="menuitemradio"]';

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
  /** A sub-menu shown in place under its item (its parent scrolls, so a flyout would be cut off). */
  inline?: boolean;
};

/** The gap a menu keeps from the window's edges. */
const EDGE = 8;
const rtl = (el: Element | null) => !!el && getComputedStyle(el).direction === "rtl";

/**
 * The list itself: arrow keys, Home and End move; Enter, Space or ArrowRight opens a sub-menu; Esc closes a level.
 * Exported for openers of their own (SplitButton); a page uses Menu or ContextMenu.
 */
export function MenuList({ label, items, id, className, initial, onBack, onLeave, onPick, style, listRef, inline = false }: ListProps) {
  const [active, setActive] = useState(initial);
  const [sub, setSub] = useState<number | null>(null);
  const own = useRef<HTMLUListElement | null>(null);
  const setRef = (el: HTMLUListElement | null) => {
    own.current = el;
    if (typeof listRef === "function") listRef(el);
    else if (listRef) (listRef as MutableRefObject<HTMLUListElement | null>).current = el;
  };
  // The list stays inside the window, measured once where it first opens (never again from a moved spot, so it can't
  // flicker). A sub-menu with no room on its end side opens on the start side, and moves up when it would run off the
  // bottom. A button's menu with no room below opens upwards when there is more room above (a ⋮ on the last rows). A
  // list taller than its room scrolls inside it; its sub-menus then open in place under their item.
  const [fit, setFit] = useState<{ flip?: boolean; up?: boolean; shift?: number; room?: number }>({});
  useLayoutEffect(() => {
    const el = own.current;
    if (!el || inline) return;
    const box = el.getBoundingClientRect();
    if (!box.height) return;
    const bottom = window.innerHeight - EDGE;
    if (className.includes("el-menu--sub")) {
      // Moved up as far as the top edge allows; still taller than the window, it scrolls.
      const room = window.innerHeight - 2 * EDGE;
      setFit({
        flip: box.right > window.innerWidth || box.left < 0,
        shift: box.bottom > bottom ? Math.min(box.bottom - bottom, Math.max(0, box.top - EDGE)) : undefined,
        room: box.height > room ? room : undefined,
      });
      return;
    }
    if (className.includes("el-menu--context")) {
      const room = window.innerHeight - 2 * EDGE;
      if (box.height > room) setFit({ room });
      return;
    }
    if (box.bottom <= bottom) return;
    const anchor = el.parentElement?.getBoundingClientRect();
    const above = (anchor?.top ?? box.top) - EDGE;
    const below = bottom - box.top;
    const up = above > below;
    const room = Math.floor(up ? above : below);
    setFit({ up, room: box.height > room ? room : undefined });
    // Measured once, when the list opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  const fitted: CSSProperties = {
    ...style,
    ...(fit.shift ? { transform: `translateY(-${fit.shift}px)` } : {}),
    ...(fit.room ? { maxBlockSize: `${fit.room}px`, overflowY: "auto" } : {}),
  };
  useEffect(() => {
    if (sub !== null) return;
    own.current?.querySelectorAll<HTMLElement>(`:scope > li > :is(${ITEM_ROLES}), :scope > li > [role="group"] > li > :is(${ITEM_ROLES})`)[active]?.focus();
  }, [active, sub]);

  const openSub = (i: number) => {
    if (items[i]?.items?.length && !items[i].disabled) setSub(i);
  };
  const choose = (i: number) => {
    const item = items[i];
    if (!item || item.disabled) return;
    if (item.items?.length) openSub(i);
    else if (item.keepOpen) item.onPick?.();
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

  // When some items carry a tick, the others keep its column empty so every label starts in the same place.
  const ticks = items.some((it) => it.checked !== undefined);
  const row = (item: MenuItem, i: number) => {
    const subOpen = sub === i;
    return (
      <Fragment key={`${item.group ?? ""}:${item.label}`}>
      {item.separator && i > 0 && <li role="separator" className="el-menu__separator" />}
      <li role="none" className="el-menu__item">
        <div role={item.checked === undefined ? "menuitem" : item.radio ? "menuitemradio" : "menuitemcheckbox"} aria-checked={item.checked} tabIndex={i === active && sub === null ? 0 : -1} aria-disabled={item.disabled || undefined}
          aria-haspopup={item.items ? "menu" : undefined} aria-expanded={item.items ? subOpen : undefined}
          aria-keyshortcuts={item.shortcut ? keyNames(item.shortcut) : undefined}
          className={`el-menu__option${item.danger ? " el-menu__option--danger" : ""}`}
          data-active={i === active || undefined}
          onMouseEnter={() => { setActive(i); if (!fit.room) setSub(item.items ? i : null); }}
          onClick={() => (item.items ? setSub(subOpen ? null : i) : choose(i))}>
          {item.checked !== undefined ? <span className="el-menu__check" aria-hidden="true">{item.checked && <Icon icon={CheckIcon} />}</span>
            : ticks && <span className="el-menu__check" aria-hidden="true" />}
          {item.icon ? <Icon icon={item.icon} /> : item.checked === undefined && <span className="el-menu__icon-space" aria-hidden="true" />}
          <span className="el-menu__label">{item.label}</span>
          {item.count !== undefined && <span className="el-menu__shortcut el-caption el-num" aria-hidden="true">{item.count}</span>}
          {item.shortcut && <kbd className="el-menu__shortcut el-caption" aria-hidden="true">{item.shortcut}</kbd>}
          {item.items && <Icon icon={ChevronEndIcon} directional />}
        </div>
        {subOpen && item.items && (
          <MenuList label={item.label} items={item.items} className={`el-menu el-menu--sub${fit.room ? " el-menu--inline" : ""}`} initial={0}
            inline={!!fit.room} onBack={() => { setSub(null); }} onLeave={onLeave} onPick={onPick} />
        )}
      </li>
      </Fragment>
    );
  };

  return (
    <ul className={`${className}${items.some((i) => i.items) ? " el-menu--has-sub" : ""}`} role="menu" id={id} aria-label={label} ref={setRef}
      onKeyDown={keyDown} style={fitted} data-flip={fit.flip || undefined} data-up={fit.up || undefined}>
      {runs(items).map(({ group, from, to }) => {
        const rows = items.slice(from, to).map((item, k) => row(item, from + k));
        if (!group) return rows;
        // A titled group is announced as one: its own role="group" named by its header, so a screen reader hears
        // "Sort, group" and each section's ticks are a radio set of their own (Sort, Group by, Density).
        return (
          <li key={`g-${group}:${from}`} role="none" className="el-menu__section">
            <ul role="group" aria-label={group}>
              <li role="presentation" aria-hidden="true" className="el-menu__group el-caption">{group}</li>
              {rows}
            </ul>
          </li>
        );
      })}
    </ul>
  );
}

/** Consecutive items with the same `group` (none: ungrouped), as index ranges [from, to). */
function runs(items: readonly MenuItem[]): { group?: string; from: number; to: number }[] {
  const out: { group?: string; from: number; to: number }[] = [];
  items.forEach((item, i) => {
    const last = out[out.length - 1];
    if (last && last.group === item.group) last.to = i + 1;
    else out.push({ group: item.group, from: i, to: i + 1 });
  });
  return out;
}

/** "⌘A" → "Meta+A", "Del" → "Delete", for aria-keyshortcuts. */
function keyNames(shortcut: string): string {
  return shortcut.replace("⌘", "Meta+").replace("Ctrl+", "Control+").replace(/^Del$/, "Delete").replace("⇧", "Shift+");
}

/**
 * A button that opens a list of actions (a project's menu, a recording's ⋮). The list is a menu: arrow keys, Home and
 * End move, Enter picks, Esc closes and gives focus back to the button; a press outside closes it.
 */
export function Menu({ label, items: given, button, chip }: {
  label: string;
  /** The items, or a function that makes them when the menu opens (a row's ⋮ in a long list). */
  items: readonly MenuItem[] | (() => readonly MenuItem[]);
  /** The button's words (a string, or words with a part that hides when space runs out); `aria-label` names it
   * instead when given. */
  button?: Omit<ButtonProps, "onClick" | "children"> & { text?: ReactNode };
  /** A filter chip opens it instead of a button: its words, and filled while the filter is on. */
  chip?: { text: string; on: boolean };
}) {
  const id = useId();
  // The items are made when the menu opens and kept while it is open.
  const [open, setOpen] = useState<false | { at: number; items: readonly MenuItem[] }>(false);
  const make = () => (typeof given === "function" ? given() : given);
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
    const items = make();
    setOpen({ at: e.key === "ArrowDown" ? 0 : items.length - 1, items });
  };
  const { text, ...buttonProps } = button ?? {};
  const press = () => setOpen(open ? false : { at: 0, items: make() });

  return (
    <div className="el-menu-anchor" ref={root}>
      {chip ? (
        <button type="button" ref={opener} className={`el-chip el-chip--menu${chip.on ? " el-chip--on" : ""}`} dir="auto" aria-haspopup="menu"
          aria-expanded={!!open} aria-controls={open ? `${id}-menu` : undefined} onClick={press} onKeyDown={openerKey}>
          {chip.text}<Icon icon={ChevronDownIcon} />
        </button>
      ) : (
        <Button {...buttonProps} ref={opener} aria-label={buttonProps["aria-label"] ?? (text ? undefined : label)} aria-haspopup="menu" aria-expanded={!!open} aria-controls={open ? `${id}-menu` : undefined}
          onClick={press} onKeyDown={openerKey}>{text}</Button>
      )}
      {open && (
        <MenuList label={label} items={typeof given === "function" ? open.items : given} id={`${id}-menu`} className={chip ? "el-menu el-menu--start" : "el-menu el-menu--end"} initial={open.at}
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

