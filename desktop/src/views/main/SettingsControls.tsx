// The rows every Settings page is made of (v1.7): one component per job, each carrying its registry id
// (`id="setting-{id}"`, so search can scroll to it and highlight it) and reading its title from the registry, so a title
// is never written twice. Sections are one container with a sentence-case header and an optional footer; rows show
// their state at the right; explanations go in the footer, never under a row.
import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { titleOf } from "../../lib/settingsRegistry";
import { reducedMotion } from "../../lib/motion";
import { LucideChevronRightIcon, LucideExternalLinkIcon } from "../../components/Icons";
import { Sheet } from "./ProjectDialogs";

/** The attributes that make a row a search target. */
export function rowAttrs(id: string): { id: string; "data-setting": string; tabIndex: number } {
  return { id: `setting-${id}`, "data-setting": id, tabIndex: -1 };
}

/** One section: an optional header, the rows in one container, and the footer lines under it. */
export function Section({ title, footer, children, className = "" }: { title?: string; footer?: ReactNode; children: ReactNode; className?: string }) {
  const headId = useId();
  return (
    <section className={`set-section ${className}`.trim()} aria-labelledby={title ? headId : undefined}>
      {title && <h3 id={headId} className="set-head">{title}</h3>}
      <div className="set-group">{children}</div>
      {footer && <div className="set-foot">{footer}</div>}
    </section>
  );
}

/** A neutral tag: a plan's name ("Pro") or a stage ("In review"), with no colour, glyph or capitals. */
export function Tag({ children, tone }: { children: ReactNode; tone?: "warning" | "success" }) {
  return <span className={`set-tag ${tone ?? ""}`.trim()}>{children}</span>;
}

/** A switch row: the whole row toggles. `locked` replaces the switch with the plan's tag and opens the upgrade flow. */
export function SwitchRow({ id, title = titleOf(id), on, onChange, disabled = false, locked = null, label }: {
  id: string;
  title?: string;
  on: boolean;
  onChange: (on: boolean) => void;
  disabled?: boolean;
  locked?: { tag: string; onOpen: () => void } | null;
  /** The switch's name when it differs from the title ("Auto-record WhatsApp"). */
  label?: string;
}) {
  if (locked) {
    return (
      <button type="button" className="set-row set-link" {...rowAttrs(id)} onClick={locked.onOpen} aria-label={`${title}, in ${locked.tag}`}>
        <span className="set-title">{title}</span>
        <span className="set-end"><Tag>{locked.tag}</Tag></span>
      </button>
    );
  }
  return (
    <div className={`set-row ${disabled ? "disabled" : ""}`.trim()} {...rowAttrs(id)}>
      <label className="set-hit">
        <span className="set-title">{title}</span>
        <button type="button" className={`switch ${on ? "on" : ""}`} role="switch" aria-checked={on} aria-label={label ?? title} disabled={disabled}
          onClick={() => onChange(!on)} />
      </label>
    </div>
  );
}

