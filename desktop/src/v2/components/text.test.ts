import { describe, expect, it } from "vitest";
import { firstSentence } from "./text";

describe("firstSentence: a row's snippet is the first meaningful sentence (D12)", () => {
  it("drops the labels, skips the short utterances and cuts at the first sentence end", () => {
    expect(firstSentence("Client: Hello, can you hear me now? Me: the line. Client: Is the shipment on time?…")).toBe("Hello, can you hear me now?");
    expect(firstSentence("Me: Thank you. Client: Assalam o alaikum, is this a good time to talk? Client: Is the order ready?")).toBe("Assalam o alaikum, is this a good time to talk?");
  });

  it("gives null when nothing is left, for the caller's \"No speech\"", () => {
    expect(firstSentence("Speaker: .")).toBeNull();
    expect(firstSentence("Speaker: . Me: Yeah. Client: Hello.")).toBeNull();
    expect(firstSentence("")).toBeNull();
    expect(firstSentence("   ")).toBeNull();
    expect(firstSentence(null)).toBeNull();
    expect(firstSentence(undefined)).toBeNull();
    expect(firstSentence("…?!")).toBeNull();
  });

  it("splits Urdu at the next label even with no full stop before it, and keeps the Urdu words", () => {
    expect(firstSentence("Speaker: آرڈر کب تک پہنچ جائے گا یہ بتا دیں Speaker: …")).toBe("آرڈر کب تک پہنچ جائے گا یہ بتا دیں");
    expect(firstSentence("مقرر: ٹھیک۔ مقرر: میں کل آپ کو فائل بھیج دوں گا۔ باقی بعد میں")).toBe("میں کل آپ کو فائل بھیج دوں گا۔");
    expect(firstSentence("مقرر: کیا آپ نے رپورٹ دیکھی؟ مقرر: ہاں")).toBe("کیا آپ نے رپورٹ دیکھی؟");
  });

  it("keeps mixed Urdu and English, and never shows the app's own labels (Speaker, Me, Client)", () => {
    expect(firstSentence("Me: ذرا دوبارہ بتائیں، اب ٹھیک ہے continue کریں Client: جی ڈیٹا")).toBe("ذرا دوبارہ بتائیں، اب ٹھیک ہے continue کریں");
    const out = firstSentence("Me: ok Client: we can start with the invoices Speaker: sure");
    expect(out).toBe("we can start with the invoices");
    expect(out).not.toMatch(/Speaker:|Client:|Me:/);
  });

  it("reads labels with brackets and spaces, up to 24 characters", () => {
    expect(firstSentence("Sara (Acme): Hi. Bilal Qureshi: The second container leaves Karachi on the 14th. Sara (Acme): Good.")).toBe("The second container leaves Karachi on the 14th.");
    expect(firstSentence("Sara (Acme): Hi there. Sara (Acme): The balance is due by the end of March.")).toBe("The balance is due by the end of March.");
    expect(firstSentence("[Host]: Welcome everyone to the call. [Guest]: Thanks.")).toBe("Welcome everyone to the call.");
    // Longer than 24 before the colon: not a label, so it stays as words.
    expect(firstSentence("The agenda for the whole quarter: hiring, budget and travel.")).toBe("The agenda for the whole quarter: hiring, budget and travel.");
  });

  it("takes a preview without labels as one utterance, and leaves a long one to the ellipsis", () => {
    expect(firstSentence("We agreed on the price. Delivery is next week.")).toBe("We agreed on the price.");
    const long = "the second container leaves Karachi on the fourteenth so the balance is due by the end of March and";
    expect(firstSentence(long)).toBe(long);
  });

  it("doesn't cut inside a number, and keeps a run of marks", () => {
    expect(firstSentence("Client: The rate went up 3.5 percent this month. Me: Okay.")).toBe("The rate went up 3.5 percent this month.");
    expect(firstSentence("Client: Can you really do that?! Me: Yes.")).toBe("Can you really do that?!");
  });

  it("treats a colon inside a sentence as words, not a label, when it doesn't open an utterance", () => {
    expect(firstSentence("Client: The time is 5:30 for the call today. Me: Fine.")).toBe("The time is 5:30 for the call today.");
    expect(firstSentence("Client: We need three things: a price, a date and a truck.")).toBe("We need three things: a price, a date and a truck.");
  });
  it("drops numbered speaker labels too", () => {
    expect(firstSentence("Speaker 1: Can you send the estimate by Monday? Speaker 2: Yes.")).toBe("Can you send the estimate by Monday?");
  });
  it("drops a numbered label inside Urdu with no full stop before it", () => {
    expect(firstSentence("Speaker 1: آرڈر کب تک پہنچ جائے گا یہ بتا دیں Speaker 2: جی")).toBe("آرڈر کب تک پہنچ جائے گا یہ بتا دیں");
  });
  it("keeps a phrase before a colon that is not a speaker", () => {
    expect(firstSentence("Yes. Next step: send the estimate today.")).toBe("Next step: send the estimate today.");
  });
  it("ignores direction marks around a label", () => {
    expect(firstSentence("\u200FSpeaker:\u200F کل کی میٹنگ کا وقت بدل گیا ہے۔")).toBe("کل کی میٹنگ کا وقت بدل گیا ہے۔");
  });
  it("skips a short sentence for the next one", () => {
    expect(firstSentence("Me: Thanks. I will send it tomorrow.")).toBe("I will send it tomorrow.");
    // A phrase before a colon after a full stop is words unless it looks like a name (review: it dropped them).
    expect(firstSentence("Client: Okay. The main issue is this: the invoice is late.")).toBe("The main issue is this: the invoice is late.");
    expect(firstSentence("Me: Thanks. I will send it to you: tomorrow.")).toBe("I will send it to you: tomorrow.");
  });
});
