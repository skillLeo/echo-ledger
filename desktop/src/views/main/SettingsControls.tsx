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

/** A labelled select row in a settings card, with an optional line under the label. */
export function SelectRow<T extends string>({ label, value, options, onChange, width = 220, hint }: {
  label: string;
  value: T;
  options: readonly (readonly [T, string])[];
  onChange: (value: T) => void;
  width?: number;
  hint?: string;
}) {
  const id = useId();
  const hintId = useId();
  const select = (
    <select id={id} className="input" style={{ width }} value={value} aria-describedby={hint ? hintId : undefined} onChange={(e) => onChange(e.target.value as T)}>
      {options.map(([v, text]) => <option key={v} value={v}>{text}</option>)}
    </select>
  );
  return (
    <div className="toggle">
      {hint ? <div><label htmlFor={id}>{label}</label><div id={hintId} className="small muted">{hint}</div></div> : <label htmlFor={id}>{label}</label>}
      {select}
    </div>
  );
}
