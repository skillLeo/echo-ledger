// The key pressed, as a Latin keyboard names it (D-221). With a non-Latin layout on (Urdu, Arabic, Persian, Russian…)
// a letter's key types that layout's letter, so the app's keys (J, K, X, F and / in the Library, ⌘F, ⌘1…⌘6, ⌘[ and
// the menus' shortcuts) would never answer. Then the key's place on the keyboard (KeyJ, Digit1, Slash) stands for it.
// A Latin layout keeps its own letters: AZERTY's A is A wherever it sits, and a key that names itself (Enter, Escape,
// ArrowDown) is left as it is.

/** The punctuation keys by place, unshifted and with Shift. */
const PLACES: Record<string, [string, string]> = {
  Slash: ["/", "?"],
  BracketLeft: ["[", "{"],
  BracketRight: ["]", "}"],
  Backslash: ["\\", "|"],
  Period: [".", ">"],
  Comma: [",", "<"],
  Minus: ["-", "_"],
  Equal: ["=", "+"],
  Semicolon: [";", ":"],
  Quote: ["'", "\""],
  Backquote: ["`", "~"],
};

/** e.key, or for a character no Latin keyboard types, the Latin key at the same place (lower case for a letter). */
export function latinKey(e: { key: string; code?: string; shiftKey?: boolean }): string {
  const { key, code } = e;
  // One character that a US keyboard types (or a named key: "Enter", "ArrowDown"): as it is.
  if (!code || [...key].length !== 1 || /^[\x20-\x7e]$/.test(key)) return key;
  const letter = /^Key([A-Z])$/.exec(code);
  if (letter) return letter[1].toLowerCase();
  const digit = /^(?:Digit|Numpad)([0-9])$/.exec(code);
  if (digit) return digit[1];
  const place = PLACES[code];
  return place ? place[e.shiftKey ? 1 : 0] : key;
}
