import { useId, useRef, useState, type FormEvent, type KeyboardEvent } from "react";
import { Button } from "./Button";
import { COLOUR_NAMES, COLOUR_ORDER, ColourPicker } from "./ColourPicker";
import { Dialog } from "./Dialog";
import { LABEL_COLOURS, labelColourName, type LabelColour } from "./Label";
import { TextField } from "./fields";

/** The longest list name the field takes. */
export const LIST_NAME_MAX = 40;

export type NewListWords = {
  title: string;
  name: string;
  colour: string;
  create: string;
  cancel: string;
  close: string;
  /** Under the field when the name is taken; given the existing list's own name. */
  duplicate: (name: string) => string;
  colourNames: Readonly<Record<LabelColour, string>>;
};

export const NEW_LIST_WORDS: NewListWords = {
  title: "New list",
  name: "List name",
  colour: "Colour",
  create: "Create",
  cancel: "Cancel",
  close: "Close",
  duplicate: (name) => `You already have a list called ${name}.`,
  colourNames: COLOUR_NAMES,
};

/**
 * The colour a new list starts with: the first of the eight, in the picker's order, that no list has yet. When every
 * colour is taken, the one fewest lists have (the earliest of those). `used` holds stored values (#rrggbb, any letter
 * case) or colour names; anything else is ignored.
 */
export function nextListColour(used: readonly (string | null | undefined)[]): LabelColour {
  const counts = new Map<LabelColour, number>(COLOUR_ORDER.map((c) => [c, 0]));
  for (const value of used) {
    const name = value && value in LABEL_COLOURS ? (value as LabelColour) : labelColourName(value);
    if (name) counts.set(name, (counts.get(name) ?? 0) + 1);
  }
  let best = COLOUR_ORDER[0];
  for (const c of COLOUR_ORDER) if ((counts.get(c) ?? 0) < (counts.get(best) ?? 0)) best = c;
  return best;
}

/** The fixed chips' words: a list can't take one of them as its name. A page in another language passes its own. */
export const FIXED_LIST_NAMES: readonly string[] = ["All", "Unread", "Important"];

/**
 * The existing list or fixed chip with this name, if there is one. Names are compared trimmed and in lower case with
 * toLowerCase(), not the locale's version, because the server keys lists the same way.
 */
export function listNameTaken(name: string, existing: readonly string[], reserved: readonly string[] = FIXED_LIST_NAMES): string | undefined {
  const key = name.trim().toLowerCase();
  if (!key) return undefined;
  return [...existing, ...reserved].find((e) => e.trim().toLowerCase() === key);
}

export type NewListDialogProps = {
  open: boolean;
  onClose: () => void;
  /** Create was pressed with a free name: the name, trimmed, and the colour as the server stores it (#rrggbb). */
  onCreate: (name: string, colour: string) => void;
  /** The person's list names, to refuse a second list of the same name. */
  existing: readonly string[];
  /** The fixed chips' words in the page's language, also refused (default: All, Unread, Important). */
  reserved?: readonly string[];
  /** The lists' stored colours, so the next colour no list has is chosen to start with. */
  usedColours?: readonly (string | null | undefined)[];
  words?: Partial<NewListWords>;
};

/**
 * "+" in the list row (D14 §2): the list's name (focused, up to 40 characters), then its colour, with the next unused
 * colour already chosen. Create is the main button and stays off until there is a name; a name already in use says so
 * under the field and blocks Create. Enter creates, Esc and Cancel close. Every opening starts empty.
 */
export function NewListDialog({ open, ...rest }: NewListDialogProps) {
  if (!open) return null;
  return <NewListForm {...rest} />;
}

