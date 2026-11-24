import { describe, expect, it, vi } from "vitest";
import { MAIN_LANGUAGES, MORE_LANGUAGES, transcribedIn, englishOnlyRisk, languageChip, languageLabel, learnPrompt, menuLabel, nextLanguage, normalizeLanguage, failureLine, canTryAgain, needsKeyAction, partsText, retryLine, retryReason, scriptWarning, transcribingText, transcriptStatusLine, usedBackupEngine } from "./languages";

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

  it("names the language of the final transcript, never its engine or model, and explains a wrong script (D-041)", () => {
    expect(transcribedIn({ final_language: "ur", final_status: "completed" })).toBe("Urdu");
    expect(transcribedIn({ final_language: "en", final_status: "completed" })).toBe("English");
    expect(transcribedIn({ final_language: null, final_status: "completed" })).toBeNull();
    // Only a finished transcript is "transcribed in" a language.
    for (const status of ["processing", "pending", "failed", null]) expect(transcribedIn({ final_language: "ur", final_status: status })).toBeNull();
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
    expect(retryLine({ provider: "groq", retry_at: at(4, 14, 5) }, now)).toBe("Waiting to transcribe — retrying at 2:05 pm");
    expect(retryLine({ provider: "groq", retry_at: at(4, 9, 30) }, new Date(2026, 10, 4, 8, 0))).toBe("Waiting to transcribe — retrying at 9:30 am");
    expect(retryLine({ provider: "groq", retry_at: at(5, 1, 10) }, now)).toBe("Waiting to transcribe — retrying tomorrow at 1:10 am");
    expect(retryLine({ provider: "gemini", retry_at: at(7, 18, 0) }, now)).toBe("Waiting to transcribe — retrying Nov 7 at 6:00 pm");
    expect(retryLine({ provider: "groq", retry_at: at(4, 12, 59) }, now)).toBe("Waiting to transcribe — retrying now");
    expect(retryLine({ provider: null, retry_at: at(4, 14, 5) }, now)).toBe("Waiting to transcribe — retrying at 2:05 pm");
  });

  it("writes a plain space before am / pm, also where the runtime puts a narrow no-break space", () => {
    const time = vi.spyOn(Date.prototype, "toLocaleTimeString").mockReturnValue("2:05\u202FPM");
    try {
      const line = retryLine({ provider: "groq", retry_at: new Date(2026, 10, 4, 14, 5).toISOString() }, new Date(2026, 10, 4, 13, 0));
      expect(line).toBe("Waiting to transcribe — retrying at 2:05 pm");
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
    // D-126: plain words, never which provider.
    expect(retryReason("rate_limited")).toBe("The transcription service has reached its limit for now. The transcript waits for it instead of using a less accurate one.");
    expect(retryReason("unavailable")).toBe("The transcription service isn't answering. The transcript waits for it instead of using a less accurate one.");
    expect(retryReason(null, "Groq")).toBeNull();
  });
});

describe("the recording page's words for a transcript's state (D-052)", () => {
  it("says each state in plain words, never the server's id", () => {
    // The glossary's word (PLAN_V2_1_POLISH section 1): never "Not transcribed".
    expect(transcriptStatusLine(null)).toBe("Audio only. No transcript yet.");
    expect(transcriptStatusLine("not_started")).toBe("Audio only. No transcript yet.");
    expect(transcriptStatusLine("awaiting_device")).toBe("Waiting for the recording to finish uploading from the device that made it.");
    // A finished recording is never said to be empty here: its transcript is loading, or says it is empty itself.
    expect(transcriptStatusLine("completed")).not.toMatch(/no words|empty/i);
    expect(transcriptStatusLine("failed")).toBe("Transcription failed.");
    for (const odd of ["some_new_state", "queued_for_retry"]) {
      expect(transcriptStatusLine(odd)).toBe("The transcript isn't ready yet.");
      expect(transcriptStatusLine(odd)).not.toMatch(/_/);
    }
  });
});

describe("why a transcription failed, in the app's own words (D-130)", () => {
  it("has a plain sentence for every documented error_code and one for anything else, never a vendor", () => {
    const codes = ["no_audio", "budget_exceeded", "provider_unreachable", "provider_rejected", "provider_not_configured", "provider_timeout", "interrupted", "recording_missing", "needs_transcription_key"];
    const lines = codes.map(failureLine);
    expect(new Set(lines).size).toBe(codes.length);
    for (const line of [...lines, failureLine(null), failureLine("something_new")]) {
      expect(line).toMatch(/\.$/);
      expect(line).not.toMatch(/AssemblyAI|Gemini|ElevenLabs|Groq|Soniox|provider/i);
    }
    expect(failureLine("something_new")).toBe("Something went wrong while transcribing this recording.");
  });

  it("uses contract 1.8.1-b's sentences for a refused own key and audio not on the server", () => {
    expect(failureLine("own_key_rejected")).toBe("Groq did not accept your key. Check it in Account → Transcription key, then try again.");
    expect(failureLine("recording_missing")).toBe("The audio did not fully reach the server, or it is no longer stored.");
    expect(needsKeyAction("own_key_rejected")).toBe(true);
    expect(needsKeyAction("needs_transcription_key")).toBe(true);
    expect(needsKeyAction("provider_rejected")).toBe(false);
  });

  it("offers Try again only where trying again can fix it", () => {
    for (const code of ["no_audio", "needs_transcription_key", "own_key_rejected", "budget_exceeded", "provider_not_configured", "recording_missing"]) {
      expect(canTryAgain(code), code).toBe(false);
    }
    for (const code of ["provider_unreachable", "provider_rejected", "provider_timeout", "interrupted", "something_new", null]) {
      expect(canTryAgain(code), String(code)).toBe(true);
    }
  });
});
