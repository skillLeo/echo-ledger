import { describe, expect, it } from "vitest";
import {
  afterCallTitle,
  alwaysIgnoreLabel,
  askSeconds,
  askSubject,
  elapsedAt,
  formatTimer,
  isTwoChannel,
  meterLevel,
  missingShort,
  nextAnchor,
  reviewDuration,
  secondsLeft,
  sourceLabel,
} from "./controls";
import { makeAskPayload, makePendingItem, makeRecordingState } from "../test/fixtures";

describe("pill timer", () => {
  it("always shows seconds: m:ss under an hour, h:mm:ss from an hour", () => {
    expect(formatTimer(0)).toBe("0:00");
    expect(formatTimer(999)).toBe("0:00");
    expect(formatTimer(1_000)).toBe("0:01");
    expect(formatTimer(65_000)).toBe("1:05");
    expect(formatTimer(599_999)).toBe("9:59");
    expect(formatTimer(3_599_000)).toBe("59:59");
    expect(formatTimer(3_600_000)).toBe("1:00:00");
    expect(formatTimer(3_725_000)).toBe("1:02:05");
    expect(formatTimer(36_000_000 + 61_000)).toBe("10:01:01");
  });

  it("never shows negative or non-numeric time", () => {
    expect(formatTimer(-5_000)).toBe("0:00");
    expect(formatTimer(Number.NaN)).toBe("0:00");
    expect(formatTimer(Number.POSITIVE_INFINITY)).toBe("0:00");
  });

  it("runs on between the core's events and stands still while paused", () => {
    const running = nextAnchor(null, makeRecordingState({ elapsedMs: 10_000 }), 1_000);
    expect(elapsedAt(running, 1_000)).toBe(10_000);
    expect(elapsedAt(running, 1_750)).toBe(10_750);
    const paused = nextAnchor(running, makeRecordingState({ elapsedMs: 10_800, paused: true }), 1_800);
    expect(elapsedAt(paused, 1_800)).toBe(10_800);
    expect(elapsedAt(paused, 60_000)).toBe(10_800);
    const resumed = nextAnchor(paused, makeRecordingState({ elapsedMs: 10_800 }), 70_000);
    expect(elapsedAt(resumed, 70_500)).toBe(11_300);
  });

  it("does not step a running timer back for an event that arrives a little late", () => {
    const anchor = nextAnchor(null, makeRecordingState({ elapsedMs: 5_000 }), 0);
    const late = nextAnchor(anchor, makeRecordingState({ elapsedMs: 5_900 }), 1_000);
    expect(elapsedAt(late, 1_000)).toBe(6_000);
  });

  it("takes the core's value for large corrections, a new recording and the end", () => {
    const anchor = nextAnchor(null, makeRecordingState({ elapsedMs: 300_000 }), 0);
    expect(elapsedAt(nextAnchor(anchor, makeRecordingState({ elapsedMs: 0 }), 10), 10)).toBe(0);
    expect(elapsedAt(nextAnchor(anchor, makeRecordingState({ elapsedMs: 310_000 }), 1_000), 1_000)).toBe(310_000);
    const stopped = nextAnchor(anchor, makeRecordingState({ active: false, elapsedMs: 301_000 }), 1_000);
    expect(elapsedAt(stopped, 5_000)).toBe(0);
    expect(elapsedAt(null, 5_000)).toBe(0);
  });

  it("scales levels like the Record view meters and clamps them", () => {
    expect(meterLevel(0)).toBe(0);
    expect(meterLevel(0.1)).toBeCloseTo(0.4);
    expect(meterLevel(0.25)).toBe(1);
    expect(meterLevel(0.9)).toBe(1);
    expect(meterLevel(-1)).toBe(0);
    expect(meterLevel(Number.NaN)).toBe(0);
  });

  it("shows Me/Client meters only for two-channel recordings", () => {
    expect(isTwoChannel("call")).toBe(true);
    expect(isTwoChannel(null)).toBe(true);
    expect(isTwoChannel("voice_note")).toBe(false);
    expect(isTwoChannel("mic_note")).toBe(false);
  });
});

