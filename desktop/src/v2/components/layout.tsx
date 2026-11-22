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
export function Sidebar({ label, brand, brandEnd, children, footer, rail, width }: {
  label: string;
  /** Icons only (true) or full (false) whatever the window's width; left out, the window's width decides. */
  rail?: boolean;
  /** Its width in CSS pixels while not a rail (the person's, 200 to 320); left out, the design's. */
  width?: number;
  brand?: ReactNode;
  /** A control at the brand's end (Search): it stays at the top of the rail when the name hides. */
  brandEnd?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <nav className="el-sidebar" aria-label={label} data-rail={rail === undefined ? undefined : rail ? "on" : "off"}
      style={width !== undefined && !rail ? { ["--el-sidebar-width" as string]: `${width}px` } : undefined}>
      {(brand || brandEnd) && (
        <div className="el-sidebar__brand">
          {brand && <span className="el-sidebar__brand-name">{brand}</span>}
          {brandEnd && <span className="el-sidebar__brand-end">{brandEnd}</span>}
        </div>
      )}
      {children}
      {footer}
    </nav>
  );
}

/**
 * One place in the sidebar; the current page is marked for assistive tools too. Its count needs attention (Activity's
 * "needs you"): the accent pill, and in the icon rail, where the count is only for assistive tools, a small accent dot
 * on the icon. Its name says the count as the Library's places do: "Activity, 6".
 */
export function NavItem({ icon, label, current, count, onClick }: { icon: IconType; label: string; current?: boolean; count?: number; onClick?: () => void }) {
  return (
    <button type="button" className="el-nav-item" aria-current={current ? "page" : undefined} onClick={onClick} title={label}
      aria-label={count ? `${label}, ${count}` : undefined}>
      <Icon icon={icon} size="md" />
      <span className="el-nav-item__label">{label}</span>
      {count !== undefined && <span className="el-nav-item__count el-caption el-num">{count}</span>}
      {count !== undefined && count > 0 && <span className="el-attention-dot el-nav-item__dot" aria-hidden="true" />}
    </button>
  );
}
