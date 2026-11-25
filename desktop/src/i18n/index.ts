// The app's words in the person's language (desktop Urdu, Phase 0). English text is the key, as the web's __(): t("Search
// recordings") is English until the catalogue (ur.json, seeded from the web's lang/ur.json) has its Urdu, so every
// test that finds a control by its English name keeps working in English. Values use Laravel's placeholders
// (t("Clear :name", { name })), plurals its trans_choice form (tc(":count recording|:count recordings", n)), and in a
// right-to-left language each value sits in a Unicode isolate (FSI … PDI), as the web's IsolatingTranslator does, so a
// Latin name or a number keeps its own direction inside Urdu. No library: a key, a line, its values.
//
// The language is the account's (GET /api/account `language`, the same on the web, the desktop and the phone), the
// system's while signed out, and English unless the server's v21_urdu flag is on (language.ts). Choosing one sets the
// window's <html lang dir>: the right-to-left layout, the Nastaliq face for Arabic-script letters and the Urdu type
// sizes follow from it (styles.css, v2.css).
import { useSyncExternalStore } from "react";

export type Lang = "en" | "ur";
export const LANGS: readonly Lang[] = ["en", "ur"];
/** The languages written right to left. */
const RTL = new Set<Lang>(["ur"]);
/** U+2068 FIRST STRONG ISOLATE and U+2069 POP DIRECTIONAL ISOLATE, written as escapes (the characters are invisible). */
export const FSI = "\u2068";
export const PDI = "\u2069";
/** The isolates' openers: U+2066 LEFT-TO-RIGHT, U+2067 RIGHT-TO-LEFT and U+2068 FIRST STRONG. */
const OPENERS = new Set(["\u2066", "\u2067", "\u2068"]);

/** Each catalogue, loaded when its language is first chosen (English is the keys themselves). */
const CATALOGUES: Record<Exclude<Lang, "en">, () => Promise<Record<string, string>>> = {
  ur: () => import("./ur.json").then((m) => m.default as Record<string, string>),
};
const loaded: Partial<Record<Lang, Record<string, string>>> = { en: {} };

let current: Lang = "en";
/** The choices made, counted: a catalogue that arrives after a later choice is not applied. */
let asked = 0;
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

/**
 * Words in this language from now on: its catalogue is loaded first (nothing shows half translated), then the window's
 * <html lang dir> are set and every useT() renders again. `root` false leaves <html> as it is (a test, a window that
 * keeps its own direction).
 */
export async function setLanguage(lang: Lang, { root = true }: { root?: boolean } = {}): Promise<void> {
  const ask = ++asked;
  if (!loaded[lang]) loaded[lang] = await CATALOGUES[lang as Exclude<Lang, "en">]();
  // Another language was chosen while this one's catalogue loaded: the last choice stands.
  if (ask !== asked) return;
  current = lang;
  lines = loaded[lang] ?? {};
  if (root && typeof document !== "undefined") {
    document.documentElement.lang = lang;
    document.documentElement.dir = isRtl(lang) ? "rtl" : "ltr";
  }
  for (const listener of listeners) listener();
}

/** For tests: the language put back to English and the catalogue given (or the real one). */
export function resetLanguageForTests(catalogue?: Record<string, string>) {
  current = "en";
  lines = {};
  if (catalogue) loaded.ur = catalogue;
  else delete loaded.ur;
  for (const listener of listeners) listener();
}

export type Values = Record<string, string | number | null | undefined>;

/**
 * A value in a right-to-left line, in its own isolate: a Latin name or a number keeps its direction. The web's
 * IsolatingTranslator::isolate exactly: an empty value stays empty, and a closing mark (PDI) with no opener of its own
 * inside the value is dropped, so a typed name can't close this isolate early and let what follows (an override such as
 * U+202E) flip the sentence; whatever the value leaves open is closed by the final PDI.
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
  return `${FSI}${safe}${PDI}`;
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

/** The words for `text` (English: the key itself), with its values filled in. */
export function t(text: string, values?: Values): string {
  const line = lines[text] ?? text;
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
  const line = lines[text] ?? text;
  const filled = values?.count === undefined || values.count === null ? { ...values, count } : values;
  return fill(choose(line, count), filled);
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** The language now, and a render whenever it changes. */
export function useLanguage(): Lang {
  return useSyncExternalStore(subscribe, language, () => "en" as Lang);
}

/** t and tc for a component: it renders again in the new language when the language changes. */
export function useT(): { t: typeof t; tc: typeof tc; lang: Lang; rtl: boolean } {
  const lang = useLanguage();
  return { t, tc, lang, rtl: isRtl(lang) };
}

/** The locale for dates (`toLocaleDateString`): Urdu's in Urdu, else the day-month order the app has always shown. */
export function dateLocale(): string {
  return current === "ur" ? "ur-PK" : "en-GB";
}

/** The locale for numbers and 12-hour times: Urdu's in Urdu (Latin digits, as in Pakistan), else US English. */
export function numberLocale(): string {
  return current === "ur" ? "ur-PK" : "en-US";
}
