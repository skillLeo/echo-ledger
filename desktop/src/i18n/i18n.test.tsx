// Desktop Urdu, Phase 0: the words' runtime (t, tc, the isolates, the root's lang and dir), the language chosen, the
// dates and numbers in the window's language, and the seeded catalogue.
import { afterEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { choose, fill, FSI, isolate, PDI, language, resetLanguageForTests, setLanguage, stripIsolates, t, tc, useT } from "./index";
import { accountLanguage, chosenLanguage, systemLanguage } from "./language";
import { DESKTOP_OVERRIDES } from "./overrides";
import { CONTEXT_EN } from "./context";
import { textsIn } from "./rawText";
import { count, rowDate, sentenceDate } from "../lib/format";
import ur from "./ur.json";

const here = dirname(fileURLToPath(import.meta.url));

/** The app's sources (no test, no gallery), as the guard reads them. */
function sourcesIn(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return name === "gallery" || name === "test" ? [] : sourcesIn(path);
    return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) && !name.endsWith(".d.ts") ? [path] : [];
  });
}

const CATALOGUE = {
  "Search recordings": "ریکارڈنگز تلاش کریں",
  "Clear :name": ":name صاف کریں",
  ":count recording|:count recordings": ":count ریکارڈنگ|:count ریکارڈنگز",
  "{0} No recordings|[1,*] :count recordings": "{0} کوئی ریکارڈنگ نہیں|[1,*] :count ریکارڈنگز",
};

afterEach(async () => {
  resetLanguageForTests();
  document.documentElement.removeAttribute("lang");
  document.documentElement.removeAttribute("dir");
});

