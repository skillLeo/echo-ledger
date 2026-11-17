import type { ReactNode } from "react";
import { Button, Icon, type IconType } from "./Button";
import { CloseIcon } from "./icons";

/** A message at the top of a page that stays until it is answered or closed: one sentence, at most one action. */
export function Banner({ icon, children, action, onClose, closeLabel = "Close", tone = "info", testId }: {
  icon?: IconType;
  children: ReactNode;
  action?: ReactNode;
  onClose?: () => void;
  closeLabel?: string;
  tone?: "info" | "warning" | "danger";
  testId?: string;
}) {
  return (
    <div className={`el-banner el-banner--${tone}`} role={tone === "info" ? "status" : "alert"} data-testid={testId}>
      {icon && <span className="el-banner__icon"><Icon icon={icon} /></span>}
      <span className="el-banner__text">{children}</span>
      {action}
      {onClose && <Button size="sm" variant="quiet" icon={CloseIcon} aria-label={closeLabel} onClick={onClose} />}
    </div>
  );
}
