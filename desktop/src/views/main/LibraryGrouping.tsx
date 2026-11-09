// The Library's Group by and Sort by menus and its group headers (Phase 1C, D): toolbar menus like Explorer's and
// Finder's, headers with the group's name and count that collapse and stay at the top while their group scrolls, and a
// box on each header that picks the whole group for the bulk actions (ticked, partly ticked or empty).
import { useEffect, useRef, useState } from "react";
import type { LibraryItem } from "../../lib/ipc";
import { groupPick } from "../../lib/libraryGroups";
import { CheckIcon, ChevronDownIcon } from "../../components/ControlIcons";

/** A toolbar menu of choices, one of them ticked ("Group by: Date ▾"). */
export function ChoiceMenu<T extends string>({ label, value, options, onChange }: {
  label: string;
  value: T;
  options: readonly (readonly [T, string])[];
  onChange: (value: T) => void;
}) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    if (!open) return;
    const outside = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    };
    const escape = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", outside);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("mousedown", outside);
      document.removeEventListener("keydown", escape);
    };
  }, [open]);
  const current = options.find(([v]) => v === value)?.[1] ?? value;
  return (
    <div className="menu-button choice-menu" ref={box}>
      <button type="button" className="chip" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen(!open)}>
        {label}: {current}<ChevronDownIcon size={14} />
      </button>
      {open && (
        <div className="menu-pop card" role="menu" aria-label={label}>
          {options.map(([v, text]) => (
            <button key={v} type="button" role="menuitemradio" aria-checked={v === value} className="btn ghost small choice-item"
              onClick={() => { setOpen(false); onChange(v); }}>
              <span className="choice-tick" aria-hidden="true">{v === value && <CheckIcon size={14} />}</span>{text}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * A group's header (P5.1): two controls that never do each other's job. The box only selects (none, some or all of the
 * group's recordings, tri-state); the chevron, the name and the count only fold and unfold the group. The box sits in
 * its own label, a target big enough that a click meant for it never lands on the name, and its clicks stop there.
 */
export function GroupHeader({ label, items, picked, collapsed, onToggle, onPick }: {
  label: string;
  items: readonly LibraryItem[];
  picked: ReadonlySet<string>;
  collapsed: boolean;
  onToggle: () => void;
  onPick: () => void;
}) {
  const state = groupPick(items, picked);
  const boxRef = useRef<HTMLInputElement | null>(null);
  useEffect(() => {
    if (boxRef.current) boxRef.current.indeterminate = state === "some";
  }, [state]);
  const count = `${items.length} recording${items.length === 1 ? "" : "s"}`;
  return (
    <div className="group-head" data-testid="group-head">
      <label className="group-pick" title={state === "all" ? `Clear ${label}` : `Select all in ${label}`} onClick={(e) => e.stopPropagation()}>
        <input ref={boxRef} type="checkbox" className="group-box" checked={state === "all"} aria-checked={state === "some" ? "mixed" : state === "all"}
          aria-label={`Select all in ${label}`} onChange={onPick} onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()} />
      </label>
      <button type="button" className="group-toggle" aria-expanded={!collapsed} title={collapsed ? `Show ${label}` : `Fold ${label}`} onClick={onToggle}>
        <span className={`group-caret ${collapsed ? "collapsed" : ""}`} aria-hidden="true"><ChevronDownIcon size={14} /></span>
        <span className="group-label">{label}</span>
        <span className="group-count" aria-label={count}>{items.length}</span>
      </button>
    </div>
  );
}
