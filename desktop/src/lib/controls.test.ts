import { describe, expect, it } from "vitest";
import {
  alwaysIgnoreLabel,
  askSeconds,
  askSubject,
  countdownText,
  elapsedAt,
  formatTimer,
  isTwoChannel,
  meterLevel,
  nextAnchor,
  reviewDuration,
  reviewTitle,
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

  it("titles a review item by its title, else '<Label> call', and voice notes 'Voice note'", () => {
    expect(reviewTitle(makePendingItem("p", { title: "Budget review" }))).toBe("Budget review");
    expect(reviewTitle(makePendingItem("p", { title: "   " }))).toBe("Zoom call");
    expect(reviewTitle(makePendingItem("p"))).toBe("Zoom call");
    expect(reviewTitle(makePendingItem("p", { sourceApp: "whatsapp" }))).toBe("WhatsApp call");
    expect(reviewTitle(makePendingItem("p", { sourceApp: "voice_note", mode: "voice_note" }))).toBe("Voice note");
    expect(reviewTitle(makePendingItem("p", { sourceApp: "voice_note", mode: "mic_note" }))).toBe("Voice note");
  });

  it("formats the review duration, with 0:00 for an empty recording", () => {
    expect(reviewDuration(750_000)).toBe("12:30");
    expect(reviewDuration(3_725_000)).toBe("1:02:05");
    expect(reviewDuration(0)).toBe("0:00");
  });
});

describe("review countdown", () => {
  const deadline = "2026-10-24T09:13:30Z";
  const at = (iso: string) => Date.parse(iso);

  it("counts whole seconds down to zero", () => {
    expect(secondsLeft(deadline, at("2026-10-24T09:12:48Z"))).toBe(42);
    expect(secondsLeft(deadline, at("2026-10-24T09:12:48Z") + 1)).toBe(42);
    expect(secondsLeft(deadline, at("2026-10-24T09:13:29.5Z"))).toBe(1);
    expect(secondsLeft(deadline, at("2026-10-24T09:14:00Z"))).toBe(0);
    expect(secondsLeft(null, 0)).toBeNull();
    expect(secondsLeft("not a date", 0)).toBeNull();
  });

  it("says what happens when the time runs out, per policy", () => {
    const now = at("2026-10-24T09:12:48Z");
    expect(countdownText("keep", deadline, now)).toBe("Keeping in 42 s");
    expect(countdownText("discard", deadline, now)).toBe("Discarding in 42 s");
    expect(countdownText("wait", deadline, now)).toBeNull();
    expect(countdownText("keep", null, now)).toBeNull();
  });
});

describe("ask chip copy", () => {
  it("names the site for a browser and the app otherwise", () => {
    const browser = makeAskPayload({ name: "chrome.exe", site: "call.example.test" });
    expect(askSubject(browser)).toBe("call.example.test");
    expect(alwaysIgnoreLabel(browser)).toBe("Always ignore this site");
    const app = makeAskPayload({ name: "Softphone.exe", site: null });
    expect(askSubject(app)).toBe("Softphone.exe");
    expect(alwaysIgnoreLabel(app)).toBe("Always ignore this app");
  });

  it("stays for the payload's seconds, 8 when missing", () => {
    expect(askSeconds(makeAskPayload({ seconds: 8 }))).toBe(8);
    expect(askSeconds(makeAskPayload({ seconds: 5 }))).toBe(5);
    expect(askSeconds(makeAskPayload({ seconds: 0 }))).toBe(8);
    expect(askSeconds(makeAskPayload({ seconds: Number.NaN }))).toBe(8);
  });
});
