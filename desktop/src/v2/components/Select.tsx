import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { Icon } from "./Button";
import { CheckIcon, ChevronDownIcon } from "./icons";

/** `dir` for labels with no letters ("1.5×"), which otherwise follow the page's direction. */
export type SelectOption = { value: string; label: string; dir?: "ltr" | "rtl" };

export type SelectProps = {
  /** The visible label above the control, or, with `hideLabel`, only its accessible name. */
  label: ReactNode;
  hideLabel?: boolean;
  options: SelectOption[];
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  className?: string;
};

/**
 * A select-only combobox: one press anywhere on the control opens the list (not only the arrow). Arrow keys, Home and
 * End move, Enter or Space picks, Esc closes without a change, typing a letter jumps to the next match. Focus stays on
 * the control; the highlighted option is its active descendant.
 */
export function Select({ label, hideLabel, options, value, onChange, disabled, className }: SelectProps) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const selected = Math.max(0, options.findIndex((o) => o.value === value));
  const [active, setActive] = useState(selected);
  const root = useRef<HTMLDivElement>(null);
  const control = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) return;
    const away = (e: PointerEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("pointerdown", away);
    return () => document.removeEventListener("pointerdown", away);
  }, [open]);

  useEffect(() => {
    if (open) document.getElementById(`${id}-opt-${active}`)?.scrollIntoView?.({ block: "nearest" });
  }, [open, active, id]);

  const show = (at = selected) => {
    setActive(at);
    setOpen(true);
  };
  const pick = (index: number) => {
    const option = options[index];
    if (option && option.value !== value) onChange(option.value);
    setOpen(false);
    control.current?.focus();
  };

  const keyDown = (e: KeyboardEvent<HTMLButtonElement>) => {
    const last = options.length - 1;
    if (!open) {
      if (["ArrowDown", "ArrowUp", "Enter", " "].includes(e.key)) {
        e.preventDefault();
        show(e.key === "ArrowUp" ? Math.max(0, selected - 1) : selected);
      }
      return;
    }
    switch (e.key) {
      case "ArrowDown": e.preventDefault(); setActive((a) => Math.min(last, a + 1)); break;
      case "ArrowUp": e.preventDefault(); setActive((a) => Math.max(0, a - 1)); break;
      case "Home": e.preventDefault(); setActive(0); break;
      case "End": e.preventDefault(); setActive(last); break;
      case "Enter":
      case " ": e.preventDefault(); pick(active); break;
      case "Escape": e.preventDefault(); e.stopPropagation(); setOpen(false); break;
      case "Tab": pick(active); break;
      default:
        if (e.key.length === 1) {
          const from = (i: number) => (active + 1 + i) % options.length;
          const hit = options.findIndex((_, i) => options[from(i)].label.toLocaleLowerCase().startsWith(e.key.toLocaleLowerCase()));
          if (hit >= 0) setActive(from(hit));
        }
    }
  };

  return (
    <div className={["el-field", "el-select", className].filter(Boolean).join(" ")} ref={root}>
      <span id={`${id}-label`} className={hideLabel ? "el-visually-hidden" : "el-field__label"}>{label}</span>
      <button ref={control} type="button" className="el-select__control" role="combobox" disabled={disabled}
        aria-haspopup="listbox" aria-expanded={open} aria-controls={`${id}-list`} aria-labelledby={`${id}-label ${id}-value`}
        aria-activedescendant={open ? `${id}-opt-${active}` : undefined}
        onClick={() => (open ? setOpen(false) : show())} onKeyDown={keyDown}>
        <span className="el-select__value" id={`${id}-value`} dir={options[selected]?.dir ?? "auto"}>{options[selected]?.label}</span>
        <Icon icon={ChevronDownIcon} />
      </button>
      {open && (
        <ul className="el-menu" role="listbox" id={`${id}-list`} aria-labelledby={`${id}-label`} tabIndex={-1}>
          {options.map((o, i) => (
            <li key={o.value} id={`${id}-opt-${i}`} role="option" className="el-menu__option" aria-selected={i === selected}
              data-active={i === active || undefined} dir={o.dir ?? "auto"}
              onMouseDown={(e) => e.preventDefault()} onMouseEnter={() => setActive(i)} onClick={() => pick(i)}>
              <span className="el-menu__check">{i === selected && <Icon icon={CheckIcon} />}</span>
              {o.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
