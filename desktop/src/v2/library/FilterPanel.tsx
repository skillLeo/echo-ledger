// The Library's filters (PLAN_LIBRARY_FILTERS §1): the panel (280 px on the start side of the list, closed by default,
// opened by [Filters n] or F, closed by Esc; from the end side, 320 px: from 1440 px it pushes the list, below it slides over it) with its sections, live counts,
// a search box in long sections, each section's Clear and Save as view at its foot; and the pills row under the
// toolbar, only while filters are on: one pill per section ("Source: WhatsApp, Zoom ×"), which opens to edit it with
// "is" or "is not", then Clear all and Save as view.
import { useEffect, useId, useLayoutEffect, useRef, useState, type KeyboardEvent, type ReactNode, type RefObject } from "react";
import { Button, Icon, Icons, ResizeEdge, SearchField, Segmented, useFocusTrap } from "../components";
import { Layer, placeMenu, regionOf } from "../components/Menu";
import {
  activeSections, clearSection, pillWords, SECTION_NAMES, SECTIONS, sectionOn, setDate, setNot, toggleValue,
  type DatePreset, type FacetOption, type FilterSpec, type Names, type SectionKey,
} from "./facets";

/** A section shows a search box from this many options. */
export const SEARCH_FROM = 8;
const FOLDED_KEY = "echoledger-library-filter-folds";

/** UX-03: the filters' width, set by their edge, from 280 to 420 px (320 usually), kept on this computer; let go below
 * 160 px they close. */
export const FILTERS_MIN = 280;
export const FILTERS_MAX = 420;
export const FILTERS_DEFAULT = 320;
export const FILTERS_CLOSE_BELOW = 160;
export const FILTERS_WIDTH_KEY = "echoledger-library-filters-width";
export function loadFiltersWidth(): number | null {
  try {
    const saved = Number(window.localStorage.getItem(FILTERS_WIDTH_KEY));
    return Number.isInteger(saved) && saved >= FILTERS_MIN && saved <= FILTERS_MAX ? saved : null;
  } catch {
    return null;
  }
}
function saveFiltersWidth(width: number | null) {
  try {
    if (width === null) window.localStorage.removeItem(FILTERS_WIDTH_KEY);
    else window.localStorage.setItem(FILTERS_WIDTH_KEY, String(width));
  } catch {
    // Kept for this session only.
  }
}

function loadFolds(): Set<SectionKey> {
  try {
    const saved: unknown = JSON.parse(window.localStorage.getItem(FOLDED_KEY) ?? "[]");
    return new Set(Array.isArray(saved) ? saved.filter((v): v is SectionKey => SECTIONS.includes(v as SectionKey)) : []);
  } catch {
    return new Set();
  }
}

