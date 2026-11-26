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
//
// The plain part (the language now, t, tc, the isolates, the locales) is locale.ts, with no React, so plain Node can
// import lib/format.ts; this file loads the catalogues, sets <html lang dir>, gives React its hooks and re-exports it.
import { createElement, Fragment, useSyncExternalStore, type ReactNode } from "react";
import { announceLanguage, applyLanguage, isRtl, language, subscribeLanguage, t, tc, type Lang } from "./locale";

export * from "./locale";

/** Each catalogue, loaded when its language is first chosen (English is the keys themselves). */
const CATALOGUES: Record<Exclude<Lang, "en">, () => Promise<Record<string, string>>> = {
  ur: () => import("./ur.json").then((m) => m.default as Record<string, string>),
};
const loaded: Partial<Record<Lang, Record<string, string>>> = { en: {} };

/** The choices made, counted: a catalogue that arrives after a later choice is not applied. */
let asked = 0;

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
  applyLanguage(lang, loaded[lang] ?? {});
  if (root && typeof document !== "undefined") {
    document.documentElement.lang = lang;
    document.documentElement.dir = isRtl(lang) ? "rtl" : "ltr";
  }
  announceLanguage();
}

/** For tests: the language put back to English and the catalogue given (or the real one). */
export function resetLanguageForTests(catalogue?: Record<string, string>) {
  applyLanguage("en", {});
  if (catalogue) loaded.ur = catalogue;
  else delete loaded.ur;
  announceLanguage();
}

/** The language now, and a render whenever it changes. */
export function useLanguage(): Lang {
  return useSyncExternalStore(subscribeLanguage, language, () => "en" as Lang);
}

/** t and tc for a component: it renders again in the new language when the language changes. */
export function useT(): { t: typeof t; tc: typeof tc; lang: Lang; rtl: boolean } {
  const lang = useLanguage();
  return { t, tc, lang, rtl: isRtl(lang) };
}

const pattern = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/**
 * A line with elements in it (a word in bold, a button inside a sentence): t()'s line, given no values so its
 * placeholders stay, split at them, each placeholder's element in its place: withParts(t("Open :site and sign in."),
 * { site: <strong>console.groq.com</strong> }). In English the elements are as they were written beside the words; in a
 * right-to-left language each one sits in its own isolate (<bdi>), as t() isolates a value. Text that leaves the window
 * is never built this way.
 */
export function withParts(line: string, parts: Readonly<Record<string, ReactNode>>): ReactNode[] {
  const names = Object.keys(parts).sort((a, b) => b.length - a.length).map(pattern);
  if (names.length === 0) return [line];
  const out: ReactNode[] = [];
  const found = new RegExp(`:(${names.join("|")})`, "g");
  let at = 0;
  for (const hit of line.matchAll(found)) {
    if (hit.index > at) out.push(line.slice(at, hit.index));
    out.push(createElement(isRtl() ? "bdi" : Fragment, { key: out.length }, parts[hit[1]]));
    at = hit.index + hit[0].length;
  }
  if (at < line.length) out.push(line.slice(at));
  return out;
}
