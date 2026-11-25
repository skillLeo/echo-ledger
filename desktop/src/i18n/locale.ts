// The words' plain machinery (desktop Urdu): the language now, the catalogue's lines, t() and tc() with Laravel's
// placeholders and plural rules, the isolates, and the locales for dates and numbers. No React and no imports but
// relative .ts files, so plain Node can import lib/format.ts and lib/languages.ts, which read it (the web's
// AdminTranscriptPlayerTest compares the transcript copy formats with format.ts's). index.ts loads the catalogues, sets
// the window's <html lang dir> and gives React its hooks, and re-exports all of this: the app imports "../i18n".
import { CONTEXT_EN } from "./context.ts";

export type Lang = "en" | "ur";
export const LANGS: readonly Lang[] = ["en", "ur"];
/** The languages written right to left. */
const RTL = new Set<Lang>(["ur"]);
/** U+2068 FIRST STRONG ISOLATE and U+2069 POP DIRECTIONAL ISOLATE, written as escapes (the characters are invisible). */
export const FSI = "\u2068";
export const PDI = "\u2069";
/** The isolates' openers: U+2066 LEFT-TO-RIGHT, U+2067 RIGHT-TO-LEFT and U+2068 FIRST STRONG. */
const OPENERS = new Set(["\u2066", "\u2067", "\u2068"]);

let current: Lang = "en";
let lines: Record<string, string> = {};
const listeners = new Set<() => void>();

/** The language the words are in now. */
export function language(): Lang {
  return current;
}

/** Whether a language is written right to left. */
export function isRtl(lang: Lang = current): boolean {
  return RTL.has(lang);
}

export type Values = Record<string, string | number | null | undefined>;

/**
 * A value in a right-to-left line, in its own isolate: a Latin name or a number keeps its direction. The web's
 * IsolatingTranslator::isolate: an empty value stays empty; a closing mark (PDI) with no opener of its own inside the
 * value is dropped, so a typed name can't close this isolate early and let what follows (an override such as U+202E)
 * flip the sentence; and every isolate the value opens (FSI, LRI, RLI) and leaves open is closed before the final PDI
 * (D-224), so a name typed with an FSI in it ("Ali", U+2068, "evil") can't leave the rest of the sentence inside its
 * isolate. The final PDI then closes this isolate, and with it any embedding or override the value opened (U+202E).
 */
export function isolate(value: string): string {
  if (value === "") return value;
  let depth = 0;
  let safe = "";
  for (const char of value) {
    if (OPENERS.has(char)) depth++;
    else if (char === PDI) {
      if (depth === 0) continue;
      depth--;
    }
    safe += char;
  }
  return `${FSI}${safe}${PDI.repeat(depth)}${PDI}`;
}

/** U+2067 RIGHT-TO-LEFT ISOLATE: a run that reads right to left whatever surrounds it (lib/format's Urdu dates). */
export const RLI = "\u2067";
/**
 * Letters of the right-to-left scripts: Hebrew, Arabic, Urdu, Syriac, Thaana and their presentation forms, and those
 * past the basic plane (Hanifi Rohingya, Adlam and the rest, U+10800–U+10FFF and U+1E800–U+1EFFF).
 */
const RTL_LETTER = /[\u0590-\u08FF\uFB1D-\uFDFF\uFE70-\uFEFC\u{10800}-\u{10FFF}\u{1E800}-\u{1EFFF}]/u;

/**
 * The text of a line, when it is text: a string, a number, or a list of them (an RLM put before a line); null for
 * anything drawn otherwise.
 */
function textOf(node: unknown): string | null {
  if (typeof node === "string" || typeof node === "number") return String(node);
  if (Array.isArray(node)) {
    let out = "";
    for (const part of node) {
      if (part === null || part === undefined || part === false || part === true) continue;
      const text = textOf(part);
      if (text === null) return null;
      out += text;
    }
    return out;
  }
  return null;
}

/**
 * A line's direction for its dir attribute. In English "auto", as the app has always drawn it. In Urdu, outside its
 * isolates (the browsers' dir="auto" reads the first letter even inside one, so "Imran کی بھیجی ہوئی ریکارڈنگ", with
 * Imran in an isolate, laid out left to right):
 * - a line with any right-to-left letter reads right to left, even when a name or a brand comes first ("Mac چیک",
 *   "Windows 11 پر آف", "EchoLedger کی ونڈوز چھپائیں"): it is an Urdu sentence;
 * - a line with only Latin letters (a person's own English, a server's message) reads left to right, as before;
 * - an RLM or ALM (or an LRM) before any letter says it outright;
 * - a line with no letter outside its isolates, or drawn with more than text, stays "auto".
 */
export function lineDir(line: unknown, lang: Lang = current): "rtl" | "ltr" | "auto" {
  if (!isRtl(lang)) return "auto";
  const text = textOf(line);
  if (text === null) return "auto";
  let depth = 0;
  let latin = false;
  for (const char of text) {
    if (OPENERS.has(char)) depth++;
    else if (char === PDI) depth = Math.max(0, depth - 1);
    else if (depth > 0) continue;
    else if (char === "\u200F" || char === "\u061C") {
      if (!latin) return "rtl";
    } else if (char === "\u200E") {
      if (!latin) return "ltr";
    } else if (/\p{L}/u.test(char)) {
      if (RTL_LETTER.test(char)) return "rtl";
      latin = true;
    }
  }
  return latin ? "ltr" : "auto";
}

/** The text without the isolates (for text that leaves the window: the clipboard, a file name, the server). */
export function stripIsolates(text: string): string {
  return text.replace(/[\u2066-\u2069]/g, "");
}

