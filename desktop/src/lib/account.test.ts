// The words and checks of your account from the app (contract 2.0.0-l): every field and refusal code in English and
// Urdu, the name and password checks made before sending, and the proof rule (two-step on: a code, never a password).
import { describe, expect, it } from "vitest";
import {
  ACCOUNT_WORDS, fieldErrors, fieldText, nameProblem, passwordProblems, proofAfter, proofBody, proofFor, replyText, rulesLine, spacedSecret,
  type AccountReply,
} from "./account";

const reply = (code: string, extra: Partial<AccountReply> = {}): AccountReply =>
  ({ ok: false, status: 422, data: null, code, message: "The server's own words.", accepts: [], errorCodes: null, errors: null, retryAfter: null, qr: null, ...extra });

describe("the words", () => {
  const FIELD_CODES = ["required", "too_short", "too_long", "needs_letters", "needs_numbers", "same_as_current", "invalid_characters", "invalid_email", "same_as_now", "email_taken"];
  const REFUSALS = ["wrong_password", "invalid_otp", "invalid_code", "proof_required", "email_taken", "expired", "already_enabled", "not_enabled", "required_for_staff",
    "last_sign_in_method", "passkey_not_found", "device_not_found", "browser_not_found", "current_device", "proof_code_not_needed", "too_large", "server_busy",
    "too_many_attempts", "rate_limited"];

  it("has every contract code in English and Urdu", () => {
    for (const lang of ["en", "ur"] as const) {
      for (const code of FIELD_CODES) expect(fieldText({ code, min: 12, max: 120 }, lang), `${lang} ${code}`).toBeTruthy();
      for (const code of REFUSALS) expect(ACCOUNT_WORDS[lang][code], `${lang} ${code}`).toBeTruthy();
      for (const reason of ["format", "broken", "too_small", "ratio", "too_many_pixels"]) expect(fieldText({ code: "invalid_image", reason }, lang)).toBeTruthy();
    }
    expect(Object.keys(ACCOUNT_WORDS.ur).sort()).toEqual(Object.keys(ACCOUNT_WORDS.en).sort());
  });

  it("fills in the numbers and the wait, in either language", () => {
    expect(fieldText({ code: "too_short", min: 12 }, "en")).toBe("Use at least 12 characters.");
    expect(fieldText({ code: "too_short", min: 12 }, "ur")).toBe("کم از کم 12 حروف استعمال کریں۔");
    expect(replyText(reply("too_many_attempts", { status: 429, retryAfter: 900 }), "en")).toBe("Too many tries. Wait 15 minutes, then try again.");
    expect(replyText(reply("rate_limited", { status: 429, retryAfter: 30 }), "ur")).toContain("30 سیکنڈ");
  });

  it("says field codes before the server's own sentence, and the sentence for a field without a code", () => {
    const r = reply("validation_failed", { errorCodes: { new: [{ code: "needs_numbers" }] }, errors: { new: ["The password must contain a number."], current: ["The current field is required."] } });
    expect(fieldErrors(r, "en")).toEqual({ new: "Add at least one number.", current: "The current field is required." });
    expect(replyText(reply("something_new"), "en")).toBe("The server's own words.");
  });
});

describe("checks before sending", () => {
  it("takes names in any script with ZWNJ, ZWJ, LRM and RLM, and refuses direction overrides and isolates", () => {
    for (const ok of ["Ayesha Siddiqui", "عائشہ‌صدیقی", "O'Brien-Smith (Jr.), 2", "ملک‏ ali"]) expect(nameProblem(ok), ok).toBeNull();
    expect(nameProblem("  ")).toEqual({ code: "required" });
    expect(nameProblem("x".repeat(121))).toEqual({ code: "too_long", max: 120 });
    for (const bad of ["a‮b", "a⁦b", "a<b>", "a\u0007"]) expect(nameProblem(bad), bad).toEqual({ code: "invalid_characters" });
    expect(nameProblem("x".repeat(81), 80)).toEqual({ code: "too_long", max: 80 });
  });

  it("checks a new password against the server's rules", () => {
    expect(passwordProblems("short1").map((p) => p.code)).toEqual(["too_short"]);
    expect(passwordProblems("onlyletterslong").map((p) => p.code)).toEqual(["needs_numbers"]);
    expect(passwordProblems("123456789012").map((p) => p.code)).toEqual(["needs_letters"]);
    expect(passwordProblems("a much longer one 2026")).toEqual([]);
    expect(passwordProblems("abcd", { min: 4, letters: true, numbers: false })).toEqual([]);
    expect(rulesLine({ min: 12, letters: true, numbers: true }, "en")).toBe("At least 12 characters, with letters and numbers.");
  });
});

describe("the proof", () => {
  it("is a two-step code when two-step is on, else the password, else a code by email", () => {
    expect(proofFor({ two_factor: true, has_password: true })).toBe("otp");
    expect(proofFor({ two_factor: false, has_password: true })).toBe("password");
    expect(proofFor({ two_factor: false, has_password: null })).toBe("password");
    expect(proofFor({ two_factor: false, has_password: false })).toBe("email_code");
  });

  it("switches to what the server accepts, and sends codes without spaces", () => {
    expect(proofAfter(reply("proof_required", { accepts: ["otp"] }), "password")).toBe("otp");
    expect(proofAfter(reply("invalid_otp", { accepts: ["otp"] }), "password")).toBe("otp");
    expect(proofAfter(reply("wrong_password"), "password")).toBe("password");
    expect(proofAfter(reply("proof_required", { accepts: ["password", "otp"] }), "otp")).toBe("otp");
    expect(proofBody("otp", "123 456")).toEqual({ otp: "123456" });
    expect(proofBody("password", " pass word ")).toEqual({ password: " pass word " });
    expect(spacedSecret("JBSWY3DPEHPK3PXP")).toBe("JBSW Y3DP EHPK 3PXP");
  });
});
