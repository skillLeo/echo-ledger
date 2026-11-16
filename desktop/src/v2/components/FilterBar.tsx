import type { ReactNode } from "react";
import { Icon } from "./Button";
import { CloseIcon } from "./icons";

export type ChipProps = {
  label: string;
  /** A quick filter: pressed or not. */
  selected?: boolean;
  onToggle?: () => void;
  /** An applied filter: a remove button instead of a toggle. */
  onRemove?: () => void;
  removeLabel?: string;
  count?: number;
};

/** A filter chip: a toggle button, or an applied filter with its own remove button. */
export function Chip({ label, selected = false, onToggle, onRemove, removeLabel, count }: ChipProps) {
  if (onRemove) {
    return (
      <span className="el-chip el-chip--removable" dir="auto">
        {label}
        <button type="button" className="el-chip__remove" aria-label={removeLabel ?? `Remove filter: ${label}`} onClick={onRemove}>
          <Icon icon={CloseIcon} />
        </button>
      </span>
    );
  }
  return (
    <button type="button" className="el-chip" aria-pressed={selected} onClick={onToggle} dir="auto">
      {label}
      {count !== undefined && <span className="el-chip__count el-num">{count}</span>}
    </button>
  );
}

/** Quick filters and applied filters in one row, named for assistive tools ("Filter recordings"). */
export function FilterBar({ label, children, end }: { label: string; children: ReactNode; end?: ReactNode }) {
  return (
    <div className="el-filterbar" role="group" aria-label={label}>
      {children}
      {end}
    </div>
  );
}