// Laravel's Str::upper and Str::ucfirst (mb_strtoupper): the same capitals whatever this computer's locale is (a
// Turkish system's "i" is still "I").
const upper = (s: string) => s.toUpperCase();
const upperFirst = (s: string) => {
  const first = s.codePointAt(0);
  if (first === undefined) return s;
  const char = String.fromCodePoint(first);
  return upper(char) + s.slice(char.length);
};
const escape = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/**
 * Laravel's replacements (makeReplacements): `:name`, `:Name` (the value's first letter capitalised) and `:NAME` (in
 * capitals), the longest placeholder first, in one pass (a value is never filled in again). In a right-to-left
 * language every value is isolated.
 */
export function fill(line: string, values: Values, rtl = isRtl()): string {
  const map = new Map<string, string>();
  for (const [key, raw] of Object.entries(values)) {
    const value = raw === null || raw === undefined ? "" : String(raw);
    const wrap = (v: string) => (rtl ? isolate(v) : v);
    map.set(`:${upperFirst(key)}`, wrap(upperFirst(value)));
    map.set(`:${upper(key)}`, wrap(upper(value)));
    map.set(`:${key}`, wrap(value));
  }
  if (map.size === 0) return line;
  const keys = [...map.keys()].sort((a, b) => b.length - a.length).map(escape);
  return line.replace(new RegExp(keys.join("|"), "g"), (hit) => map.get(hit) ?? hit);
}

/** The line for a key: the catalogue's; else a context key's English (context.ts); else the English key itself. */
const lineOf = (text: string): string => lines[text] ?? CONTEXT_EN[text] ?? text;

/**
 * The words for `text` (English: the key itself, or a context key's English: t("library_context.saved_view") is
 * "View"), with its values filled in.
 */
export function t(text: string, values?: Values): string {
  const line = lineOf(text);
  return values ? fill(line, values) : line;
}

/** Which plural form (Laravel's getPluralIndex, on the number's absolute value): English's and Urdu's rules are the same. */
function pluralIndex(lang: Lang, n: number): number {
  const abs = Math.abs(n);
  switch (lang) {
    case "en":
    case "ur":
    default:
      return abs === 1 ? 0 : 1;
  }
}

/** A string PHP reads as a number ("2", "-1", "1.5", ".5"). */
const numeric = (s: string) => /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(s);
/** PHP 8's comparison of a number with a string: as numbers when the string is numeric, else as strings. */
const compare = (n: number, s: string): number => {
  if (numeric(s)) return Math.sign(n - Number(s));
  const text = String(n);
  return text < s ? -1 : text > s ? 1 : 0;
};

/**
 * A segment's condition, as Laravel's MessageSelector reads it: `{0}` exactly, `[2,5]` a range, `[6,*]` and `[*,1]` open
 * ones. Its characters are only Laravel's (`-?`, digits, `|*,.`), so "[Draft] :count file" is not a condition, and an
 * empty `{}` never matches.
 */
function conditionHolds(condition: string, n: number): boolean {
  if (condition.includes(",")) {
    const comma = condition.indexOf(",");
    const from = condition.slice(0, comma);
    const to = condition.slice(comma + 1);
    if (to === "*" && compare(n, from) >= 0) return true;
    if (from === "*" && compare(n, to) <= 0) return true;
    if (compare(n, from) >= 0 && compare(n, to) <= 0) return true;
  }
  return compare(n, condition) === 0;
}

/** Laravel's condition: `{` or `[`, its characters, `}` or `]`. */
const CONDITION = /^[{[]([-?\d|*,.]*)[}\]](.*)$/s;
const CONDITION_PREFIX = /^[{[][-?\d|*,.]*[}\]]/;

/**
 * Laravel's trans_choice on a line (MessageSelector::choose): an explicit `{n}` or `[a,b]` segment first (trimmed), else
 * the language's plural form of the segments without their conditions (as written, not trimmed).
 */
export function choose(line: string, n: number, lang: Lang = current): string {
  const segments = line.split("|");
  for (const part of segments) {
    const m = CONDITION.exec(part);
    if (m && conditionHolds(m[1], n)) return m[2].trim();
  }
  const bare = segments.map((part) => part.replace(CONDITION_PREFIX, ""));
  if (bare.length === 1) return bare[0];
  return bare[pluralIndex(lang, n)] ?? bare[0];
}

/**
 * trans_choice: the words for `text` ("one|other", English's being the key) for `count`. As Laravel, `:count` is the
 * number given unless the values say their own (`{ count: count(n) }` for "3,000" or Urdu's digits).
 */
export function tc(text: string, count: number, values?: Values): string {
  const line = lineOf(text);
  const filled = values?.count === undefined || values.count === null ? { ...values, count } : values;
  return fill(choose(line, count), filled);
}

/** The locale for dates (`toLocaleDateString`): Urdu's in Urdu, else the day-month order the app has always shown. */
export function dateLocale(): string {
  return current === "ur" ? "ur-PK" : "en-GB";
}

/** The locale for numbers and 12-hour times: Urdu's in Urdu (Latin digits, as in Pakistan), else US English. */
export function numberLocale(): string {
  return current === "ur" ? "ur-PK" : "en-US";
}

/**
 * The language and its catalogue's lines from now on (index.ts's setLanguage, once the catalogue is loaded; its
 * resetLanguageForTests). Nothing is told: announceLanguage() then tells every listener.
 */
export function applyLanguage(lang: Lang, catalogue: Record<string, string>): void {
  current = lang;
  lines = catalogue;
}

/** Tells every listener (useLanguage) that the language changed. */
export function announceLanguage(): void {
  for (const listener of listeners) listener();
}

/** Listens for language changes (useLanguage's store); returns the way to stop. */
export function subscribeLanguage(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