describe("labels", () => {
  it("uses the display labels and never a raw id", () => {
    expect(sourceLabel("zoom")).toBe("Zoom");
    expect(sourceLabel("google_meet")).toBe("Google Meet");
    expect(sourceLabel("voice_note")).toBe("Voice note");
    expect(sourceLabel("whatsapp_web")).toBe("WhatsApp Web");
    expect(sourceLabel("microsoft_teams")).toBe("Microsoft Teams");
    expect(sourceLabel(null)).toBe("Other");
    expect(sourceLabel("")).toBe("Other");
    expect(sourceLabel("some_new_app")).toBe("Some new app");
  });

  it("names a recording after a call by its title, the caller, the app, or 'Voice note'", () => {
    expect(afterCallTitle(makePendingItem("p", { title: "Budget review", callerName: "Omar" }))).toBe("Budget review");
    expect(afterCallTitle(makePendingItem("p", { title: "   ", callerName: " Omar Khan " }))).toBe("Call with Omar Khan");
    expect(afterCallTitle(makePendingItem("p"))).toBe("Zoom");
    expect(afterCallTitle(makePendingItem("p", { sourceApp: "whatsapp", callerName: "" }))).toBe("WhatsApp");
    expect(afterCallTitle(makePendingItem("p", { sourceApp: "other" }))).toBe("Call");
    expect(afterCallTitle(makePendingItem("p", { sourceApp: "voice_note", mode: "voice_note", callerName: "Omar" }))).toBe("Voice note");
    expect(afterCallTitle(makePendingItem("p", { sourceApp: "voice_note", mode: "mic_note" }))).toBe("Voice note");
    expect(afterCallTitle({ title: "کال اسلم کے ساتھ", sourceApp: "whatsapp", mode: "call" })).toBe("کال اسلم کے ساتھ");
  });

  it("formats the length, with 0:00 for an empty recording", () => {
    expect(reviewDuration(750_000)).toBe("12:30");
    expect(reviewDuration(3_725_000)).toBe("1:02:05");
    expect(reviewDuration(0)).toBe("0:00");
  });
});

describe("chip countdown", () => {
  const deadline = "2026-10-24T09:13:30Z";
  const at = (iso: string) => Date.parse(iso);

  it("counts the seconds left down to zero, fractions included", () => {
    expect(secondsLeft(deadline, at("2026-10-24T09:13:20Z"))).toBe(10);
    expect(secondsLeft(deadline, at("2026-10-24T09:13:27.5Z"))).toBe(2.5);
    expect(secondsLeft(deadline, at("2026-10-24T09:14:00Z"))).toBe(0);
    expect(secondsLeft(null, 0)).toBeNull();
    expect(secondsLeft("not a date", 0)).toBeNull();
  });
});

describe("ask chip copy", () => {
  it("names the site for a browser and the app otherwise", () => {
    const browser = makeAskPayload({ name: "chrome.exe", site: "call.example.test" });
    expect(askSubject(browser)).toBe("call.example.test");
    expect(alwaysIgnoreLabel(browser)).toBe("Always ignore this site");
    const app = makeAskPayload({ name: "Softphone.exe", site: null });
    expect(askSubject(app)).toBe("Softphone"); // the app by name, not its file name (C8)
    expect(alwaysIgnoreLabel(app)).toBe("Always ignore this app");
  });

  it("stays for the payload's seconds, 8 when missing", () => {
    expect(askSeconds(makeAskPayload({ seconds: 8 }))).toBe(8);
    expect(askSeconds(makeAskPayload({ seconds: 5 }))).toBe(5);
    expect(askSeconds(makeAskPayload({ seconds: 0 }))).toBe(8);
    expect(askSeconds(makeAskPayload({ seconds: Number.NaN }))).toBe(8);
  });
});

describe("missingShort", () => {
  it("says in one short line what the recording holds", () => {
    expect(missingShort(undefined)).toBeNull();
    expect(missingShort([])).toBeNull();
    expect(missingShort([{ kind: "system_audio", sentence: "" }])).toMatch(/your voice only/);
    expect(missingShort([{ kind: "microphone", sentence: "" }])).toMatch(/recording the other side/);
    expect(missingShort([{ kind: "microphone", sentence: "" }, { kind: "system_audio", sentence: "" }])).toMatch(/Nothing is being recorded/);
    expect(missingShort([{ kind: "screen", sentence: "" }])).toBeNull();
  });
});
