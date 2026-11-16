import type { ReactNode } from "react";
import { Icon, type IconType } from "./Button";

/** What a list shows with nothing in it: a quiet icon, one title, one sentence, at most one action. */
export function EmptyState({ icon, title, body, action }: { icon?: IconType; title: ReactNode; body?: ReactNode; action?: ReactNode }) {
  return (
    <section className="el-empty" aria-label={typeof title === "string" ? title : undefined}>
      {icon && <span className="el-empty__icon"><Icon icon={icon} size="lg" /></span>}
      <h2 className="el-title-m">{title}</h2>
      {body && <p className="el-empty__body">{body}</p>}
      {action && <div className="el-empty__action">{action}</div>}
    </section>
  );
}
