import { useId, type KeyboardEvent, type MouseEvent, type PointerEvent, type ReactNode } from "react";
import { Icon, type IconType } from "./Button";
import { CheckIcon, ChevronDownIcon, ChevronEndIcon, ImportantIcon, PinIcon } from "./icons";
import { LabelDot } from "./Label";

/**
 * What a row's fixed indicator slot shows, always in this order: unread, pinned, important, then up to 3 label dots
 * (D12). Nothing in the slot ever goes into the snippet; an empty slot takes no room.
 */
export type RowIndicators = {
  unread?: boolean;
  pinned?: boolean;
  important?: boolean;
  labels?: readonly { name: string; colour: string | null | undefined }[];
  /** The words assistive tools hear for each mark, in the page's language. */
  words?: Partial<IndicatorWords>;
};
export type IndicatorWords = { unread: string; pinned: string; important: string };
export const INDICATOR_WORDS: IndicatorWords = { unread: "Unread", pinned: "Pinned", important: "Important" };

const anyIndicator = (ind: RowIndicators | undefined) => !!ind && (!!ind.unread || !!ind.pinned || !!ind.important || !!ind.labels?.length);

/** The slot's marks, or null when there are none. */
function Indicators({ of: ind, id }: { of: RowIndicators; id: string }) {
  const w = { ...INDICATOR_WORDS, ...ind.words };
  const labels = (ind.labels ?? []).slice(0, 3);
  if (!anyIndicator(ind)) return null;
  return (
    <span className="el-row__slot" id={id}>
      {/* The marks are drawn for the eye; the words after them are what the row's button reads. */}
      {ind.unread && <span className="el-row__unread" aria-hidden="true" />}
      {ind.pinned && <span className="el-row__ind" aria-hidden="true"><Icon icon={PinIcon} /></span>}
      {ind.important && <span className="el-row__ind el-row__ind--important" aria-hidden="true"><Icon icon={ImportantIcon} /></span>}
      {labels.length > 0 && <span className="el-row__dots" aria-hidden="true">{labels.map((l, i) => <LabelDot key={`${l.name}:${i}`} name={l.name} colour={l.colour} />)}</span>}
      <span className="el-visually-hidden">
        {[ind.unread && w.unread, ind.pinned && w.pinned, ind.important && w.important, labels.map((l) => l.name).join(", ")].filter(Boolean).join(" ")}
      </span>
    </span>
  );
}

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
  /** Free leading content instead of an icon (an avatar, a recording's source logo). */
  leading?: ReactNode;
  /** The title in the strong weight, as a row with an avatar icon has it (a row with its own `leading`). */
  strong?: boolean;
  /**
   * "library": the Library's recording row (D12): 72 high (56 compact), a strong title and a secondary snippet on one
   * line each, a hairline divider between rows from the text's start, bg-subtle on hover and focus, and the end column
   * as left-to-right isolates in the row's own face with even-width digits.
   */
  variant?: "default" | "library";
  /** The fixed slot between the text and the end column: unread, pinned, important, label dots. */
  indicators?: RowIndicators;
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
  /** "always": the actions stay on screen, quiet (a list whose rows have no other way to their actions, as Shared). */
  actionsShown?: "hover" | "always";
  /** 2: the meta line wraps onto a second line before it is cut, so a time at its end isn't lost in a narrow window. */
  metaLines?: 1 | 2;
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
  onContextMenu, checked, actions, strong, actionsShown = "hover", metaLines = 1, variant = "default", indicators,
}: ListRowProps) {
  const library = variant === "library";
  const id = useId();
  const interactive = Boolean(onClick) && !control;
  const cls = ["el-row", library && "el-row--library", interactive && "el-row--interactive", disabled && "el-row--disabled", (selected || checked) && "el-row--selected", compact && "el-row--compact", actions && "el-row--actions", actions && actionsShown === "always" && "el-row--actions-always", metaLines === 2 && "el-row--meta-2"]
    .filter(Boolean).join(" ");
  const describedBy = [mark && `${id}-mark`, meta && `${id}-meta`, anyIndicator(indicators) && `${id}-slot`, (endTop || endBottom) && `${id}-end`, value && `${id}-value`, badge && `${id}-badge`]
    .filter(Boolean).join(" ") || undefined;
  const lead = checked !== undefined ? (
    <span className="el-row__leading el-row__leading--tick" aria-hidden="true">
      <span className={`el-row__tick${checked ? " el-row__tick--on" : ""}`}>{checked && <Icon icon={CheckIcon} />}</span>
    </span>
  ) : leading ?? (icon && (
    <span className={`el-row__leading${iconStyle === "avatar" ? " el-row__leading--avatar" : ""}`}>
      <Icon icon={icon} size="md" />
    </span>
  ));
  return (
    <li className={cls} {...(anchor ? { id: `setting-${anchor}`, "data-setting": anchor, tabIndex: -1 } : {})}
      onContextMenu={onContextMenu ? (e) => { e.preventDefault(); onContextMenu(e); } : undefined}>
      {interactive && (
        <button type="button" className="el-row__hit" aria-label={label} aria-labelledby={label ? undefined : `${id}-title`} aria-describedby={describedBy}
          aria-current={selected || undefined} aria-pressed={checked ?? undefined} onClick={onClick} onPointerDown={onPointerDown} data-row-id={rowId} disabled={disabled} aria-expanded={expanded}
          onKeyDown={onContextMenu ? (e) => { if (e.key === "ContextMenu" || (e.shiftKey && e.key === "F10")) { e.preventDefault(); onContextMenu(e); } } : undefined} />
      )}
      {library && lead ? <span className="el-row__lead">{lead}</span> : lead}
      <span className="el-row__main">
        <span className={`el-row__title${strong || library || (icon && iconStyle === "avatar") ? " el-row__title--strong" : ""}`} id={`${id}-title`} dir="auto">{title}</span>
        {(meta || badge || mark) && (
          <span className="el-row__meta el-caption">
            {badge && <span id={`${id}-badge`}>{badge}</span>}
            {mark && <span className="el-row__mark" id={`${id}-mark`}>{mark}</span>}
            {meta && <span className="el-row__meta-text" id={`${id}-meta`} dir="auto">{meta}</span>}
          </span>
        )}
      </span>
      {indicators && <Indicators of={indicators} id={`${id}-slot`} />}
      {(endTop || endBottom) && (
        <span className="el-row__end el-caption" id={`${id}-end`}>
          {/* A time ("9:00 am") or a length keeps its own order inside a right-to-left row; the library row makes each
              a left-to-right isolate ("15 Nov", "5:09 am", "1:19"), as D12 asks. */}
          {endTop && <span className="el-row__end-top" dir={library ? undefined : "auto"}>{library ? <span dir="ltr">{endTop}</span> : endTop}</span>}
          {library && endTop && endBottom && " "}
          {endBottom && <span className="el-row__end-bottom" dir={library ? undefined : "auto"}>{library ? <span dir="ltr">{endBottom}</span> : endBottom}</span>}
        </span>
      )}
      {value && <span className="el-row__value" id={`${id}-value`}><span className="el-row__value-text" dir="auto">{value}</span></span>}
      {control && <span className="el-row__control">{control}</span>}
      {actions && <span className="el-row__actions">{actions}</span>}
      {chevron && <span className="el-row__chevron"><Icon icon={ChevronEndIcon} directional /></span>}
    </li>
  );
}
