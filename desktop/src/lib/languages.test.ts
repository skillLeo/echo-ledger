import { describe, expect, it } from "vitest";
import { MAIN_LANGUAGES, MORE_LANGUAGES, engineLine, languageChip, languageLabel, learnPrompt, nextLanguage, normalizeLanguage, scriptWarning } from "./languages";

describe("languages", () => {
  it("offers Auto, English, Urdu and Arabic first, then the More languages, without Hindi or mixed choices", () => {
    expect(MAIN_LANGUAGES.map((o) => o.chip)).toEqual(["Auto", "EN", "UR", "AR"]);
    expect(MORE_LANGUAGES.map((o) => o.value)).toEqual(["fr", "de", "es", "it", "pt", "nl", "tr", "ru"]);
    expect(["", "en", "ur", "ar", "de", "xx"].map((c) => languageChip(c))).toEqual(["Auto", "EN", "UR", "AR", "DE", "Auto"]);
    expect(languageLabel("ar")).toBe("Arabic");
    expect(languageLabel(null)).toBe("Auto");
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
