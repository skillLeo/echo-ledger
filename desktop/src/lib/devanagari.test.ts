import { describe, expect, it } from "vitest";
import { devanagariToUrdu, hasDevanagari } from "./devanagari";

describe("Hindi letters in live captions", () => {
  it("writes common Urdu speech in Urdu script", () => {
    expect(devanagariToUrdu("आप कैसे हैं")).toBe("آپ کیسے ہیں");
    expect(devanagariToUrdu("मैं ठीक हूँ")).toBe("میں ٹھیک ہوں");
    expect(devanagariToUrdu("क्या बात है")).toBe("کیا بات ہے");
    expect(devanagariToUrdu("नहीं")).toBe("نہیں");
    expect(devanagariToUrdu("ज़रूर")).toBe("زرور");
  });

  it("keeps English words, Urdu script and digits, and turns the danda into an Urdu full stop", () => {
    expect(devanagariToUrdu("meeting कल है।")).toBe("meeting کل ہے۔");
    expect(devanagariToUrdu("آپ کیسے ہیں")).toBe("آپ کیسے ہیں");
    expect(devanagariToUrdu("१२३")).toBe("123");
    expect(hasDevanagari("hello")).toBe(false);
  });
});
