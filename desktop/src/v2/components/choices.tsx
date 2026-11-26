import { useId, type ComponentPropsWithRef, type MouseEvent, type ReactNode } from "react";
import { Icon } from "./Button";
import { CheckIcon } from "./icons";

export type ToggleProps = {
  checked: boolean;
  onChange: (checked: boolean) => void;
  /** Names what it controls ("Auto-transcribe after calls"), never just On/Off. */
  label: ReactNode;
  /** Keep the label for assistive tools only (the row beside it already shows the words). */
  hideLabel?: boolean;
  hint?: ReactNode;
  disabled?: boolean;
};

/** A switch that takes effect at once. */
export function Toggle({ checked, onChange, label, hideLabel, hint, disabled }: ToggleProps) {
  const id = useId();
  const sw = (
    <button type="button" role="switch" className="el-toggle" id={id} aria-checked={checked} disabled={disabled}
      aria-labelledby={`${id}-label`} aria-describedby={hint ? `${id}-hint` : undefined} onClick={() => onChange(!checked)} />
  );
  if (hideLabel) {
    // The row beside it shows the words; assistive tools still hear the name and, given one, the hint (D-267).
    return <>{sw}<span id={`${id}-label`} className="el-visually-hidden">{label}</span>{hint && <span id={`${id}-hint`} className="el-visually-hidden">{hint}</span>}</>;
  }
  return (
    <span className="el-check-row">
      <span className="el-check-row__text">
        <label id={`${id}-label`} htmlFor={id}>{label}</label>
        {hint && <span className="el-caption" id={`${id}-hint`}>{hint}</span>}
      </span>
      {sw}
    </span>
  );
}

type BoxProps = Omit<ComponentPropsWithRef<"input">, "type"> & { label: ReactNode; hint?: ReactNode };

/** A checkbox with its label; for choices that a Save button applies. */
export function Checkbox({ label, hint, id, ...rest }: BoxProps) {
  const auto = useId();
  const inputId = id ?? auto;
  return (
    <label className="el-check-row" htmlFor={inputId}>
      <span className="el-check-box">
        <input type="checkbox" className="el-check" id={inputId} aria-labelledby={`${inputId}-label`} aria-describedby={hint ? `${inputId}-hint` : undefined} {...rest} />
        <Icon icon={CheckIcon} />
      </span>
      <span className="el-check-row__text">
        <span id={`${inputId}-label`}>{label}</span>
        {hint && <span className="el-caption" id={`${inputId}-hint`}>{hint}</span>}
      </span>
    </label>
  );
}

/** One radio; use inside RadioGroup. */
export function Radio({ label, hint, id, ...rest }: BoxProps) {
  const auto = useId();
  const inputId = id ?? auto;
  return (
    <label className="el-check-row" htmlFor={inputId}>
      <input type="radio" className="el-check el-check--radio" id={inputId} aria-labelledby={`${inputId}-label`} aria-describedby={hint ? `${inputId}-hint` : undefined} {...rest} />
      <span className="el-check-row__text">
        <span id={`${inputId}-label`}>{label}</span>
        {hint && <span className="el-caption" id={`${inputId}-hint`}>{hint}</span>}
      </span>
    </label>
  );
}

/** Radios with a legend; arrow keys move between them (the browser's own radio behaviour). */
export function RadioGroup<T extends string>({ legend, name, value, options, onChange }: {
  legend: ReactNode;
  name: string;
  value: T;
  options: { value: T; label: ReactNode; hint?: ReactNode }[];
  onChange: (value: T) => void;
}) {
  return (
    <fieldset className="el-radio-group">
      <legend>{legend}</legend>
      {options.map((o) => (
        <Radio key={o.value} name={name} value={o.value} label={o.label} hint={o.hint} checked={o.value === value}
          onChange={() => onChange(o.value)} />
      ))}
    </fieldset>
  );
}

/**
 * A bare checkbox with its tick (a row's pick, Select all): named by `label`, no words beside it. `mixed`: some of what
 * it stands for are picked (a dash, aria-checked="mixed"). `onClick` gets the event (Shift picks a range).
 */
export function PickBox({ checked, mixed = false, label, onClick, className, keyshortcuts }: {
  checked: boolean; mixed?: boolean; label: string; onClick: (e: MouseEvent<HTMLInputElement>) => void; className?: string; keyshortcuts?: string;
}) {
  return (
    <span className={["el-check-box", className].filter(Boolean).join(" ")}>
      <input type="checkbox" className="el-check" aria-label={label} checked={checked} aria-keyshortcuts={keyshortcuts}
        ref={(el) => { if (el) el.indeterminate = mixed && !checked; }} onChange={() => undefined} onClick={onClick} />
      <Icon icon={CheckIcon} />
    </span>
  );
}
