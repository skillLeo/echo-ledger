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
export const FSI = "⁨";
export const PDI = "⁩";

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

/** A value in a right-to-left line, in its own isolate: a Latin name or a number keeps its direction. */
export function isolate(value: string): string {
  return `${FSI}${value}${PDI}`;
}

/** The text without the isolates (for text that leaves the window: the clipboard, a file name, the server). */
export function stripIsolates(text: string): string {
  return text.replace(/[⁦-⁩]/g, "");
}

const upperFirst = (s: string) => (s ? s.charAt(0).toLocaleUpperCase() + s.slice(1) : s);
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
    map.set(`:${key.toLocaleUpperCase()}`, wrap(value.toLocaleUpperCase()));
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

/** Which plural form: English's and Urdu's rules are the same (one for 1, the other for the rest), as Laravel's. */
function pluralIndex(lang: Lang, n: number): number {
  switch (lang) {
    case "en":
    case "ur":
    default:
      return n === 1 ? 0 : 1;
  }
}

/** A segment's condition (Laravel's MessageSelector): `{0}` exactly, `[2,5]` a range, `[6,*]` and `[*,1]` open ones. */
function conditionHolds(condition: string, n: number): boolean {
  if (condition.includes(",")) {
    const [from, to] = condition.split(",", 2).map((s) => s.trim());
    if (to === "*" && n >= Number(from)) return true;
    if (from === "*" && n <= Number(to)) return true;
    return n >= Number(from) && n <= Number(to);
  }
  return condition.split(/\s*,\s*/).some((c) => Number(c) === n);
}

/** Laravel's trans_choice on a line: an explicit `{n}` or `[a,b]` segment first, else the language's plural form. */
export function choose(line: string, n: number, lang: Lang = current): string {
  const segments = line.split("|");
  const explicit = /^[{[]([^[\]{}]*)[}\]](.*)$/s;
  for (const part of segments) {
    const m = explicit.exec(part);
    if (m && conditionHolds(m[1], n)) return m[2].trim();
  }
  const bare = segments.map((part) => part.replace(/^[{[]([^[\]{}]*)[}\]]/, "").trim());
  if (bare.length === 1) return bare[0];
  return bare[pluralIndex(lang, n)] ?? bare[0];
}

/** trans_choice: the words for `text` ("one|other", English's being the key) for `count`, `:count` filled in too. */
export function tc(text: string, count: number, values?: Values): string {
  const line = lines[text] ?? text;
  // As Laravel, :count is the number given, whatever the values say.
  return fill(choose(line, count), { ...values, count });
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
