// Classic's kit for the account flows (AccountFlows.tsx): its Sheet, buttons, fields and checkbox.
import { useId } from "react";
import type { AccountKit, KitButtonProps, KitDialogProps, KitFieldProps } from "./AccountFlows";
import { Sheet } from "./ProjectDialogs";

function Dialog({ title, description, onClose, busy, children, actions }: KitDialogProps) {
  return (
    <Sheet title={title} onClose={onClose} busy={busy} className="account-sheet">
      {description && <p>{description}</p>}
      {children}
      <div className="row sheet-actions"><span className="spacer" />{actions}</div>
    </Sheet>
  );
}

function Button({ kind = "secondary", busy, busyLabel, disabled, type = "button", form, label, onClick, children }: KitButtonProps) {
  const cls = kind === "primary" ? "btn primary" : kind === "danger" ? "btn danger solid" : kind === "quiet" ? "btn text compact" : "btn ghost";
  return (
    <button type={type} form={form} className={cls} disabled={disabled || busy} aria-busy={busy || undefined} aria-label={label} onClick={onClick}>
      {busy && busyLabel ? busyLabel : children}
    </button>
  );
}

function Field({ label, value, onChange, type = "text", autoComplete, inputMode, error, hint, autoFocus, maxLength }: KitFieldProps) {
  const id = useId();
  const described = [error && `${id}-error`, hint && `${id}-hint`].filter(Boolean).join(" ") || undefined;
  return (
    <div className="column account-field">
      <label htmlFor={id} className="small muted">{label}</label>
      <input id={id} className="input" type={type} dir="auto" value={value} onChange={(e) => onChange(e.target.value)} autoComplete={autoComplete}
        inputMode={inputMode} maxLength={maxLength} autoFocus={autoFocus} aria-invalid={error ? true : undefined} aria-describedby={described} />
      {error && <p id={`${id}-error`} className="small error-text">{error}</p>}
      {hint && <p id={`${id}-hint`} className="small muted">{hint}</p>}
    </div>
  );
}

/** A checkbox named by its label alone; the hint is read after it, as the new design's Checkbox does. */
function Check({ label, hint, checked, onChange }: { label: string; hint?: string; checked: boolean; onChange: (on: boolean) => void }) {
  const id = useId();
  return (
    <div className="column account-field">
      <label className="check-row">
        <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} aria-describedby={hint ? `${id}-hint` : undefined} />
        <span>{label}</span>
      </label>
      {hint && <p id={`${id}-hint`} className="small muted">{hint}</p>}
    </div>
  );
}

export const CLASSIC_KIT: AccountKit = {
  Dialog,
  Button,
  Field,
  Check,
  Problem: ({ children }) => <p className="small error-text" role="alert">{children}</p>,
};
