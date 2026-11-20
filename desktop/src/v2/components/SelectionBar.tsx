import { useEffect, useRef, type KeyboardEvent } from "react";
import { Button } from "./Button";
import { Menu, type MenuItem } from "./Menu";
import { CloseIcon, MoreIcon } from "./icons";

/** One action of the selection: a menu item with an id, so a page can name the ones that get their own button. */
export type SelectionAction = MenuItem & { id: string };

export type SelectionWords = {
  /** The bar's title for a count ("3 selected"). */
  selected: (count: number) => string;
  /** The title while nothing is picked yet. */
  none: string;
  selectAll: string;
  unselectAll: string;
  exit: string;
  more: string;
};

const WORDS: SelectionWords = {
  selected: (n) => `${n} selected`,
  none: "Select items",
  selectAll: "Select all",
  unselectAll: "Unselect all",
  exit: "Cancel selection",
  more: "More actions",
};

/** The most actions that get their own button; the rest are in ⋮. */
export const SELECTION_MAIN_MAX = 4;

/** A key typed into a field belongs to the field. */
const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName));

export type SelectionBarProps = {
  /** The toolbar's name for assistive tools ("Selected recordings"). */
  label: string;
  count: number;
  /** Every item is picked: the toggle offers Unselect all. */
  allSelected: boolean;
  onToggleAll: () => void;
  /** ×, and Esc anywhere on the page that nothing else (a menu, a dialog, a field) has taken. */
  onExit: () => void;
  /** Every action of the selection, in menu order. */
  actions: readonly SelectionAction[];
  /** The ids of the actions that get an icon button, in order (Move to project, Label, Share or export, Delete); at most four. */
  main: readonly string[];
  words?: Partial<SelectionWords>;
  /** The page heading level of the title (the bar takes the page header's place). */
  level?: 1 | 2;
};

/**
 * The top bar in selection mode: ×, "3 selected", Select all or Unselect all, then up to four actions as icon buttons
 * (a danger one in the danger colour) and ⋮ with the rest. It is a toolbar: the arrow keys, Home and End move along
 * it, and Esc leaves selection mode. The count is announced politely as it changes. With nothing picked, the
 * actions can't be pressed.
 */
export function SelectionBar({ label, count, allSelected, onToggleAll, onExit, actions, main, words = {}, level = 1 }: SelectionBarProps) {
  const w = { ...WORDS, ...words };
  const bar = useRef<HTMLDivElement>(null);
  const exit = useRef(onExit);
  exit.current = onExit;
  const none = count === 0;

  useEffect(() => {
    const onKey = (e: globalThis.KeyboardEvent) => {
      if (e.key !== "Escape" || e.defaultPrevented || typing(e.target) || document.querySelector("[aria-modal=true]")) return;
      e.preventDefault();
      exit.current();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, []);

  const mainIds = main.slice(0, SELECTION_MAIN_MAX);
  const shown = mainIds.map((id) => actions.find((a) => a.id === id)).filter((a): a is SelectionAction => !!a);
  const rest = actions.filter((a) => !mainIds.includes(a.id));

  // The toolbar's arrow keys: along the bar's own buttons (never into an open menu, whose list keeps its keys).
  const move = (e: KeyboardEvent<HTMLDivElement>) => {
    const rtl = bar.current ? getComputedStyle(bar.current).direction === "rtl" : false;
    const next = rtl ? "ArrowLeft" : "ArrowRight";
    const prev = rtl ? "ArrowRight" : "ArrowLeft";
    if (![next, prev, "Home", "End"].includes(e.key) || typing(e.target)) return;
    const buttons = [...(bar.current?.querySelectorAll<HTMLButtonElement>("button") ?? [])].filter((b) => !b.disabled && !b.closest(".el-menu"));
    const at = buttons.indexOf(document.activeElement as HTMLButtonElement);
    if (at < 0) return;
    e.preventDefault();
    const to = e.key === "Home" ? 0 : e.key === "End" ? buttons.length - 1 : e.key === next ? Math.min(at + 1, buttons.length - 1) : Math.max(at - 1, 0);
    buttons[to]?.focus();
  };

  const Title = level === 1 ? "h1" : "h2";
  return (
    <div className="el-selection-bar" role="toolbar" aria-label={label} ref={bar} onKeyDown={move}>
      <Button variant="quiet" icon={CloseIcon} aria-label={w.exit} title={w.exit} aria-keyshortcuts="Escape" onClick={onExit} />
      <Title className="el-selection-bar__title el-title-s" aria-live="polite" aria-atomic="true" title={none ? w.none : w.selected(count)}>
        {none ? w.none : w.selected(count)}
      </Title>
      <Button variant="quiet" size="sm" className="el-selection-bar__all" onClick={onToggleAll}>{allSelected && !none ? w.unselectAll : w.selectAll}</Button>
      <div className="el-selection-bar__actions">
        {shown.map((a) => {
          const off = none || a.disabled;
          const variant = "quiet" as const;
          const cls = a.danger ? "el-selection-bar__danger" : undefined;
          const name = a.label;
          return a.items?.length ? (
            <Menu key={a.id} label={name} items={a.items} button={{ variant, icon: a.icon ?? MoreIcon, title: name, disabled: off, className: cls }} />
          ) : (
            <Button key={a.id} variant={variant} icon={a.icon} className={cls} aria-label={a.icon ? name : undefined} title={a.shortcut ? `${name} (${a.shortcut})` : name}
              disabled={off} onClick={() => a.onPick?.()}>{a.icon ? undefined : name}</Button>
          );
        })}
        {rest.length > 0 && <Menu label={w.more} items={rest} button={{ variant: "quiet", icon: MoreIcon, title: w.more, disabled: none }} />}
      </div>
    </div>
  );
}
