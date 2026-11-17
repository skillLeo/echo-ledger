import { useId, type KeyboardEvent, type MouseEvent, type PointerEvent, type ReactNode } from "react";
import { Icon, type IconType } from "./Button";
import { CheckIcon, ChevronDownIcon, ChevronEndIcon } from "./icons";

/** A list of rows with an accessible name. */
export function List({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return <ul className={["el-list", className].filter(Boolean).join(" ")} aria-label={label}>{children}</ul>;
}

/** A plain heading between groups of rows (Today, Yesterday; Recording, Privacy). */
export function ListSection({ title, id, count, collapsed, onToggle, pick }: {
  title: ReactNode;
  id?: string;
  /** How many rows the section has, after its title ("Today · 3"). */
  count?: number;
  /** With onToggle the title folds and unfolds the section (aria-expanded). */
  collapsed?: boolean;
  onToggle?: () => void;
  /** Selection mode: a box that picks or unpicks the whole section (mixed when some are picked). */
  pick?: { state: "all" | "some" | "none"; label: string; onToggle: () => void };
}) {
  const words = <>{title}{count !== undefined && <span className="el-list-section__count">{" · "}<span className="el-num">{count}</span></span>}</>;
  return (
    <li className={`el-list-section el-caption el-body-strong${pick ? " el-list-section--pick" : ""}`} id={id}>
      {pick && (
        <input type="checkbox" className="el-check" aria-label={pick.label} checked={pick.state === "all"}
          ref={(el) => { if (el) el.indeterminate = pick.state === "some"; }} onChange={pick.onToggle} />
      )}
      {onToggle ? (
        <button type="button" className="el-list-section__button" aria-expanded={!collapsed} onClick={onToggle}
          aria-label={typeof title === "string" && count !== undefined ? `${title} · ${count}` : undefined}>
          <Icon icon={ChevronDownIcon} />{words}
        </button>
      ) : words}
    </li>
  );
}

export type ListRowProps = {
  title: ReactNode;
  /** One line under the title: source, a snippet, a hint. */
  meta?: ReactNode;
  /** A monochrome icon in a neutral circle (recordings) or plain (settings). */
  icon?: IconType;
  iconStyle?: "avatar" | "plain";
  /** Free leading content instead of an icon (an avatar). */
  leading?: ReactNode;
  /** Stacked on the end side: time over duration. */
  endTop?: ReactNode;
  endBottom?: ReactNode;
  /** A setting's current value, on the end side, before the chevron. */
  value?: ReactNode;
  /** A control that acts on its own (a Toggle); the row is then not a button. */
  control?: ReactNode;
  chevron?: boolean;
  /** The row's press; the event tells a Shift- or Cmd/Ctrl-click (a range or one more in a selection). */
  onClick?: (e: MouseEvent<HTMLButtonElement>) => void;
  /** A right-click on the row, or Shift+F10 and the context-menu key on its button: the row's menu. */
  onContextMenu?: (e: MouseEvent<HTMLElement> | KeyboardEvent<HTMLButtonElement>) => void;
  /** Selection mode: the row ticks and unticks (aria-pressed) and shows a tick in a circle instead of its icon. */
  checked?: boolean;
  /** Buttons on the end side next to the row's own button (a recording's ⋮), shown on hover and focus. */
  actions?: ReactNode;
  selected?: boolean;
  compact?: boolean;
  /** At most one badge per row. */
  badge?: ReactNode;
  /** A small mark on the second line before its words (a recording's screens: an icon and the count). */
  mark?: ReactNode;
  /** What the row stands for (a recording's id): a page can give it keyboard focus back after a change. */
  rowId?: string;
  /** The row opens and closes what follows it in place (aria-expanded). */
  expanded?: boolean;
  /** The row's button can't be pressed now (it says why elsewhere). */
  disabled?: boolean;
  /** A setting this row is the home of: search and links scroll to it and highlight it (id "setting-<id>"). */
  anchor?: string;
  /** The row button's name when the title alone isn't enough ("Play Kick-off from 0:31"); it starts with the title. */
  label?: string;
  /** A press that may start a drag (recordings dragged onto a project in the sidebar). */
  onPointerDown?: (e: PointerEvent<HTMLButtonElement>) => void;
};

/**
 * One row for recordings and settings. With onClick the whole row is one button, named by the title and described
 * by the rest; with a control the row is a label for it.
 */
export function ListRow({
  title, meta, icon, iconStyle = "avatar", leading, endTop, endBottom, value, control, chevron, onClick, selected, compact, badge, onPointerDown, rowId, label, mark, anchor, disabled, expanded,
  onContextMenu, checked, actions,
}: ListRowProps) {
  const id = useId();
  const interactive = Boolean(onClick) && !control;
  const cls = ["el-row", interactive && "el-row--interactive", disabled && "el-row--disabled", (selected || checked) && "el-row--selected", compact && "el-row--compact", actions && "el-row--actions"]
    .filter(Boolean).join(" ");
  const describedBy = [mark && `${id}-mark`, meta && `${id}-meta`, (endTop || endBottom) && `${id}-end`, value && `${id}-value`, badge && `${id}-badge`]
    .filter(Boolean).join(" ") || undefined;
  return (
    <li className={cls} {...(anchor ? { id: `setting-${anchor}`, "data-setting": anchor, tabIndex: -1 } : {})}
      onContextMenu={onContextMenu ? (e) => { e.preventDefault(); onContextMenu(e); } : undefined}>
      {interactive && (
        <button type="button" className="el-row__hit" aria-label={label} aria-labelledby={label ? undefined : `${id}-title`} aria-describedby={describedBy}
          aria-current={selected || undefined} aria-pressed={checked ?? undefined} onClick={onClick} onPointerDown={onPointerDown} data-row-id={rowId} disabled={disabled} aria-expanded={expanded}
          onKeyDown={onContextMenu ? (e) => { if (e.key === "ContextMenu" || (e.shiftKey && e.key === "F10")) { e.preventDefault(); onContextMenu(e); } } : undefined} />
      )}
      {checked !== undefined ? (
        <span className="el-row__leading el-row__leading--tick" aria-hidden="true">
          <span className={`el-row__tick${checked ? " el-row__tick--on" : ""}`}>{checked && <Icon icon={CheckIcon} />}</span>
        </span>
      ) : leading ?? (icon && (
        <span className={`el-row__leading${iconStyle === "avatar" ? " el-row__leading--avatar" : ""}`}>
          <Icon icon={icon} size="md" />
        </span>
      ))}
      <span className="el-row__main">
        <span className={`el-row__title${icon && iconStyle === "avatar" ? " el-row__title--strong" : ""}`} id={`${id}-title`} dir="auto">{title}</span>
        {(meta || badge || mark) && (
          <span className="el-row__meta el-caption">
            {badge && <span id={`${id}-badge`}>{badge}</span>}
            {mark && <span className="el-row__mark" id={`${id}-mark`}>{mark}</span>}
            {meta && <span className="el-row__meta-text" id={`${id}-meta`} dir="auto">{meta}</span>}
          </span>
        )}
      </span>
      {(endTop || endBottom) && (
        <span className="el-row__end el-caption" id={`${id}-end`}>
          {/* A time ("9:00 am") or a length keeps its own order inside a right-to-left row. */}
          {endTop && <span dir="auto">{endTop}</span>}
          {endBottom && <span dir="auto">{endBottom}</span>}
        </span>
      )}
      {value && <span className="el-row__value" id={`${id}-value`}><span className="el-row__value-text" dir="auto">{value}</span></span>}
      {control && <span className="el-row__control">{control}</span>}
      {actions && <span className="el-row__actions">{actions}</span>}
      {chevron && <span className="el-row__chevron"><Icon icon={ChevronEndIcon} directional /></span>}
    </li>
  );
}