/** One section's choices: Date's one choice (Custom with its two days), or the values ticked, each with its count. */
function SectionBody({ section, spec, onSpec, options, search = true }: {
  section: SectionKey; spec: FilterSpec; onSpec: (spec: FilterSpec) => void; options: readonly FacetOption[]; search?: boolean;
}) {
  const [find, setFind] = useState("");
  const name = useId();
  if (section === "date") {
    const chosen = spec.date?.values[0] ?? null;
    const pick = (preset: DatePreset | null, days: { from?: string; to?: string } = {}) => onSpec(setDate(spec, preset, days));
    return (
      <div className="el-filter__options" role="radiogroup" aria-label={SECTION_NAMES.date}>
        {[{ value: null, label: "Any time", count: null }, ...options].map((o) => (
          <label key={o.value ?? "any"} className={`el-filter__option${o.count === 0 && o.value !== "custom" ? " el-filter__option--none" : ""}`}>
            <input type="radio" name={name} checked={chosen === o.value} onChange={() => pick(o.value as DatePreset | null, { from: spec.date?.from, to: spec.date?.to })} />
            <span className="el-filter__label" dir="auto">{o.label}</span>
            {o.count !== null && o.value !== "custom" && <span className="el-filter__count el-caption el-num">{o.count}</span>}
          </label>
        ))}
        {chosen === "custom" && (
          <div className="el-filter__range">
            <label className="el-filter__day"><span className="el-caption">From</span>
              <input type="date" className="el-input el-filter__date" value={spec.date?.from ?? ""} max={spec.date?.to || undefined}
                onChange={(e) => pick("custom", { from: e.target.value || undefined, to: spec.date?.to })} />
            </label>
            <label className="el-filter__day"><span className="el-caption">To</span>
              <input type="date" className="el-input el-filter__date" value={spec.date?.to ?? ""} min={spec.date?.from || undefined}
                onChange={(e) => pick("custom", { from: spec.date?.from, to: e.target.value || undefined })} />
            </label>
          </div>
        )}
      </div>
    );
  }
  const values = spec[section]?.values ?? [];
  const q = find.trim().toLowerCase();
  const shown = q ? options.filter((o) => o.label.toLowerCase().includes(q)) : options;
  return (
    <div className="el-filter__options">
      {search && options.length >= SEARCH_FROM && (
        <SearchField label={`Search ${SECTION_NAMES[section].toLowerCase()}`} value={find} onChange={setFind} className="el-filter__search" />
      )}
      {options.length === 0 && <p className="el-caption el-secondary">None yet</p>}
      {q && shown.length === 0 && <p className="el-caption el-secondary">No match</p>}
      {shown.map((o) => (
        // An option at 0 is dimmed, never hidden or disabled: it can still be ticked (for "is not").
        <label key={o.value} className={`el-filter__option${o.count === 0 ? " el-filter__option--none" : ""}`}>
          <input type="checkbox" checked={values.includes(o.value)} onChange={() => onSpec(toggleValue(spec, section, o.value))} />
          <span className="el-filter__label" dir="auto">{o.label}</span>
          <span className="el-filter__count el-caption el-num">{o.count}</span>
        </label>
      ))}
    </div>
  );
}

type FilterPanelProps = {
  spec: FilterSpec;
  onSpec: (spec: FilterSpec) => void;
  counts: Record<SectionKey, FacetOption[]>;
  /** Under 1440 px: a drawer over the list (with its scrim), not a column beside it. */
  drawer: boolean;
  onClose: () => void;
  onSaveView: () => void;
  canSave: boolean;
};

/** The filter panel: a column at the list's end, or under 1440 px a modal drawer over it (FilterDrawer). */
export function FilterPanel(props: FilterPanelProps) {
  return props.drawer ? <FilterDrawer {...props} /> : <FilterAside {...props} />;
}

/**
 * The drawer: modal while open. Focus moves in, Tab and Shift+Tab stay inside, and focus goes back where it was when
 * it closes; the Library's keys (⌘F, F) leave the list behind it alone meanwhile.
 */
function FilterDrawer(props: FilterPanelProps) {
  const panel = useRef<HTMLElement>(null);
  const trap = useFocusTrap(panel);
  return (
    <>
      <div className="el-filter-scrim" aria-hidden="true" onMouseDown={props.onClose} />
      <FilterAside {...props} panel={panel} onTab={trap} />
    </>
  );
}

