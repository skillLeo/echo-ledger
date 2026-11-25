// Desktop Urdu, Phase 0: the words' runtime (t, tc, the isolates, the root's lang and dir), the language chosen, the
// dates and numbers in the window's language, and the seeded catalogue.
import { afterEach, describe, expect, it } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { choose, fill, FSI, PDI, language, resetLanguageForTests, setLanguage, stripIsolates, t, tc, useT } from "./index";
import { accountLanguage, chosenLanguage, systemLanguage } from "./language";
import { count, rowDate, sentenceDate } from "../lib/format";
import ur from "./ur.json";

const here = dirname(fileURLToPath(import.meta.url));
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

  it("in Urdu the catalogue's line, each value in its own isolate (FSI … PDI), as the web's IsolatingTranslator", async () => {
    resetLanguageForTests(CATALOGUE);
    await act(() => setLanguage("ur"));
    expect(language()).toBe("ur");
    expect(t("Search recordings")).toBe("ریکارڈنگز تلاش کریں");
    expect(t("Clear :name", { name: "Labels" })).toBe(`${FSI}Labels${PDI} صاف کریں`);
    expect(stripIsolates(t("Clear :name", { name: "Labels" }))).toBe("Labels صاف کریں");
    // Not in the catalogue yet: the English, its values still isolated.
    expect(t("Not yet :n", { n: 3 })).toBe(`Not yet ${FSI}3${PDI}`);
    // The window reads right to left, in Urdu.
    expect(document.documentElement.lang).toBe("ur");
    expect(document.documentElement.dir).toBe("rtl");
    await act(() => setLanguage("en"));
    expect([document.documentElement.lang, document.documentElement.dir]).toEqual(["en", "ltr"]);
  });

  it("tc chooses as trans_choice does: one for 1, the other for the rest; {n} and [a,b] segments first", async () => {
    expect(tc(":count recording|:count recordings", 1)).toBe("1 recording");
    expect(tc(":count recording|:count recordings", 0)).toBe("0 recordings");
    expect(tc(":count recording|:count recordings", 5, { count: 99 })).toBe("5 recordings");
    expect(tc("{0} No recordings|[1,*] :count recordings", 0)).toBe("No recordings");
    expect(tc("{0} No recordings|[1,*] :count recordings", 12)).toBe("12 recordings");
    expect(choose("{1} one|[2,4] few|[5,*] many", 3)).toBe("few");
    expect(choose("[*,0] none|[1,*] some", -2)).toBe("none");
    expect(choose("only one form", 7)).toBe("only one form");
    resetLanguageForTests(CATALOGUE);
    await setLanguage("ur", { root: false });
    expect(tc(":count recording|:count recordings", 1)).toBe(`${FSI}1${PDI} ریکارڈنگ`);
    expect(tc(":count recording|:count recordings", 2)).toBe(`${FSI}2${PDI} ریکارڈنگز`);
    expect(tc("{0} No recordings|[1,*] :count recordings", 0)).toBe("کوئی ریکارڈنگ نہیں");
    // root: false leaves <html> as it is.
    expect(document.documentElement.getAttribute("dir")).toBeNull();
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

describe("the language chosen (v21_urdu, the account's, the system's)", () => {
  it("English with the flag off; else the account's language, else the system's", () => {
    expect(chosenLanguage(false, { language: "ur" }, "ur")).toBe("en");
    expect(chosenLanguage(true, { language: "ur" }, "en")).toBe("ur");
    expect(chosenLanguage(true, { language: "en" }, "ur")).toBe("en");
    // Signed out, or a server that doesn't say (or says one the app doesn't speak): the system's.
    expect(chosenLanguage(true, null, "ur")).toBe("ur");
    expect(chosenLanguage(true, { language: null }, "en")).toBe("en");
    expect(chosenLanguage(true, { language: "fr" }, "ur")).toBe("ur");
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
  it("is seeded from the web's lang/ur.json: every entry is the web's own Urdu for the same English key", () => {
    const web = JSON.parse(readFileSync(join(here, "..", "..", "..", "web", "lang", "ur.json"), "utf8")) as Record<string, string>;
    const entries = Object.entries(ur as Record<string, string>);
    expect(entries.length).toBeGreaterThan(400);
    const differ = entries.filter(([key, value]) => web[key] !== undefined && web[key] !== value).map(([key]) => key);
    expect(differ).toEqual([]);
  });

  it("each Urdu line keeps its English key's placeholders and plural forms", () => {
    const names = (s: string) => [...s.matchAll(/:([A-Za-z_]\w*)/g)].map((m) => m[1].toLowerCase()).sort();
    const broken = Object.entries(ur as Record<string, string>)
      .filter(([key, value]) => names(key).join() !== names(value).join() || key.split("|").length !== value.split("|").length)
      .map(([key]) => key);
    expect(broken).toEqual([]);
  });
});
