import { useId, useRef, type ComponentPropsWithRef, type KeyboardEvent, type ReactNode } from "react";
import { Button, Icon } from "./Button";
import { CloseIcon, ErrorIcon, SearchIcon } from "./icons";

type InputProps = Omit<ComponentPropsWithRef<"input">, "size">;

export type TextFieldProps = InputProps & {
  /** The visible label; every field has one. */
  label: ReactNode;
  hint?: ReactNode;
  /** An error sentence: the field turns invalid and the sentence is read with it. */
  error?: ReactNode;
};

/** A labelled text input with an optional hint and error. */
export function TextField({ label, hint, error, id, className, ...rest }: TextFieldProps) {
  const auto = useId();
  const inputId = id ?? auto;
  const hintId = hint ? `${inputId}-hint` : undefined;
  const errorId = error ? `${inputId}-error` : undefined;
  const describedBy = [errorId, hintId].filter(Boolean).join(" ") || undefined;
  return (
    <div className={["el-field", className].filter(Boolean).join(" ")}>
      <label className="el-field__label" htmlFor={inputId}>{label}</label>
      <div className="el-input" data-invalid={error ? "true" : undefined}>
        <input id={inputId} aria-invalid={error ? true : undefined} aria-describedby={describedBy} dir="auto" {...rest} />
      </div>
      {error && <p className="el-field__error" id={errorId}><Icon icon={ErrorIcon} />{error}</p>}
      {hint && <p className="el-field__hint" id={hintId}>{hint}</p>}
    </div>
  );
}

export type SearchFieldProps = Omit<InputProps, "value" | "onChange" | "type"> & {
  /** The accessible name ("Search recordings"); shown as the placeholder unless one is given. */
  label: string;
  value: string;
  onChange: (value: string) => void;
  clearLabel?: string;
};

/** Search: a magnifier, the query, and a clear button once there is text. Esc clears, then leaves the field alone. */
export function SearchField({ label, value, onChange, clearLabel = "Clear search", placeholder, onKeyDown, className, ...rest }: SearchFieldProps) {
  const input = useRef<HTMLInputElement>(null);
  const keyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Escape" && value) {
      e.preventDefault();
      e.stopPropagation();
      onChange("");
    }
    onKeyDown?.(e);
  };
  return (
    <div className={["el-input", "el-search", className].filter(Boolean).join(" ")} role="search">
      <Icon icon={SearchIcon} />
      <input ref={input} type="search" aria-label={label} placeholder={placeholder ?? label} value={value} dir="auto"
        onChange={(e) => onChange(e.target.value)} onKeyDown={keyDown} {...rest} />
      {value && (
        <Button variant="quiet" size="sm" icon={CloseIcon} aria-label={clearLabel}
          onClick={() => { onChange(""); input.current?.focus(); }} />
      )}
    </div>
  );
}
