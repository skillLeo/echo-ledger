import type { ReactNode } from "react";
import { Icon, type IconType } from "./Button";

/** A flat panel with a hairline border (mixed items only, never a coloured edge). */
export function Card({ children, className, label }: { children: ReactNode; className?: string; label?: string }) {
  return <section className={["el-card", className].filter(Boolean).join(" ")} aria-label={label}>{children}</section>;
}

/** Initials in a neutral circle; the name is read from the text beside it, so the avatar itself is hidden. */
export function Avatar({ initials, size = "md" }: { initials: string; size?: "sm" | "md" }) {
  return <span className={`el-avatar${size === "sm" ? " el-avatar--sm" : ""}`} aria-hidden="true">{initials}</span>;
}

/** The app's primary navigation; below the rail breakpoint (780 px window) it keeps its icons only. */
export function Sidebar({ label, brand, children, footer }: { label: string; brand?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <nav className="el-sidebar" aria-label={label}>
      {brand && <div className="el-sidebar__brand">{brand}</div>}
      {children}
      {footer}
    </nav>
  );
}

/** One place in the sidebar; the current page is marked for assistive tools too. */
export function NavItem({ icon, label, current, count, onClick }: { icon: IconType; label: string; current?: boolean; count?: number; onClick?: () => void }) {
  return (
    <button type="button" className="el-nav-item" aria-current={current ? "page" : undefined} onClick={onClick} title={label}>
      <Icon icon={icon} size="md" />
      <span className="el-nav-item__label">{label}</span>
      {count !== undefined && <span className="el-nav-item__count el-caption el-num">{count}</span>}
    </button>
  );
}