function FilterAside({ spec, onSpec, counts, drawer, onClose, onSaveView, canSave, panel, onTab }: FilterPanelProps & {
  panel?: RefObject<HTMLElement | null>;
  onTab?: (e: KeyboardEvent<HTMLElement>) => void;
}) {
  const [folded, setFolded] = useState<Set<SectionKey>>(loadFolds);
  const [width, setWidthNow] = useState<number | null>(loadFiltersWidth);
  const setWidth = (w: number | null) => {
    setWidthNow(w);
    saveFiltersWidth(w);
  };
  const fold = (s: SectionKey) => setFolded((now) => {
    const next = new Set(now);
    if (next.has(s)) next.delete(s);
    else next.add(s);
    try {
      window.localStorage.setItem(FOLDED_KEY, JSON.stringify([...next]));
    } catch {
      // Folded for this session only.
    }
    return next;
  });
  const keyDown = (e: KeyboardEvent<HTMLElement>) => {
    onTab?.(e);
    if (e.key !== "Escape" || e.defaultPrevented) return;
    e.preventDefault();
    e.stopPropagation();
    onClose();
  };
  const sections = SECTIONS;
  const on = activeSections(spec).length;
  const body = (
    <aside ref={panel} className={`el-filter${drawer ? " el-filter--drawer" : ""}`} aria-label="Filters" onKeyDown={keyDown} data-testid="filter-panel"
      role={drawer ? "dialog" : undefined} aria-modal={drawer ? true : undefined}
      style={width ? { ["--el-filter-width" as string]: `${width}px` } : undefined}>
      <div className="el-filter__head">
        <h2 className="el-body-strong">Filters{on > 0 && <span className="el-filter__on el-caption"><span className="el-num">{on}</span></span>}</h2>
        <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Close filters"
          aria-keyshortcuts={drawer ? "Escape" : "Escape F"} onClick={onClose} data-close="" />
      </div>
      <div className="el-filter__sections">
        {sections.map((s) => {
          const open = !folded.has(s);
          const active = sectionOn(spec, s);
          return (
            <section key={s} className="el-filter__section" aria-label={SECTION_NAMES[s]}>
              <div className="el-filter__section-head">
                <button type="button" className="el-filter__title" aria-expanded={open} onClick={() => fold(s)}>
                  <Icon icon={open ? Icons.ChevronDownIcon : Icons.ChevronEndIcon} directional />
                  <span>{SECTION_NAMES[s]}</span>
                  {active && <span className="el-filter__dot" aria-label="on" role="img" />}
                </button>
                {active && <Button size="sm" variant="quiet" aria-label={`Clear ${SECTION_NAMES[s]}`} onClick={() => onSpec(clearSection(spec, s))}>Clear</Button>}
              </div>
              {open && <SectionBody section={s} spec={spec} onSpec={onSpec} options={counts[s]} />}
            </section>
          );
        })}
      </div>
      <div className="el-filter__foot">
        <Button className="el-filter__save" icon={Icons.PlusIcon} disabled={!canSave} onClick={onSaveView}>Save as view</Button>
      </div>
      {/* At the page's end, so its edge is the start one; let go below 160 px the filters close. */}
      <ResizeEdge label="Resize the filters" side="start" cssVar="--el-filter-width" width={width ?? FILTERS_DEFAULT}
        min={FILTERS_MIN} max={FILTERS_MAX} closeBelow={FILTERS_CLOSE_BELOW} onWidth={setWidth} onReset={() => setWidth(null)} onClose={onClose} />
    </aside>
  );
  return body;
}