describe("t and tc (English text is the key, as the web's __())", () => {
  it("English is the key itself, with Laravel's :name, :Name and :NAME filled in and no isolates", () => {
    expect(t("Search recordings")).toBe("Search recordings");
    expect(t("Clear :name", { name: "labels" })).toBe("Clear labels");
    expect(t(":Name, :NAME and :name", { name: "ayesha" })).toBe("Ayesha, AYESHA and ayesha");
    // The longest placeholder first, and a value is never filled in again.
    expect(fill(":count of :counted", { count: 1, counted: ":count" }, false)).toBe("1 of :count");
    expect(t("Untranslated :x", { x: null })).toBe("Untranslated ");
  });

  it("capitals as Laravel's Str::upper, never this computer's locale (a Turkish system's i)", () => {
    const local = vi.spyOn(String.prototype, "toLocaleUpperCase").mockImplementation(() => {
      throw new Error("locale-dependent capitals");
    });
    try {
      expect(t(":Name and :NAME", { name: "istanbul" })).toBe("Istanbul and ISTANBUL");
    } finally {
      local.mockRestore();
    }
  });

  it("in Urdu the catalogue's line, each value in its own isolate (FSI … PDI), as the web's IsolatingTranslator", async () => {
    resetLanguageForTests(CATALOGUE);
    await act(() => setLanguage("ur"));
    expect(language()).toBe("ur");
    expect(t("Search recordings")).toBe("ریکارڈنگز تلاش کریں");
    expect(t("Clear :name", { name: "Labels" })).toBe(`${FSI}Labels${PDI} صاف کریں`);
    expect(stripIsolates(t("Clear :name", { name: "Labels" }))).toBe("Labels صاف کریں");
    // Not in the catalogue yet: the English, its values still isolated.
    expect(t("Not yet :n", { n: 3 })).toBe(`Not yet ${FSI}3${PDI}`);
    // An empty value stays empty (no isolate around nothing).
    expect(t("Clear :name", { name: "" })).toBe(" صاف کریں");
    // The window reads right to left, in Urdu.
    expect(document.documentElement.lang).toBe("ur");
    expect(document.documentElement.dir).toBe("rtl");
    await act(() => setLanguage("en"));
    expect([document.documentElement.lang, document.documentElement.dir]).toEqual(["en", "ltr"]);
  });

  it("tc chooses as trans_choice does: one for 1, the other for the rest; {n} and [a,b] segments first", async () => {
    expect(tc(":count recording|:count recordings", 1)).toBe("1 recording");
    expect(tc(":count recording|:count recordings", 0)).toBe("0 recordings");
    // As Laravel's choice(): :count is the number unless the values give their own ("3,000", Urdu's digits).
    expect(tc(":count recording|:count recordings", 5, { count: 99 })).toBe("99 recordings");
    expect(tc(":count recording|:count recordings", 3000, { count: count(3000) })).toBe("3,000 recordings");
    expect(tc(":count recording|:count recordings", 5, { count: null })).toBe("5 recordings");
    expect(tc("{0} No recordings|[1,*] :count recordings", 0)).toBe("No recordings");
    expect(tc("{0} No recordings|[1,*] :count recordings", 12)).toBe("12 recordings");
    expect(choose("{1} one|[2,4] few|[5,*] many", 3)).toBe("few");
    expect(choose("[*,0] none|[1,*] some", -2)).toBe("none");
    expect(choose("only one form", 7)).toBe("only one form");
    // Laravel's condition characters only: "[Draft]" is words, and an empty {} matches nothing (not 0).
    expect(choose("[Draft] :count file|[Draft] :count files", 1)).toBe("[Draft] :count file");
    expect(choose("[Draft] :count file|[Draft] :count files", 2)).toBe("[Draft] :count files");
    expect(choose("{}none|:count item|:count items", 0)).toBe(":count item");
    // getPluralIndex takes the absolute value: -1 is singular.
    expect(choose(":count day|:count days", -1)).toBe(":count day");
    // A plural form is taken as written (only an explicit segment's text is trimmed), as MessageSelector does.
    expect(choose("one | many", 2)).toBe(" many");
    expect(choose("{2} two | many", 2)).toBe("two");
    resetLanguageForTests(CATALOGUE);
    await setLanguage("ur", { root: false });
    expect(tc(":count recording|:count recordings", 1)).toBe(`${FSI}1${PDI} ریکارڈنگ`);
    expect(tc(":count recording|:count recordings", 2)).toBe(`${FSI}2${PDI} ریکارڈنگز`);
    expect(tc("{0} No recordings|[1,*] :count recordings", 0)).toBe("کوئی ریکارڈنگ نہیں");
    // root: false leaves <html> as it is.
    expect(document.documentElement.getAttribute("dir")).toBeNull();
  });

  it("the isolates are U+2068 and U+2069, and a value is isolated as the web's IsolatingTranslator::isolate", () => {
    expect([FSI.codePointAt(0), PDI.codePointAt(0), FSI.length, PDI.length]).toEqual([0x2068, 0x2069, 1, 1]);
    expect(isolate("Ali")).toBe("\u2068Ali\u2069");
    expect(isolate("")).toBe("");
    // A closing mark with no opener of its own is dropped: a typed name can't end the isolate early and let an
    // override (U+202E) flip the rest of the sentence.
    expect(isolate("\u2069\u202Eevil")).toBe("\u2068\u202Eevil\u2069");
    expect(isolate("a\u2069b\u2069")).toBe("\u2068ab\u2069");
    // One the value opened and closed itself stays as it is.
    expect(isolate("\u2067x\u2069y")).toBe("\u2068\u2067x\u2069y\u2069");
    // D-224: every isolate the value leaves open is closed before the final PDI, so the rest of the sentence is outside.
    expect(isolate("\u2066open")).toBe("\u2068\u2066open\u2069\u2069");
    expect(isolate("Ali\u2068evil")).toBe("\u2068Ali\u2068evil\u2069\u2069");
    expect(isolate("\u2068\u2067\u2066x")).toBe("\u2068\u2068\u2067\u2066x\u2069\u2069\u2069\u2069");
    // An override the value opens (U+202E) is closed with this isolate by its final PDI; an isolate after it is closed first.
    expect(isolate("Ali\u202E\u2066x")).toBe("\u2068Ali\u202E\u2066x\u2069\u2069");
    expect(fill("Call with :name today", { name: "Ali\u2068evil" }, true)).toBe("Call with \u2068Ali\u2068evil\u2069\u2069 today");
    // Each value's marks balance: as many PDIs as openers, every PDI after its opener.
    for (const value of ["Ali\u2068evil", "Ali\u202E\u2066x", "\u2069\u2066a\u2069\u2067b", "\u2066\u2066\u2069"]) {
      let open = 0;
      for (const char of isolate(value)) {
        if (char === "\u2066" || char === "\u2067" || char === "\u2068") open++;
        if (char === "\u2069") open--;
        expect(open).toBeGreaterThanOrEqual(0);
      }
      expect(open).toBe(0);
    }
    expect(fill("Hi :name", { name: "\u2069\u202Eevil" }, true)).toBe("Hi \u2068\u202Eevil\u2069");
    // Taken out again for text that leaves the window: the four isolate marks only.
    expect(stripIsolates("\u2066a\u2067b\u2068c\u2069\u202Ed")).toBe("abc\u202Ed");
  });

  it("the last choice stands, even when an earlier one's catalogue arrives later", async () => {
    const slow = setLanguage("ur");
    await setLanguage("en");
    await slow;
    expect(language()).toBe("en");
    expect(document.documentElement.dir).toBe("ltr");
  });

  it("useT renders again in the new language", async () => {
    resetLanguageForTests(CATALOGUE);
    function Label() {
      const { t: say, lang, rtl } = useT();
      return <p data-lang={lang} data-rtl={String(rtl)}>{say("Search recordings")}</p>;
    }
    render(<Label />);
    expect(screen.getByText("Search recordings")).toHaveAttribute("data-lang", "en");
    await act(() => setLanguage("ur"));
    expect(screen.getByText("ریکارڈنگز تلاش کریں")).toHaveAttribute("data-rtl", "true");
  });
});

