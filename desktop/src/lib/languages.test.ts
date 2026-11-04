import { describe, expect, it, vi } from "vitest";
import { MAIN_LANGUAGES, MORE_LANGUAGES, engineLine, englishOnlyRisk, languageChip, languageLabel, learnPrompt, menuLabel, nextLanguage, normalizeLanguage, partsText, retryLine, retryReason, scriptWarning, transcribingText, usedBackupEngine } from "./languages";

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

describe("backup engine and waiting for the engine (v1.5)", () => {
  it("knows a backup engine only from backup_engine true; an older server sends nothing", () => {
    expect(usedBackupEngine({ backup_engine: true })).toBe(true);
    expect(usedBackupEngine({ backup_engine: false })).toBe(false);
    expect(usedBackupEngine({})).toBe(false);
    expect(usedBackupEngine(null)).toBe(false);
  });

  it("says which engine it waits for and when it tries again", () => {
    const now = new Date(2026, 10, 4, 13, 0);
    const at = (day: number, hour: number, minute: number) => new Date(2026, 10, day, hour, minute).toISOString();
    expect(retryLine({ provider: "groq", retry_at: at(4, 14, 5) }, now)).toBe("Waiting for Groq — retrying at 2:05 pm");
    expect(retryLine({ provider: "groq", retry_at: at(4, 9, 30) }, new Date(2026, 10, 4, 8, 0))).toBe("Waiting for Groq — retrying at 9:30 am");
    expect(retryLine({ provider: "groq", retry_at: at(5, 1, 10) }, now)).toBe("Waiting for Groq — retrying tomorrow at 1:10 am");
    expect(retryLine({ provider: "gemini", retry_at: at(7, 18, 0) }, now)).toBe("Waiting for Gemini — retrying Nov 7 at 6:00 pm");
    expect(retryLine({ provider: "groq", retry_at: at(4, 12, 59) }, now)).toBe("Waiting for Groq — retrying now");
    expect(retryLine({ provider: null, retry_at: at(4, 14, 5) }, now)).toBe("Waiting for the engine — retrying at 2:05 pm");
  });

  it("writes a plain space before am / pm, also where the runtime puts a narrow no-break space", () => {
    const time = vi.spyOn(Date.prototype, "toLocaleTimeString").mockReturnValue("2:05\u202FPM");
    try {
      const line = retryLine({ provider: "groq", retry_at: new Date(2026, 10, 4, 14, 5).toISOString() }, new Date(2026, 10, 4, 13, 0));
      expect(line).toBe("Waiting for Groq — retrying at 2:05 pm");
    } finally {
      time.mockRestore();
    }
  });

  it("says nothing without a retry time (not waiting, or an older server)", () => {
    expect(retryLine({ provider: "groq", retry_at: null })).toBeNull();
    expect(retryLine({ provider: "groq" })).toBeNull();
    expect(retryLine({ provider: "groq", retry_at: "soon" })).toBeNull();
    expect(retryLine(null)).toBeNull();
  });

  it("explains why it waits", () => {
    expect(retryReason("rate_limited", "Groq")).toBe("Groq has reached its limit for now. The transcript waits for it instead of using a less accurate engine.");
    expect(retryReason("unavailable", "Groq")).toBe("Groq is not answering. The transcript waits for it instead of using a less accurate engine.");
    expect(retryReason(null, "Groq")).toBeNull();
  });
});