/** The pills row: one pill per section that is on, then Clear all and Save as view (and Save changes for a view). */
export function FilterPills({ spec, onSpec, counts, names, onClearAll, onSaveView, onSaveChanges, extra }: {
  spec: FilterSpec;
  onSpec: (spec: FilterSpec) => void;
  /** The options' counts, asked for only while a pill is being edited. */
  counts: () => Record<SectionKey, FacetOption[]>;
  names: Names;
  onClearAll: () => void;
  onSaveView: () => void;
  /** A saved view is open and changed: save it again. */
  onSaveChanges?: () => void;
  /** A pill of its own before the filters' (the search, a label shown). */
  extra?: ReactNode;
}) {
  const [editing, setEditing] = useState<SectionKey | null>(null);
  const anchors = useRef(new Map<SectionKey, HTMLElement>());
  const sections = activeSections(spec);
  return (
    <div className="el-fpills" role="group" aria-label="Filters on">
      {extra}
      {sections.map((s) => {
        const words = pillWords(spec, s, names);
        return (
          <span key={s} className="el-fpill" ref={(el) => { if (el) anchors.current.set(s, el); else anchors.current.delete(s); }}>
            <button type="button" className="el-fpill__edit" aria-haspopup="dialog" aria-expanded={editing === s} title={`Edit: ${words}`}
              onClick={() => setEditing(editing === s ? null : s)}><bdi>{words}</bdi></button>
            <button type="button" className="el-fpill__remove" aria-label={`Remove ${words}`} onClick={() => onSpec(clearSection(spec, s))}>
              <Icon icon={Icons.CloseIcon} />
            </button>
          </span>
        );
      })}
      <Button size="sm" variant="quiet" onClick={onClearAll}>Clear all</Button>
      {onSaveChanges && <Button size="sm" variant="quiet" onClick={onSaveChanges}>Save changes</Button>}
      <Button size="sm" variant="quiet" icon={Icons.PlusIcon} onClick={onSaveView}>Save as view</Button>
      {editing && sectionOn(spec, editing) && (
        <PillEditor section={editing} spec={spec} onSpec={onSpec} options={counts()[editing]} anchor={anchors.current.get(editing) ?? null}
          onClose={(focus) => {
            const pill = anchors.current.get(editing);
            setEditing(null);
            if (focus) pill?.querySelector<HTMLElement>(".el-fpill__edit")?.focus();
          }} />
      )}
    </div>
  );
}

/**
 * A pill opened: "is" or "is not" (Date too) and the section's choices with their counts, in a small panel
 * floating under the pill (above the page, never cut off); Esc, Done or a press outside closes it.
 */
function PillEditor({ section, spec, onSpec, options, anchor, onClose }: {
  section: SectionKey; spec: FilterSpec; onSpec: (spec: FilterSpec) => void; options: readonly FacetOption[]; anchor: HTMLElement | null;
  onClose: (focus: boolean) => void;
}) {
  const box = useRef<HTMLDivElement>(null);
  const [at, setAt] = useState<{ left: number; top: number } | null>(null);
  useLayoutEffect(() => {
    const el = box.current;
    if (!el || !anchor) return;
    const size = el.getBoundingClientRect();
    const rtl = getComputedStyle(anchor).direction === "rtl";
    const place = placeMenu({ anchor: anchor.getBoundingClientRect(), size: { width: size.width, height: size.height }, region: regionOf(anchor),
      view: { width: window.innerWidth, height: window.innerHeight }, align: "start", rtl });
    setAt({ left: place.left, top: place.top });
    el.querySelector<HTMLElement>("input, button")?.focus();
  }, [anchor]);
  useEffect(() => {
    const away = (e: PointerEvent) => {
      if (!box.current?.contains(e.target as Node) && !anchor?.contains(e.target as Node)) onClose(false);
    };
    document.addEventListener("pointerdown", away);
    return () => document.removeEventListener("pointerdown", away);
  }, [anchor, onClose]);
  // "is not" for every section, Date too (Date is not This month).
  const not = spec[section]?.op === "is_not";
  return (
    <Layer from={anchor}>
      <div ref={box} className="el-fpill-editor el-menu el-menu--floating" role="dialog" aria-label={`Edit ${SECTION_NAMES[section]}`} style={at ?? { left: 0, top: 0, visibility: "hidden" }}
        onKeyDown={(e) => { if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); onClose(true); } }}>
        <Segmented label={`${SECTION_NAMES[section]} is or is not`} value={not ? "not" : "is"}
          options={[{ value: "is", label: "is" }, { value: "not", label: "is not" }]} onChange={(v) => onSpec(setNot(spec, section, v === "not"))} />
        <SectionBody section={section} spec={spec} onSpec={onSpec} options={options} />
        <div className="el-fpill-editor__foot"><Button size="sm" onClick={() => onClose(true)}>Done</Button></div>
      </div>
    </Layer>
  );
}