describe("the language chosen (Urdu on, the account's, the one kept, the system's)", () => {
  it("English with Urdu off; signed in, the account's, else the one kept here, else English: never the system's", () => {
    expect(chosenLanguage({ on: false, signedIn: true, account: { language: "ur" }, kept: "ur", system: "ur" })).toBe("en");
    expect(chosenLanguage({ on: true, signedIn: true, account: { language: "ur" }, kept: null, system: "en" })).toBe("ur");
    expect(chosenLanguage({ on: true, signedIn: true, account: { language: "en" }, kept: "ur", system: "ur" })).toBe("en");
    // Still loading (undefined) or not read (null): the last one known here, else English, whatever the system says.
    expect(chosenLanguage({ on: true, signedIn: true, account: undefined, kept: "ur", system: "en" })).toBe("ur");
    expect(chosenLanguage({ on: true, signedIn: true, account: null, kept: "en", system: "ur" })).toBe("en");
    expect(chosenLanguage({ on: true, signedIn: true, account: undefined, kept: null, system: "ur" })).toBe("en");
    expect(chosenLanguage({ on: true, signedIn: true, account: { language: "fr" }, kept: null, system: "ur" })).toBe("en");
    // Signed out: the system's.
    expect(chosenLanguage({ on: true, signedIn: false, account: undefined, kept: "en", system: "ur" })).toBe("ur");
    expect(chosenLanguage({ on: true, signedIn: false, account: undefined, kept: "ur", system: "en" })).toBe("en");
    expect(accountLanguage(undefined)).toBeNull();
  });

  it("the system's language is Urdu when its first language is (ur, ur-PK, ur-IN)", () => {
    expect(systemLanguage("ur-PK")).toBe("ur");
    expect(systemLanguage("ur")).toBe("ur");
    expect(systemLanguage("en-GB")).toBe("en");
    expect(systemLanguage(undefined)).toBe("en");
  });
});

describe("dates and numbers in the window's language", () => {
  it("English keeps the forms the app has always shown; Urdu uses ur-PK", async () => {
    const now = new Date(2026, 10, 25);
    expect(rowDate("2026-12-09", now)).toBe("9 Dec");
    expect(sentenceDate("2026-12-09")).toBe("9 December 2026");
    expect(count(3000)).toBe("3,000");
    resetLanguageForTests(CATALOGUE);
    await setLanguage("ur", { root: false });
    expect(sentenceDate("2026-12-09")).toBe(new Date(2026, 11, 9).toLocaleDateString("ur-PK", { day: "numeric", month: "long", year: "numeric" }));
    expect(sentenceDate("2026-12-09")).not.toContain("December");
    expect(count(3000)).toBe((3000).toLocaleString("ur-PK"));
  });
});

