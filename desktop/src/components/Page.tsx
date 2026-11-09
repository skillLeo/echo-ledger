// The page pattern (v1.6, P4.4): breadcrumbs, the title and a one-line subtitle, the actions on the right (one
// primary), then tabs with their counts. "Saved · just now" says when a setting was last saved.
import { useEffect, useState, type ReactNode } from "react";
import { savedLine } from "../lib/shell";
import { CheckIcon } from "./ControlIcons";
import { ChevronRightIcon } from "./Icons";

export interface Crumb {
  label: string;
  /** Opens that page; the last crumb (this page) has none. */
  onOpen?: () => void;
}

export function PageHeader({ crumbs = [], title, subtitle, actions, children, titleId }: {
  crumbs?: readonly Crumb[];
  title: ReactNode;
  subtitle?: ReactNode;
  /** The page's actions, the primary one last. */
  actions?: ReactNode;
  /** Under the title (tabs, a notice). */
  children?: ReactNode;
  titleId?: string;
}) {
  return (
    <header className="page-header">
      {crumbs.length > 0 && (
        <nav className="crumbs page-crumbs" aria-label="Breadcrumbs">
          {crumbs.map((c, i) => (
            <span key={`${c.label}-${i}`} className="crumb-step">
              {i > 0 && <ChevronRightIcon size={12} aria-hidden="true" />}
              {c.onOpen ? <button type="button" className="crumb" onClick={c.onOpen}>{c.label}</button>
                : <span className="crumb current" aria-current={i === crumbs.length - 1 ? "page" : undefined}>{c.label}</span>}
            </span>
          ))}
        </nav>
      )}
      <div className="page-head">
        <div className="page-head-main">
          <h1 className="page-title" id={titleId}>{title}</h1>
          {subtitle && <p className="page-sub">{subtitle}</p>}
        </div>
        <div className="spacer" />
        {actions && <div className="page-actions">{actions}</div>}
      </div>
      {children}
    </header>
  );
}

export interface TabItem<T extends string> {
  id: T;
  label: string;
  count?: number | null;
}

/** Tabs with their counts (a tablist; the panel is the page under it). */
export function PageTabs<T extends string>({ tabs, value, onChange, label }: { tabs: readonly TabItem<T>[]; value: T; onChange: (id: T) => void; label: string }) {
  const move = (e: React.KeyboardEvent, i: number) => {
    const next = e.key === "ArrowRight" ? (i + 1) % tabs.length : e.key === "ArrowLeft" ? (i - 1 + tabs.length) % tabs.length : -1;
    if (next < 0) return;
    e.preventDefault();
    onChange(tabs[next].id);
    (e.currentTarget.parentElement?.children[next] as HTMLElement | undefined)?.focus();
  };
  return (
    <div className="page-tabs" role="tablist" aria-label={label}>
      {tabs.map((t, i) => (
        <button key={t.id} type="button" role="tab" aria-selected={t.id === value} tabIndex={t.id === value ? 0 : -1} className={`page-tab ${t.id === value ? "active" : ""}`}
          onClick={() => onChange(t.id)} onKeyDown={(e) => move(e, i)}>
          {t.label}{typeof t.count === "number" && <span className="tab-count" aria-hidden="true">{t.count}</span>}
        </button>
      ))}
    </div>
  );
}

/** "Saved · just now", then "Saved · 2 min ago" as time goes on; nothing before the first save. */
export function SavedIndicator({ at }: { at: number | null }) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (at === null) return;
    setNow(Date.now());
    const timer = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(timer);
  }, [at]);
  if (at === null) return null;
  return <span className="saved-indicator small" aria-live="polite" data-testid="saved"><CheckIcon size={14} />{savedLine(at, Math.max(now, at))}</span>;
}
