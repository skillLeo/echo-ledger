import type { ReactNode } from "react";
import { Icon, type IconType } from "./Button";

/**
 * What a place shows with nothing in it, or when it could not load (`tone="error"`, announced at once): a quiet icon,
 * one title, one sentence, at most one action (the one fix).
 */
export function EmptyState({ icon, title, body, action, tone = "empty" }: { icon?: IconType; title: ReactNode; body?: ReactNode; action?: ReactNode; tone?: "empty" | "error" }) {
  return (
    <section className={`el-empty${tone === "error" ? " el-empty--error" : ""}`} aria-label={typeof title === "string" ? title : undefined}
      role={tone === "error" ? "alert" : undefined}>
      {icon && <span className="el-empty__icon"><Icon icon={icon} size="lg" /></span>}
      <h2 className="el-title-m">{title}</h2>
      {body && <p className="el-empty__body" dir="auto">{body}</p>}
      {action && <div className="el-empty__action">{action}</div>}
    </section>
  );
}
