import { describe, expect, it } from "vitest";
import { engineLine, languageChip, languageLabel, learnPrompt, nextLanguage, scriptWarning } from "./languages";

describe("languages", () => {
  it("labels and chips every offered language, and treats unknown codes as automatic", () => {
    expect(["", "en", "ur", "en+ur", "hi", "en+hi", "fr"].map((c) => languageChip(c))).toEqual(["Auto", "EN", "UR", "UR+EN", "HI", "HI+EN", "Auto"]);
    expect(languageLabel("en+hi")).toBe("Hindi + English");
    expect(languageLabel(null)).toBe("Automatic");
  });

  it("steps through the languages in chip order and wraps to Auto", () => {
    const seen = [null as string | null];
    for (let i = 0; i < 6; i++) seen.push(nextLanguage(seen[seen.length - 1]));
    expect(seen).toEqual([null, "en", "ur", "en+ur", "hi", "en+hi", null]);
  });

  it("asks to remember a language only for a client that uses another one", () => {
    expect(learnPrompt("Acme", "en", "ur")).toBe("Use Urdu for Acme from now on?");
    expect(learnPrompt("Acme", null, "en+ur")).toBe("Use Urdu + English for Acme from now on?");
    expect(learnPrompt("Acme", "ur", "ur")).toBeNull();
    expect(learnPrompt("Acme", "ur", null)).toBeNull();
    expect(learnPrompt(null, "ur", "en")).toBeNull();
  });

  it("names the language and engine of the final transcript and explains a wrong script", () => {
    expect(engineLine({ final_language: "en+ur", final_provider_label: "ElevenLabs Scribe", final_model: "scribe_v2" })).toBe("Urdu + English · ElevenLabs Scribe (scribe_v2)");
    expect(engineLine({ final_language: null, final_provider_label: null })).toBeNull();
    expect(scriptWarning("devanagari")).toMatch(/Hindi letters in an Urdu transcript/);
    expect(scriptWarning(null)).toBeNull();
  });
});
