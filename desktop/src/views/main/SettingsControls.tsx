import { useId } from "react";

/** A labelled on/off switch row in a settings card. */
export function Toggle({ on, onChange, label, hint }: { on: boolean; onChange: (v: boolean) => void; label: string; hint?: string }) {
  const hintId = useId();
  return (
    <div className="toggle">
      <div><div>{label}</div>{hint && <div id={hintId} className="small muted">{hint}</div>}</div>
      <button type="button" className={`switch ${on ? "on" : ""}`} role="switch" aria-checked={on} aria-label={label} aria-describedby={hint ? hintId : undefined} onClick={() => onChange(!on)} />
    </div>
  );
}

/** A labelled select row in a settings card. */
export function SelectRow<T extends string>({ label, value, options, onChange, width = 220 }: {
  label: string;
  value: T;
  options: readonly (readonly [T, string])[];
  onChange: (value: T) => void;
  width?: number;
}) {
  const id = useId();
  return (
    <div className="toggle">
      <label htmlFor={id}>{label}</label>
      <select id={id} className="input" style={{ width }} value={value} onChange={(e) => onChange(e.target.value as T)}>
        {options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
      </select>
    </div>
  );
}