/** A choice from a short list, as a select at the right (styled, with a drawn chevron). */
export function SelectRow<T extends string>({ id, title = titleOf(id), value, options, onChange, disabled = false, width }: {
  id: string;
  title?: string;
  value: T;
  options: readonly (readonly [T, string])[];
  onChange: (value: T) => void;
  disabled?: boolean;
  width?: number;
}) {
  const selectId = useId();
  return (
    <div className={`set-row ${disabled ? "disabled" : ""}`.trim()} {...rowAttrs(id)}>
      <label htmlFor={selectId} className="set-title">{title}</label>
      <select id={selectId} className="input set-select" style={width ? { width } : undefined} value={value} disabled={disabled} onChange={(e) => onChange(e.target.value as T)}>
        {options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
      </select>
    </div>
  );
}

/** Two to four short options side by side: a radio group with one focus stop per option and arrow keys. */
export function SegmentedRow<T extends string>({ id, title = titleOf(id), value, options, onChange, disabled = false }: {
  id: string;
  title?: string;
  value: T;
  options: readonly (readonly [T, string])[];
  onChange: (value: T) => void;
  disabled?: boolean;
}) {
  const labelId = useId();
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  const index = Math.max(0, options.findIndex(([v]) => v === value));
  const move = (to: number) => {
    const next = (to + options.length) % options.length;
    onChange(options[next][0]);
    refs.current[next]?.focus();
  };
  return (
    <div className={`set-row ${disabled ? "disabled" : ""}`.trim()} {...rowAttrs(id)}>
      <span id={labelId} className="set-title">{title}</span>
      <span className="segmented set-segmented" role="radiogroup" aria-labelledby={labelId}>
        {options.map(([v, text], i) => (
          <button key={v} ref={(el) => { refs.current[i] = el; }} type="button" role="radio" aria-checked={v === value} tabIndex={i === index ? 0 : -1} disabled={disabled}
            onClick={() => onChange(v)}
            onKeyDown={(e) => {
              if (e.key === "ArrowRight" || e.key === "ArrowDown") { e.preventDefault(); move(i + 1); }
              if (e.key === "ArrowLeft" || e.key === "ArrowUp") { e.preventDefault(); move(i - 1); }
            }}>{text}</button>
        ))}
      </span>
    </div>
  );
}

/** A value on a scale: the value at the right of the title, the slider under it. */
export function SliderRow({ id, title = titleOf(id), value, min, max, step, format, onChange }: {
  id: string;
  title?: string;
  value: number;
  min: number;
  max: number;
  step: number;
  format: (v: number) => string;
  onChange: (v: number) => void;
}) {
  const inputId = useId();
  return (
    <div className="set-row set-slider" {...rowAttrs(id)}>
      <span className="set-line"><label htmlFor={inputId} className="set-title">{title}</label><span className="set-value">{format(value)}</span></span>
      <input id={inputId} type="range" min={min} max={max} step={step} value={value} onChange={(e) => onChange(Number(e.target.value))} />
    </div>
  );
}

/** A row that opens a page, a sub-page or a website. The value is what it holds now; external links say so. */
export function LinkRow({ id, title = titleOf(id), value, onOpen, external = false, icon, chevron = false, disabled = false, label }: {
  id: string;
  title?: string;
  value?: ReactNode;
  onOpen: () => void;
  external?: boolean;
  icon?: ReactNode;
  chevron?: boolean;
  disabled?: boolean;
  label?: string;
}) {
  return (
    <button type="button" className={`set-row set-link ${icon ? "with-icon" : ""}`.trim()} {...rowAttrs(id)} onClick={onOpen} disabled={disabled}
      aria-label={label ?? (external ? `${title}, opens in browser` : undefined)}>
      {icon && <span className="set-icon" aria-hidden="true">{icon}</span>}
      <span className="set-title">{title}</span>
      <span className="set-end">
        {value !== undefined && value !== null && value !== "" && <span className="set-value">{value}</span>}
        {external && <LucideExternalLinkIcon size={18} className="set-trail" />}
        {chevron && !external && <LucideChevronRightIcon size={16} className="set-trail" />}
      </span>
    </button>
  );
}

/** A row that does something at once ("Lock now", "Export my data"): the title in the text colour, never a link colour. */
export function ActionRow({ id, title = titleOf(id), value, onClick, disabled = false, label }: {
  id: string;
  title?: string;
  value?: ReactNode;
  onClick: () => void;
  disabled?: boolean;
  label?: string;
}) {
  return (
    <button type="button" className="set-row set-link" {...rowAttrs(id)} onClick={onClick} disabled={disabled} aria-label={label}>
      <span className="set-title">{title}</span>
      {value !== undefined && value !== null && value !== "" && <span className="set-end"><span className="set-value">{value}</span></span>}
    </button>
  );
}

/** A destructive row: red title, no icon, and it always asks first (the caller's confirm). */
export function DestructiveRow({ id, title = titleOf(id), onClick, disabled = false }: { id: string; title?: string; onClick: () => void; disabled?: boolean }) {
  return (
    <button type="button" className="set-row set-link set-danger" {...rowAttrs(id)} onClick={onClick} disabled={disabled}>
      <span className="set-title">{title}</span>
    </button>
  );
}

/** A row that only tells: its title and value, with an optional control at the end ("Fix", "Copy"). */
export function InfoRow({ id, title = titleOf(id), value, children, tone }: {
  id: string;
  title?: string;
  value?: ReactNode;
  children?: ReactNode;
  tone?: "warning";
}) {
  return (
    <div className="set-row" {...rowAttrs(id)}>
      <span className="set-title">{title}</span>
      <span className="set-end">
        {value !== undefined && value !== null && value !== "" && <span className={`set-value ${tone ?? ""}`.trim()}>{value}</span>}
        {children}
      </span>
    </div>
  );
}

/** A thing in a list (a device, a call app, a rule, a receipt): its leading glyph, its name and a second line. */
export function EntityRow({ id, leading, title, sub, children, testId }: {
  id?: string;
  leading?: ReactNode;
  title: ReactNode;
  sub?: ReactNode;
  children?: ReactNode;
  testId?: string;
}) {
  return (
    <div className="set-row set-entity" {...(id ? rowAttrs(id) : {})} role="listitem" data-testid={testId}>
      {leading && <span className="set-icon" aria-hidden="true">{leading}</span>}
      <span className="set-main"><span className="set-title">{title}</span>{sub && <span className="set-sub">{sub}</span>}</span>
      {children && <span className="set-end">{children}</span>}
    </div>
  );
}

/** "Reset to defaults" at the end of a page: asks first, then resets only what that page holds. */
export function ResetRow({ page, onReset }: { page: string; onReset: () => void }) {
  return (
    <div className="set-reset">
      <button type="button" className="btn text" onClick={() => { if (window.confirm(`Reset ${page} to defaults?`)) onReset(); }}>Reset to defaults</button>
    </div>
  );
}

/** The highlight a search result leaves on its row: the brand's soft fill and ring, until the next click or key, 8 s at
 * most, then a 500 ms fade (none with reduced motion). The row gets the focus, so a screen reader reads it once. */
export function highlightSetting(id: string, root: ParentNode = document): boolean {
  const el = root.querySelector<HTMLElement>(`#${CSS.escape(`setting-${id}`)}`);
  if (!el) return false;
  const reduced = reducedMotion();
  el.scrollIntoView?.({ block: "center", behavior: reduced ? "auto" : "smooth" });
  el.focus?.({ preventScroll: true });
  document.querySelectorAll(".is-highlighted").forEach((other) => other.classList.remove("is-highlighted", "is-fading"));
  el.classList.add("is-highlighted");
  let done = false;
  const clear = () => {
    if (done) return;
    done = true;
    window.clearTimeout(timer);
    window.removeEventListener("pointerdown", clear, true);
    window.removeEventListener("keydown", clear, true);
    el.classList.add("is-fading");
    window.setTimeout(() => el.classList.remove("is-highlighted", "is-fading"), reduced ? 0 : 500);
  };
  const timer = window.setTimeout(clear, 8000);
  // The click or key that opened the result is still going; listen from the next one.
  window.setTimeout(() => {
    window.addEventListener("pointerdown", clear, true);
    window.addEventListener("keydown", clear, true);
  }, 0);
  return true;
}

/** A copy button: "Copy {what}", the icon turns into a tick for 2 s, and the page says "Copied". */
export function useCopied(): [string | null, (key: string, text: string) => Promise<void>] {
  const [copied, setCopied] = useState<string | null>(null);
  useEffect(() => {
    if (!copied) return;
    const timer = window.setTimeout(() => setCopied(null), 2000);
    return () => window.clearTimeout(timer);
  }, [copied]);
  const copy = async (key: string, text: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(key);
    } catch {
      setCopied(null);
    }
  };
  return [copied, copy];
}

/** A question before something that can't be undone: the title asks, one sentence says what follows, and the red
 * button repeats the verb ("Cancel request"). */
export function ConfirmDialog({ title, body, cancel = "Cancel", confirm, onConfirm, onClose, busy = false, error = null }: {
  title: string;
  body?: ReactNode;
  cancel?: string;
  confirm: string;
  onConfirm: () => void;
  onClose: () => void;
  busy?: boolean;
  error?: string | null;
}) {
  return (
    <Sheet title={title} onClose={onClose} busy={busy} className="confirm-sheet">
      {body && <p>{body}</p>}
      {error && <p className="small error-text" role="alert">{error}</p>}
      <div className="row sheet-actions">
        <span className="spacer" />
        <button type="button" className="btn ghost" onClick={onClose} disabled={busy}>{cancel}</button>
        <button type="button" className="btn danger solid" onClick={onConfirm} disabled={busy}>{confirm}</button>
      </div>
    </Sheet>
  );
}
