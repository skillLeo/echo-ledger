// One "Filters" dropdown for the Library (replaces three rows of chips): checkboxes in three groups. Inside a group the
// ticked boxes add up (Today or Pinned); between groups they narrow (Today and Phone). Nothing ticked = no filter.
import { useEffect, useRef, useState, type ReactNode } from "react";
import { CloseIcon, DesktopIcon, GlobeIcon, PhoneIcon, TuneIcon } from "../../components/Icons";
import { BookmarkIcon } from "../../components/ControlIcons";
import { SMART_VIEWS, type SmartView } from "../../lib/organise";

export type Source = "phone" | "desktop" | "web";

const SOURCES: readonly (readonly [Source, string, ReactNode])[] = [
  ["phone", "Phone", <PhoneIcon key="p" size={13} />],
  ["desktop", "Desktop", <DesktopIcon key="d" size={13} />],
  ["web", "Web", <GlobeIcon key="w" size={13} />],
];

/** The set with `value` added or removed. */
export function toggled<T>(set: ReadonlySet<T>, value: T): Set<T> {
  const next = new Set(set);
  if (!next.delete(value)) next.add(value);
  return next;
}

export interface FilterState {
  views: ReadonlySet<SmartView>;
  sources: ReadonlySet<Source>;
  tags: ReadonlySet<string>;
}

export const NO_FILTERS: FilterState = { views: new Set(), sources: new Set(), tags: new Set() };

export function activeFilterCount(f: FilterState): number {
  return f.views.size + f.sources.size + f.tags.size;
}

export function LibraryFilters({ state, onChange, counts, tags, showBookmarks }: {
  state: FilterState;
  onChange: (next: FilterState) => void;
  counts: Record<SmartView, number>;
  /** The bookmark tags that exist in the library. */
  tags: string[];
  /** Whether to list the bookmark tags: only when some exist or one is ticked. (Any bookmark is the "Bookmarked" view.) */
  showBookmarks: boolean;
}) {
  const [open, setOpen] = useState(false);
  const wrap = useRef<HTMLDivElement>(null);
  const active = activeFilterCount(state);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    const onOutside = (e: MouseEvent) => {
      if (wrap.current && e.target instanceof Node && !wrap.current.contains(e.target)) setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    window.addEventListener("mousedown", onOutside);
    return () => {
      window.removeEventListener("keydown", onKey);
      window.removeEventListener("mousedown", onOutside);
    };
  }, [open]);

  const box = (label: ReactNode, name: string, checked: boolean, onToggle: () => void, count?: number) => (
    <label className="filter-option" key={name}>
      <input type="checkbox" checked={checked} onChange={onToggle} aria-label={name} />
      <span className="filter-label">{label}</span>
      {count !== undefined && count > 0 && <span className="count">{count}</span>}
    </label>
  );

  return (
    <div className="filter-menu" ref={wrap}>
      <button type="button" className={`chip ${active > 0 ? "active" : ""}`} aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(!open)}>
        <TuneIcon size={16} />Filters{active > 0 && <span className="badge-inline" aria-label={`${active} filters on`}>{active}</span>}
      </button>
      {open && (
        <div className="card filter-panel" role="dialog" aria-label="Filters">
          <div className="row">
            <b>Filters</b>
            <span className="spacer" />
            {active > 0 && <button type="button" className="btn ghost small" onClick={() => onChange(NO_FILTERS)}>Clear all</button>}
            <button type="button" className="btn ghost small" aria-label="Close filters" onClick={() => setOpen(false)}><CloseIcon size={14} /></button>
          </div>
          <fieldset className="filter-group">
            <legend>Show</legend>
            {SMART_VIEWS.filter(([key]) => key !== "all").map(([key, text]) =>
              box(text, text, state.views.has(key), () => onChange({ ...state, views: toggled(state.views, key) }), counts[key]))}
          </fieldset>
          <fieldset className="filter-group">
            <legend>Recorded on</legend>
            {SOURCES.map(([key, text, icon]) =>
              box(<>{icon}{text}</>, text, state.sources.has(key), () => onChange({ ...state, sources: toggled(state.sources, key) })))}
          </fieldset>
          {showBookmarks && tags.length > 0 && (
            <fieldset className="filter-group">
              <legend>Bookmark tags</legend>
              {tags.map((t) => box(<><BookmarkIcon size={13} />{t}</>, `Tag ${t}`, state.tags.has(t), () => onChange({ ...state, tags: toggled(state.tags, t) })))}
            </fieldset>
          )}
        </div>
      )}
    </div>
  );
}
