import { describe, expect, it } from "vitest";
import { decisionText, explainMicUser, micTone } from "./explainer";
import type { IgnoreReason, MicDecision, MicUser } from "./ipc";
import { makeMicUser } from "../test/fixtures";
import detectRs from "../../src-tauri/src/detect/mod.rs?raw";

describe("explainMicUser (A1.8)", () => {
  it("reads like the contract for a Meet tab that will be recorded", () => {
    expect(explainMicUser(makeMicUser())).toBe("Holds mic ✓ · Playing audio ✓ · Identified as Google Meet · Decision: auto-record");
  });

  it("says Recording now for the tree being recorded", () => {
    expect(explainMicUser(makeMicUser({ decision: "recording" }))).toBe("Holds mic ✓ · Playing audio ✓ · Identified as Google Meet · Recording now");
  });

  it("marks a silent app with ✗ and leaves out Identified as when the app is unknown", () => {
    expect(explainMicUser(makeMicUser({ app: null, site: null, playsAudio: false, decision: "ignored", reason: "unknown_app" }))).toBe(
      "Holds mic ✓ · Playing audio ✗ · Ignored: not a call app",
    );
    expect(explainMicUser(makeMicUser({ holdsMic: false, decision: "ask" }))).toBe("Holds mic ✗ · Playing audio ✓ · Identified as Google Meet · Will ask you");
  });

  it("uses display labels for every source app", () => {
    expect(explainMicUser(makeMicUser({ app: "microsoft_teams" }))).toContain("Identified as Microsoft Teams");
    expect(explainMicUser(makeMicUser({ app: "whatsapp_web" }))).toContain("Identified as WhatsApp Web");
  });
});

describe("decisionText", () => {
  it.each<[MicDecision, IgnoreReason | null, string]>([
    ["auto_record", null, "Decision: auto-record"],
    ["recording", null, "Recording now"],
    ["ask", null, "Will ask you"],
    ["off", "auto_off", "Auto-record is off"],
    ["off", null, "Auto-record is off"],
    ["ignored", "dictation", "Ignored: dictation (no audio playing)"],
    ["ignored", "unknown_site", "Ignored: unknown site"],
    ["ignored", "unknown_app", "Ignored: not a call app"],
    ["ignored", "never_record", "Ignored: on your never-record list"],
    ["ignored", "auto_off", "Auto-record is off"],
    ["ignored", null, "Ignored"],
  ])("%s / %s → %s", (decision, reason, text) => {
    expect(decisionText({ decision, reason })).toBe(text);
  });

  it("falls back to Ignored for a value a newer core might send", () => {
    expect(decisionText({ decision: "later" as MicDecision, reason: null })).toBe("Ignored");
    expect(decisionText({ decision: "ignored", reason: "muted" as IgnoreReason })).toBe("Ignored");
  });

  it("knows every decision string the Rust explainer produces", () => {
    // `explain()` in src-tauri/src/detect/mod.rs maps each verdict to a ("decision", reason) pair.
    const start = detectRs.indexOf("pub fn explain(");
    expect(start, "detect/mod.rs has no pub fn explain").toBeGreaterThan(-1);
    const body = detectRs.slice(start, detectRs.indexOf("\n}\n", start));
    const produced = new Set([...body.matchAll(/\("([a-z_]+)",/g)].map((m) => m[1]));
    expect(produced.size).toBeGreaterThanOrEqual(4);
    const known: MicDecision[] = ["auto_record", "recording", "ignored", "ask", "off"];
    for (const decision of produced) expect(known, `explain() produces "${decision}"`).toContain(decision);
  });
});

describe("micTone", () => {
  it.each<[MicUser["decision"], string]>([
    ["recording", "live"],
    ["auto_record", "ok"],
    ["ask", "ask"],
    ["ignored", "idle"],
    ["off", "idle"],
  ])("%s → %s", (decision, tone) => {
    expect(micTone({ decision })).toBe(tone);
  });
});
