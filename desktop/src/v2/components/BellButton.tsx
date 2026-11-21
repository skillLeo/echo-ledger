import type { ComponentPropsWithRef } from "react";
import { Icon } from "./Button";
import { BellIcon } from "./icons";

/** "99+" above 99, the number itself up to there, nothing at 0. */
export function unreadBadge(count: number): string | null {
  const n = Math.max(0, Math.floor(count));
  return n === 0 ? null : n > 99 ? "99+" : String(n);
}

export type BellButtonProps = Omit<ComponentPropsWithRef<"button">, "children" | "aria-label"> & {
  /** Unread activity; the badge hides at 0. */
  unread: number;
  /** The button's name without a count. */
  label?: string;
  /** Its name with a count ("Activity, 3 unread"). */
  unreadLabel?: (count: number) => string;
};

/**
 * Activity: the bell in the top bar with the unread count in a small badge on its end side. The count is part of the
 * button's name, so the badge itself is hidden from assistive tools. Nothing moves when the count changes.
 */
export function BellButton({ unread, label = "Activity", unreadLabel = (n) => `${label}, ${n} unread`, className, type = "button", ...rest }: BellButtonProps) {
  const badge = unreadBadge(unread);
  const n = Math.max(0, Math.floor(unread));
  return (
    <button type={type} className={["el-button el-button--quiet el-button--icon el-bell", className].filter(Boolean).join(" ")}
      aria-label={badge ? unreadLabel(n) : label} title={label} {...rest}>
      <Icon icon={BellIcon} />
      {badge && <span className="el-bell__badge el-num" aria-hidden="true">{badge}</span>}
    </button>
  );
}
