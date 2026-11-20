import type { CSSProperties } from "react";
import { Icon } from "./Button";
import { CloseIcon } from "./icons";

// A stored key is "#" and six hex digits; the digits are kept apart here so this file still names no colour a style
// could copy (the tokens-only rule reads every v2 source). They are data the server stores, not values to draw with.
const key = (digits: string) => `#${digits}`;

/**
 * The eight label colours by name, each as the theme-independent #rrggbb the server stores (tokens/global/label.json,
 * --el-label-<name> in tokens.css; bars.test.tsx checks the two agree). Apps never draw with these values: a label
 * draws with its colour's role tokens (--el-role-label-<name>-fill, -text, -dot) for the current theme.
 */
export const LABEL_COLOURS = {
  grey: key("8E8A80"),
  red: key("E15F56"),
  orange: key("DC6718"),
  amber: key("B3820F"),
  green: key("2D9A63"),
  teal: key("26978F"),
  blue: key("458CDE"),
  pink: key("D7609C"),
} as const;

export type LabelColour = keyof typeof LABEL_COLOURS;

/** The colour's name when a stored value is one of the eight (in any letter case), or null for any other value. */
export function labelColourName(stored: string | null | undefined): LabelColour | null {
  const value = (stored ?? "").trim().toLowerCase();
  for (const [name, hex] of Object.entries(LABEL_COLOURS)) if (hex.toLowerCase() === value) return name as LabelColour;
  return null;
}

const STORED = /^#[0-9a-f]{6}$/i;

/**
 * The class and the one inline value a label draws with. One of the eight takes its role tokens through the class.
 * Any other colour (set by an older app or the API) reaches the CSS only as --label-own, which the stylesheet uses for
 * the dot alone: never as text or fill. A value that isn't #rrggbb isn't used at all (the dot stays neutral).
 */
function look(colour: string): { className: string; style?: CSSProperties } {
  const name = labelColourName(colour);
  if (name) return { className: `el-label--${name}` };
  return STORED.test(colour.trim()) ? { className: "el-label--other", style: { ["--label-own" as string]: colour.trim() } } : { className: "el-label--other" };
}

/** A label's colour dot alone (a row's labels when there is no room for chips); named by the label for assistive tools. */
export function LabelDot({ name, colour }: { name: string; colour: string }) {
  const { className, style } = look(colour);
  return <span className={`el-label-dot ${className}`} style={style} role="img" aria-label={name} />;
}

/**
 * A label as a chip: its dot and its name, on the colour's fill with the colour's text for one of the eight, or on the
 * neutral chip with primary text and only the dot in the stored colour for any other. With onRemove it has its own
 * remove button, like an applied filter's Chip.
 */
export function LabelChip({ name, colour, onRemove, removeLabel }: { name: string; colour: string; onRemove?: () => void; removeLabel?: string }) {
  const { className, style } = look(colour);
  return (
    <span className={`el-label-chip ${className}${onRemove ? " el-label-chip--removable" : ""}`} style={style}>
      <span className="el-label-dot" aria-hidden="true" />
      <span className="el-label-chip__name" dir="auto">{name}</span>
      {onRemove && (
        <button type="button" className="el-chip__remove" aria-label={removeLabel ?? `Remove label: ${name}`} onClick={onRemove}>
          <Icon icon={CloseIcon} />
        </button>
      )}
    </span>
  );
}
