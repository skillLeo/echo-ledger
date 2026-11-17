import { useId, type PointerEvent, type ReactNode } from "react";
import { Icon, type IconType } from "./Button";
import { ChevronEndIcon } from "./icons";

/** A list of rows with an accessible name. */
export function List({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return <ul className={["el-list", className].filter(Boolean).join(" ")} aria-label={label}>{children}</ul>;
}

/** A plain heading between groups of rows (Today, Yesterday; Recording, Privacy). */
export function ListSection({ title, id }: { title: ReactNode; id?: string }) {
  return <li className="el-list-section el-caption el-body-strong" id={id}>{title}</li>;
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
  onClick?: () => void;
  selected?: boolean;
  compact?: boolean;
  /** At most one badge per row. */
  badge?: ReactNode;
  /** What the row stands for (a recording's id): a page can give it keyboard focus back after a change. */
  rowId?: string;
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
  title, meta, icon, iconStyle = "avatar", leading, endTop, endBottom, value, control, chevron, onClick, selected, compact, badge, onPointerDown, rowId, label,
}: ListRowProps) {
  const id = useId();
  const interactive = Boolean(onClick) && !control;
  const cls = ["el-row", interactive && "el-row--interactive", selected && "el-row--selected", compact && "el-row--compact"]
    .filter(Boolean).join(" ");
  const describedBy = [meta && `${id}-meta`, (endTop || endBottom) && `${id}-end`, value && `${id}-value`, badge && `${id}-badge`]
    .filter(Boolean).join(" ") || undefined;
  return (
    <li className={cls}>
      {interactive && (
        <button type="button" className="el-row__hit" aria-label={label} aria-labelledby={label ? undefined : `${id}-title`} aria-describedby={describedBy}
          aria-current={selected || undefined} onClick={onClick} onPointerDown={onPointerDown} data-row-id={rowId} />
      )}
      {leading ?? (icon && (
        <span className={`el-row__leading${iconStyle === "avatar" ? " el-row__leading--avatar" : ""}`}>
          <Icon icon={icon} size="md" />
        </span>
      ))}
      <span className="el-row__main">
        <span className={`el-row__title${icon && iconStyle === "avatar" ? " el-row__title--strong" : ""}`} id={`${id}-title`} dir="auto">{title}</span>
        {(meta || badge) && (
          <span className="el-row__meta el-caption">
            {badge && <span id={`${id}-badge`}>{badge}</span>}
            {meta && <span className="el-row__meta-text" id={`${id}-meta`} dir="auto">{meta}</span>}
          </span>
        )}
      </span>
      {(endTop || endBottom) && (
        <span className="el-row__end el-caption" id={`${id}-end`}>
          {endTop && <span>{endTop}</span>}
          {endBottom && <span>{endBottom}</span>}
        </span>
      )}
      {value && <span className="el-row__value" id={`${id}-value`}><span className="el-row__value-text" dir="auto">{value}</span></span>}
      {control && <span className="el-row__control">{control}</span>}
      {chevron && <span className="el-row__chevron"><Icon icon={ChevronEndIcon} directional /></span>}
    </li>
  );
}
