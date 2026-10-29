import { describe, expect, it } from "vitest";
import { MAIN_LANGUAGES, MORE_LANGUAGES, engineLine, englishOnlyRisk, languageChip, languageLabel, learnPrompt, menuLabel, nextLanguage, normalizeLanguage, partsText, scriptWarning, transcribingText } from "./languages";

describe("languages", () => {
  it("offers Auto, English, Urdu and Arabic first, then the More languages, without Hindi or mixed choices", () => {
    expect(MAIN_LANGUAGES.map((o) => o.chip)).toEqual(["Auto", "EN", "UR", "AR"]);
    expect(MORE_LANGUAGES.map((o) => o.value)).toEqual(["fr", "de", "es", "it", "pt", "nl", "tr", "ru"]);
    expect(["", "en", "ur", "ar", "de", "xx"].map((c) => languageChip(c))).toEqual(["Auto", "EN", "UR", "AR", "DE", "Auto"]);
    expect(languageLabel("ar")).toBe("Arabic");
    expect(languageLabel(null)).toBe("Auto");
  });

  it("labels menus by what each choice does and keeps the plain name for sentences", () => {
    expect(MAIN_LANGUAGES.map(menuLabel)).toEqual(["Auto (English / Urdu / Arabic)", "English only", "Urdu (with English words)", "Arabic (with English words)"]);
    expect(menuLabel(MORE_LANGUAGES[0])).toBe("French");
    expect(languageLabel("ur")).toBe("Urdu");
    expect([...MAIN_LANGUAGES, ...MORE_LANGUAGES].some((o) => /hindi/i.test(menuLabel(o)))).toBe(false);
  });

  it("sees the risk of English only in Urdu or Arabic speech, Urdu letters or Roman Urdu, not in English", () => {
    expect(englishOnlyRisk("ur", null)).toBe(true);
    expect(englishOnlyRisk("hi", null)).toBe(true);
    expect(englishOnlyRisk(null, "آپ کل کی meeting")).toBe(true);
    expect(englishOnlyRisk(null, "kya kar rahe ho, theek hai")).toBe(true);
    expect(englishOnlyRisk("en", "Hello, this is Sam.")).toBe(false);
    expect(englishOnlyRisk(null, "Okay, acha.")).toBe(false);
    expect(englishOnlyRisk(null, null)).toBe(false);
  });

  it("reads v1.1 values as today: the mixed choices and Hindi are Urdu", () => {
    expect(normalizeLanguage("en+ur")).toBe("ur");
    expect(normalizeLanguage("ur+en")).toBe("ur");
    expect(normalizeLanguage("hi")).toBe("ur");
    expect(normalizeLanguage("en+hi")).toBe("ur");
    expect(normalizeLanguage("en+ar")).toBe("ar");
    expect(normalizeLanguage("ar-EG")).toBe("ar");
    expect(normalizeLanguage("ja")).toBeNull();
    expect(languageChip("en+ur")).toBe("UR");
  });

  it("steps through the main choices and wraps to Auto; a More language steps back to Auto", () => {
    const seen = [null as string | null];
    for (let i = 0; i < 4; i++) seen.push(nextLanguage(seen[seen.length - 1]));
    expect(seen).toEqual([null, "en", "ur", "ar", null]);
    expect(nextLanguage("de")).toBeNull();
  });

  it("asks to remember a language only for a client that uses another one", () => {
    expect(learnPrompt("Acme", "en", "ur")).toBe("Use Urdu for Acme from now on?");
    expect(learnPrompt("Acme", null, "ar")).toBe("Use Arabic for Acme from now on?");
    expect(learnPrompt("Acme", "en+ur", "ur")).toBeNull();
    expect(learnPrompt("Acme", "ur", null)).toBeNull();
    expect(learnPrompt(null, "ur", "en")).toBeNull();
  });

  it("names the language and engine of the final transcript and explains a wrong script", () => {
    expect(engineLine({ final_language: "ur", final_provider_label: "Groq Whisper large-v3", final_model: "whisper-large-v3" })).toBe("Urdu · Groq Whisper large-v3 (whisper-large-v3)");
    expect(engineLine({ final_language: null, final_provider_label: null })).toBeNull();
    expect(scriptWarning("devanagari")).toMatch(/Hindi letters came back/);
    expect(scriptWarning(null)).toBeNull();
  });
});

describe("progress", () => {
  it("names the part being transcribed, and nothing for a single part", () => {
    expect(transcribingText({ done: 2, total: 8 })).toBe("Transcribing… part 3 of 8");
    expect(transcribingText({ done: 8, total: 8 })).toBe("Transcribing… part 8 of 8");
    expect(transcribingText({ done: 0, total: 1 })).toBe("Transcribing…");
    expect(transcribingText(null)).toBe("Transcribing…");
    expect(partsText({ done: 0, total: 3 })).toBe("part 1 of 3");
    expect(partsText(undefined)).toBeNull();
  });
});
