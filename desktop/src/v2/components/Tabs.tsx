import { useId, useRef, type KeyboardEvent, type ReactNode } from "react";

/** `badge`: the plan a locked tab's feature is in ("Pro"), shown after its name. */
/** `count`: how many the tab lists (a project's recordings, its files), after its name. */
export type TabItem<T extends string> = { value: T; label: string; badge?: string | null; count?: number | string | null };

/** Arrow keys in reading order: in right-to-left, ArrowLeft moves forward. Home and End jump to the ends. */
function nextIndex(e: KeyboardEvent<HTMLElement>, at: number, count: number): number | null {
  const rtl = getComputedStyle(e.currentTarget).direction === "rtl";
  const forward = rtl ? "ArrowLeft" : "ArrowRight";
  const back = rtl ? "ArrowRight" : "ArrowLeft";
  if (e.key === forward || e.key === "ArrowDown") return (at + 1) % count;
  if (e.key === back || e.key === "ArrowUp") return (at - 1 + count) % count;
  if (e.key === "Home") return 0;
  if (e.key === "End") return count - 1;
  return null;
}

/** Page tabs (Transcript · Summary · Ask · Moments · Details); the selected tab's panel is the children. */
export function Tabs<T extends string>({ label, tabs, value, onChange, children }: {
  label: string;
  tabs: TabItem<T>[];
  value: T;
  onChange: (value: T) => void;
  children?: ReactNode;
}) {
  const id = useId();
  const list = useRef<HTMLDivElement>(null);
  const at = Math.max(0, tabs.findIndex((t) => t.value === value));
  const keyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const next = nextIndex(e, at, tabs.length);
    if (next === null) return;
    e.preventDefault();
    onChange(tabs[next].value);
    list.current?.querySelectorAll<HTMLElement>('[role="tab"]')[next]?.focus();
  };
  return (
    <div className="el-tabs">
      <div className="el-tabs__list" role="tablist" aria-label={label} ref={list} onKeyDown={keyDown}>
        {tabs.map((t, i) => (
          <button key={t.value} type="button" role="tab" className="el-tab" id={`${id}-tab-${t.value}`}
            aria-selected={i === at} aria-controls={`${id}-panel`} tabIndex={i === at ? 0 : -1} onClick={() => onChange(t.value)}>
            {t.label}
            {t.count !== undefined && t.count !== null && <span className="el-tab__count el-caption el-num" aria-hidden="true">{t.count}</span>}
            {t.badge && <>{" "}<span className="plan-badge static mark" title={`${t.label} is in ${t.badge}`}>{t.badge}</span></>}
          </button>
        ))}
      </div>
      <div className="el-tabs__panel" role="tabpanel" id={`${id}-panel`} aria-labelledby={`${id}-tab-${tabs[at]?.value}`} tabIndex={0}>
        {children}
      </div>
    </div>
  );
}

/** A small set of exclusive choices (System · Light · Dark), a radio group drawn as one control. */
export function Segmented<T extends string>({ label, options, value, onChange }: {
  label: string;
  options: TabItem<T>[];
  value: T;
  onChange: (value: T) => void;
}) {
  const group = useRef<HTMLDivElement>(null);
  const at = Math.max(0, options.findIndex((o) => o.value === value));
  const keyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const next = nextIndex(e, at, options.length);
    if (next === null) return;
    e.preventDefault();
    onChange(options[next].value);
    group.current?.querySelectorAll<HTMLElement>('[role="radio"]')[next]?.focus();
  };
  return (
    <div className="el-segmented" role="radiogroup" aria-label={label} ref={group} onKeyDown={keyDown}>
      {options.map((o, i) => (
        <button key={o.value} type="button" role="radio" className="el-segmented__item" aria-checked={i === at}
          tabIndex={i === at ? 0 : -1} onClick={() => onChange(o.value)}>
          {o.label}
        </button>
      ))}
    </div>
  );
}