describe("the catalogue (src/i18n/ur.json)", () => {
  it("is seeded from the web's lang/ur.json: every entry is the web's own Urdu for the same English key, but the named desktop overrides", () => {
    const web = JSON.parse(readFileSync(join(here, "..", "..", "..", "web", "lang", "ur.json"), "utf8")) as Record<string, string>;
    const entries = Object.entries(ur as Record<string, string>);
    expect(entries.length).toBeGreaterThan(400);
    const differ = entries.filter(([key, value]) => web[key] !== undefined && web[key] !== value).map(([key]) => key);
    expect(differ.filter((key) => !(key in DESKTOP_OVERRIDES))).toEqual([]);
    // Each override names a line of the catalogue that does differ from the web's (none left over), with its reason.
    const stale = Object.entries(DESKTOP_OVERRIDES).filter(([key, why]) => (web[key] !== undefined && !differ.includes(key)) || !(key in ur) || !why.trim()).map(([key]) => key);
    expect(stale).toEqual([]);
  });

  it("a word that means something else in one place has a context key: its English in context.ts, its Urdu in ur.json", async () => {
    const contextKeys = Object.keys(ur).filter((key) => /^[a-z_]+_context\.[a-z_]+$/.test(key));
    expect(contextKeys.sort()).toEqual(Object.keys(CONTEXT_EN).sort());
    const names = (s: string) => [...s.matchAll(/:([A-Za-z_]\w*)/g)].map((m) => m[1]).sort().join();
    expect(contextKeys.filter((key) => names(CONTEXT_EN[key]) !== names((ur as Record<string, string>)[key]))).toEqual([]);
    // English as before; in Urdu the place's own word, not the plain key's.
    expect(t("library_context.saved_view")).toBe("View");
    resetLanguageForTests(ur as Record<string, string>);
    await setLanguage("ur", { root: false });
    expect(t("library_context.saved_view")).toBe("ویو");
    expect(t("View")).toBe("انداز");
  });

  it("each Urdu line keeps its English key's placeholders and plural forms", () => {
    // A key with one English form may have Urdu's two (one|other) only where the app gives it to tc() alone, which
    // chooses by the number: "3 of 5 moved" is one form in English, two in Urdu (چلی گئی, چلی گئیں).
    const counted = new Map<string, Set<string>>();
    for (const file of sourcesIn(join(here, ".."))) {
      for (const f of textsIn(file, readFileSync(file, "utf8"))) if (f.via) counted.set(f.text, (counted.get(f.text) ?? new Set()).add(f.via));
    }
    const tcOnly = (key: string) => counted.get(key)?.size === 1 && counted.get(key)?.has("tc");
    const names = (s: string) => [...s.matchAll(/:([A-Za-z_]\w*)/g)].map((m) => m[1].toLowerCase()).sort().join();
    const forms = (key: string, value: string) => {
      const english = key.split("|").length;
      const urdu = value.split("|").length;
      return english === urdu || (english === 1 && urdu === 2 && !/^[{[]/.test(value) && tcOnly(key));
    };
    // Each Urdu form has its English form's placeholders (the one English form's, for each of Urdu's two).
    const samePlaceholders = (key: string, value: string) => {
      const english = key.split("|");
      return value.split("|").every((form, i) => names(form) === names(english[english.length === 1 ? 0 : i] ?? ""));
    };
    const broken = Object.entries(ur as Record<string, string>)
      .filter(([key, value]) => !forms(key, value) || !samePlaceholders(key, value))
      .map(([key]) => key);
    expect(broken).toEqual([]);
    // The rule holds only for those: a one-form key given to t() with two Urdu forms is refused.
    expect(forms(":count matches", ":count نتیجہ|:count نتائج")).toBe(true);
    expect(tcOnly("Search recordings")).toBe(false);
    expect(forms("Search recordings", "الف|ب")).toBe(false);
  });
});
