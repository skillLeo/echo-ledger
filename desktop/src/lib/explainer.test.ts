import { describe, expect, it } from "vitest";
import { decisionText, micTone, micUserName, notRecorded } from "./explainer";
import { makeMicUser } from "../test/fixtures";

describe("why a call was or wasn't recorded, in plain words (A1.8, D-014)", () => {
  it("says what EchoLedger does with each app, and why when it doesn't record it", () => {
    expect(decisionText(makeMicUser({ decision: "auto_record" }))).toBe("Will be recorded");
    expect(decisionText(makeMicUser({ decision: "recording" }))).toBe("Recorded now");
    expect(decisionText(makeMicUser({ decision: "ask" }))).toBe("Will ask you");
    expect(decisionText(makeMicUser({ decision: "off" }))).toBe("Not recorded: auto-record is off");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "dictation" }))).toBe("Not recorded: no sound is playing, so it looks like dictation");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "unknown_site" }))).toBe("Not recorded: this website isn't one of your call sites");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "never_record" }))).toBe("Not recorded: it's on your never-record list");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: null }))).toBe("Not recorded");
  });

  it("never says how sound is captured", () => {
    for (const decision of ["auto_record", "recording", "ask", "off", "ignored"] as const) {
      for (const reason of [null, "dictation", "unknown_site", "unknown_app", "never_record", "auto_off", "app_off"] as const) {
        expect(decisionText(makeMicUser({ decision, reason }))).not.toMatch(/holds mic|playing audio|loopback|tap|✓|✗/i);
      }
    }
  });

  it("names the app as people know it, and tells which ones were left unrecorded", () => {
    expect(micUserName(makeMicUser({ app: "google_meet" }))).toBe("Google Meet");
    expect(micUserName(makeMicUser({ app: null, site: "meet.example.com", name: "chrome.exe" }))).toBe("meet.example.com");
    expect(micUserName(makeMicUser({ app: null, site: null, name: "C:\\\\Program Files\\\\Slack\\\\slack.exe" }))).toBe("Slack");
    expect(notRecorded(makeMicUser({ decision: "ignored" }))).toBe(true);
    expect(notRecorded(makeMicUser({ decision: "off" }))).toBe(true);
    expect(notRecorded(makeMicUser({ decision: "ask" }))).toBe(false);
    expect(micTone(makeMicUser({ decision: "recording" }))).toBe("live");
    expect(micTone(makeMicUser({ decision: "ignored" }))).toBe("idle");
  });
});
