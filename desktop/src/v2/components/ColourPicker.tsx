import { useId, useRef, type KeyboardEvent } from "react";
import { Icon } from "./Button";
import { LABEL_COLOURS, type LabelColour } from "./Label";
import { CheckIcon } from "./icons";

/** The eight colours in the picker's order, which is also LABEL_COLOURS' order (grey first: the default). */
export const COLOUR_ORDER = Object.keys(LABEL_COLOURS) as LabelColour[];

/** Each colour's name for assistive tools and the tooltip, in English; a page in another language passes its own. */
export const COLOUR_NAMES: Readonly<Record<LabelColour, string>> = {
  grey: "Grey", red: "Red", orange: "Orange", amber: "Amber", green: "Green", teal: "Teal", blue: "Blue", pink: "Pink",
};

const rtl = (el: Element) => {
  const computed = typeof getComputedStyle === "function" ? getComputedStyle(el).direction : "";
  if (computed) return computed === "rtl";
  return el.closest('[dir="rtl"], [dir="ltr"]')?.getAttribute("dir") === "rtl";
};

export type ColourPickerProps = {
  /** The chosen colour, by name. */
  value: LabelColour;
  onChange: (colour: LabelColour) => void;
  /** The group's name ("Colour"), shown above the dots. */
  label: string;
  /** Keep the label for assistive tools only (a dialog whose title already says it: Change colour). */
  hideLabel?: boolean;
  names?: Readonly<Record<LabelColour, string>>;
};

/**
 * The eight label colours as one radio group: 32 px dots in 44 px targets, the chosen one ringed in the primary text
 * colour with a check. The group is one tab stop; the arrow keys move and choose (left and right follow the reading
 * direction, so ArrowLeft moves on in Urdu), and Home and End go to the first and last colour.
 */
export function ColourPicker({ value, onChange, label, hideLabel = false, names = COLOUR_NAMES }: ColourPickerProps) {
  const id = useId();
  const buttons = useRef<(HTMLButtonElement | null)[]>([]);
  const at = Math.max(0, COLOUR_ORDER.indexOf(value));

  const go = (to: number) => {
    const n = COLOUR_ORDER.length;
    const i = ((to % n) + n) % n;
    onChange(COLOUR_ORDER[i]);
    buttons.current[i]?.focus();
  };
  const keyDown = (e: KeyboardEvent<HTMLButtonElement>, i: number) => {
    const back = rtl(e.currentTarget);
    const moves: Record<string, number> = {
      ArrowRight: back ? i - 1 : i + 1,
      ArrowLeft: back ? i + 1 : i - 1,
      ArrowDown: i + 1,
      ArrowUp: i - 1,
      Home: 0,
      End: COLOUR_ORDER.length - 1,
    };
    if (!(e.key in moves)) return;
    e.preventDefault();
    go(moves[e.key]);
  };

  return (
    <div className="el-colour-picker">
      <span id={`${id}-label`} className={hideLabel ? "el-visually-hidden" : "el-field__label"}>{label}</span>
      <div className="el-colour-picker__options" role="radiogroup" aria-labelledby={`${id}-label`}>
        {COLOUR_ORDER.map((colour, i) => {
          const on = i === at && colour === value;
          return (
            <button key={colour} type="button" role="radio" aria-checked={on} aria-label={names[colour]} title={names[colour]}
              tabIndex={i === at ? 0 : -1} className="el-colour-picker__option" ref={(el) => { buttons.current[i] = el; }}
              onClick={() => onChange(colour)} onKeyDown={(e) => keyDown(e, i)}>
              <span className={`el-label-dot el-label--${colour} el-colour-picker__dot`} aria-hidden="true">
                {on && <Icon icon={CheckIcon} />}
              </span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
