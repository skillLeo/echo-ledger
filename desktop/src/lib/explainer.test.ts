import { describe, expect, it } from "vitest";
import { decisionText, lastNotRecordedLine, micTone, micUserName, notRecorded, seenWhen } from "./explainer";
import type { IgnoreReason, MicDecision } from "./ipc";
import { makeMicUser } from "../test/fixtures";
import detectRs from "../../src-tauri/src/detect/mod.rs?raw";
import decideRs from "../../src-tauri/src/detect/decide.rs?raw";

describe("why a call was or wasn't recorded, in plain words (A1.8, D-014)", () => {
  it("says what EchoLedger does with each app, and why when it doesn't record it", () => {
    expect(decisionText(makeMicUser({ decision: "auto_record" }))).toBe("Will be recorded");
    expect(decisionText(makeMicUser({ decision: "recording" }))).toBe("Recorded now");
    expect(decisionText(makeMicUser({ decision: "ask" }))).toBe("Will ask you");
    expect(decisionText(makeMicUser({ decision: "off" }))).toBe("Not recorded: auto-record is off");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "dictation" }))).toBe("Not recorded: no sound is playing, so it looks like dictation");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "unknown_site" }))).toBe("Not recorded: this website isn't one of your call sites");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "never_record" }))).toBe("Not recorded: it's on your never-record list");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "unknown_app" }))).toBe("Not recorded: it isn't a call app EchoLedger knows");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "auto_off" }))).toBe("Not recorded: auto-record is off");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: "app_off" }))).toBe("Not recorded: auto-record is off for this app");
    expect(decisionText(makeMicUser({ decision: "ignored", reason: null }))).toBe("Not recorded");
  });

  it.each<[MicDecision, string]>([
    ["recording", "live"],
    ["auto_record", "ok"],
    ["ask", "ask"],
    ["ignored", "idle"],
    ["off", "idle"],
  ])("colours the dot of %s %s", (decision, tone) => {
    expect(micTone({ decision })).toBe(tone);
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

describe("every decision and reason the core sends has its own words (D-014)", () => {
  // explain() in src-tauri/src/detect/mod.rs turns each verdict into a ("decision", reason) pair.
  const start = detectRs.indexOf("pub fn explain(");
  const body = detectRs.slice(start, detectRs.indexOf("\n}\n", start));
  const decisions = [...new Set([...body.matchAll(/\("([a-z_]+)",/g)].map((m) => m[1]))];
  // The reasons: the core's own names (IgnoreReason::as_str in detect/decide.rs) and the list MicUser's doc gives.
  const asStr = decideRs.slice(decideRs.indexOf("impl IgnoreReason"));
  const reasons = [...asStr.slice(0, asStr.indexOf("\n}\n")).matchAll(/IgnoreReason::\w+ => "([a-z_]+)"/g)].map((m) => m[1]);
  const documented = [...(detectRs.split("\n").find((line) => line.includes("When ignored:")) ?? "").matchAll(/"([a-z_]+)"/g)].map((m) => m[1]);

  it("reads them from the core's source", () => {
    expect(start, "detect/mod.rs has no pub fn explain").toBeGreaterThan(-1);
    expect(decisions).toEqual(expect.arrayContaining(["recording", "auto_record", "ask", "off", "ignored"]));
    expect(reasons.length, "IgnoreReason::as_str was not found").toBeGreaterThanOrEqual(6);
    expect([...documented].sort(), "MicUser's doc lists every reason").toEqual([...reasons].sort());
  });

  it("says what happens for every decision explain() produces, never just \"Not recorded\"", () => {
    for (const decision of decisions) {
      // An ignored app's words come from its reason, below.
      if (decision === "ignored") continue;
      expect(decisionText({ decision: decision as MicDecision, reason: null }), `explain() produces "${decision}"`).not.toBe("Not recorded");
    }
  });

  it("has words of its own for every reason the core names", () => {
    const said = reasons.map((reason) => decisionText({ decision: "ignored", reason: reason as IgnoreReason }));
    for (const [i, text] of said.entries()) expect(text, `the core names "${reasons[i]}"`).toMatch(/^Not recorded: \S/);
    expect(new Set(said).size, "two reasons read the same").toBe(reasons.length);
  });
});

describe("the last app not recorded, as the core kept it (D-014)", () => {
  const now = new Date(2026, 10, 17, 15, 0);
  const at = new Date(2026, 10, 17, 14, 5).toISOString();

  it("says when it was last seen, by Settings' clock", () => {
    expect(seenWhen(at, now)).toBe("at 2:05 pm");
    expect(seenWhen(new Date(2026, 10, 16, 9, 30).toISOString(), now)).toBe("yesterday at 9:30 am");
    expect(seenWhen(new Date(2026, 10, 9, 18, 0).toISOString(), now)).toBe("on 9 Nov at 6:00 pm");
    expect(seenWhen("not a time", now)).toBe("");
  });

  it("says which app, when and why, in one line", () => {
    const slack = makeMicUser({ name: "slack.exe", app: null, site: null, decision: "ignored", reason: "unknown_app" });
    expect(lastNotRecordedLine({ user: slack, at }, now)).toBe("Last not recorded: Slack at 2:05 pm, because it isn't a call app EchoLedger knows.");
    expect(lastNotRecordedLine({ user: makeMicUser({ decision: "off" }), at }, now)).toBe("Last not recorded: Google Meet at 2:05 pm, because auto-record is off.");
    expect(lastNotRecordedLine({ user: makeMicUser({ decision: "ignored", reason: null }), at }, now)).toBe("Last not recorded: Google Meet at 2:05 pm.");
  });
});