function NewListForm({ onClose, onCreate, existing, reserved, usedColours = [], words: given }: Omit<NewListDialogProps, "open">) {
  const words = { ...NEW_LIST_WORDS, ...given };
  const formId = useId();
  const field = useRef<HTMLInputElement>(null);
  const [name, setName] = useState("");
  const [colour, setColour] = useState<LabelColour>(() => nextListColour(usedColours));
  const clash = listNameTaken(name, existing, reserved);
  const ready = name.trim().length > 0 && !clash;

  const create = () => {
    if (ready) onCreate(name.trim(), LABEL_COLOURS[colour]);
  };
  const submit = (e: FormEvent) => {
    e.preventDefault();
    create();
  };
  // Enter anywhere in the form creates, on the name or on a colour (never while an input method is composing a word).
  const enter = (e: KeyboardEvent<HTMLFormElement>) => {
    if (e.key !== "Enter" || e.nativeEvent.isComposing) return;
    e.preventDefault();
    create();
  };

  return (
    <Dialog open title={words.title} onClose={onClose} closeLabel={words.close} initialFocus={field}
      actions={<>
        <Button variant="secondary" onClick={onClose}>{words.cancel}</Button>
        <Button variant="primary" type="submit" form={formId} disabled={!ready}>{words.create}</Button>
      </>}>
      <form id={formId} className="el-newlist" onSubmit={submit} onKeyDown={enter} noValidate>
        <TextField ref={field} label={words.name} value={name} maxLength={LIST_NAME_MAX} autoComplete="off" spellCheck={false}
          onChange={(e) => setName(e.target.value)} error={clash ? words.duplicate(clash) : undefined} />
        <ColourPicker label={words.colour} value={colour} onChange={setColour} names={words.colourNames} />
      </form>
    </Dialog>
  );
}

export type RenameListWords = Pick<NewListWords, "name" | "cancel" | "close" | "duplicate"> & { title: string; save: string };

export const RENAME_LIST_WORDS: RenameListWords = {
  title: "Rename list",
  name: NEW_LIST_WORDS.name,
  save: "Save",
  cancel: NEW_LIST_WORDS.cancel,
  close: NEW_LIST_WORDS.close,
  duplicate: NEW_LIST_WORDS.duplicate,
};

export type RenameListDialogProps = {
  open: boolean;
  /** The list's name now; the field starts with it. */
  name: string;
  onClose: () => void;
  /** Save was pressed with a free name, trimmed. A change of letter case alone is allowed. */
  onRename: (name: string) => void;
  /** Every list name, this list's own included (it is left out of the check). */
  existing: readonly string[];
  reserved?: readonly string[];
  words?: Partial<RenameListWords>;
};

/**
 * Rename… on a list chip: the same field and the same name check as the new-list dialog, so a list can't be renamed
 * to another list's name or a fixed chip's word. Save stays off until the name is free and not empty; Enter saves.
 */
export function RenameListDialog({ open, ...rest }: RenameListDialogProps) {
  if (!open) return null;
  return <RenameListForm {...rest} />;
}

function RenameListForm({ name: current, onClose, onRename, existing, reserved, words: given }: Omit<RenameListDialogProps, "open">) {
  const words = { ...RENAME_LIST_WORDS, ...given };
  const formId = useId();
  const field = useRef<HTMLInputElement>(null);
  const [name, setName] = useState(current);
  const self = current.trim().toLowerCase();
  const others = existing.filter((e) => e.trim().toLowerCase() !== self);
  const clash = listNameTaken(name, others, reserved);
  const ready = name.trim().length > 0 && !clash;

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (ready) onRename(name.trim());
  };

  return (
    <Dialog open title={words.title} onClose={onClose} closeLabel={words.close} initialFocus={field}
      actions={<>
        <Button variant="secondary" onClick={onClose}>{words.cancel}</Button>
        <Button variant="primary" type="submit" form={formId} disabled={!ready}>{words.save}</Button>
      </>}>
      <form id={formId} className="el-newlist" onSubmit={submit} noValidate>
        <TextField ref={field} label={words.name} value={name} maxLength={LIST_NAME_MAX} autoComplete="off" spellCheck={false}
          onChange={(e) => setName(e.target.value)} error={clash ? words.duplicate(clash) : undefined} />
      </form>
    </Dialog>
  );
}
