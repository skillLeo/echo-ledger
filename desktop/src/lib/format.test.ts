import { describe, expect, it } from "vitest";
import {
  acceleratorFromEvent,
  formatDuration,
  formatWhen,
  prettyShortcut,
  SOURCE_APP_LABELS,
  SOURCE_DEVICE_LABELS,
  timestamp,
  transcriptText,
} from "./format";

describe("formatDuration", () => {
  it("is blank for empty or non-positive input", () => {
    expect(formatDuration(undefined)).toBe("");
    expect(formatDuration(0)).toBe("");
    expect(formatDuration(-5)).toBe("");
  });

  it("formats minutes:seconds and hours:minutes:seconds", () => {
    expect(formatDuration(5_000)).toBe("0:05");
    expect(formatDuration(125_000)).toBe("2:05");
    expect(formatDuration(3_725_000)).toBe("1:02:05");
  });
});

describe("formatWhen", () => {
  const now = new Date("2026-10-24T12:00:00");

  it("labels today and yesterday", () => {
    expect(formatWhen("2026-10-24T09:30:00", now).startsWith("Today")).toBe(true);
    expect(formatWhen("2026-10-23T09:30:00", now).startsWith("Yesterday")).toBe(true);
  });

  it("uses a 12-hour clock with AM/PM", () => {
    expect(formatWhen("2026-10-24T09:30:00", now)).toMatch(/^Today · 9:30\s?AM$/);
    expect(formatWhen("2026-10-24T18:05:00", now)).toMatch(/^Today · 6:05\s?PM$/);
    expect(formatWhen("2026-10-24T00:10:00", now)).toMatch(/12:10\s?AM$/);
  });

  it("falls back to a dated label and tolerates junk", () => {
    // The month-and-day part follows the viewer's locale ("Oct 1" in en-US).
    const monthDay = new Date(2026, 9, 1).toLocaleDateString([], { month: "short", day: "numeric" });
    expect(formatWhen("2026-10-01T09:30:00", now).startsWith(`${monthDay} · `)).toBe(true);
    expect(formatWhen("not a date", now)).toBe("");
    expect(formatWhen(undefined, now)).toBe("");
  });
});

describe("timestamp", () => {
  it("renders session-relative m:ss and switches to h:mm:ss", () => {
    expect(timestamp(0)).toBe("0:00");
    expect(timestamp(65_000)).toBe("1:05");
    expect(timestamp(3_725_000)).toBe("1:02:05");
  });
});

describe("transcriptText", () => {
  const call = [
    { speaker: "Me", start_ms: 0, text: "Hello there." },
    { speaker: "Client", start_ms: 12_400, text: "Hi, thanks for calling." },
  ];

  it("keeps speaker labels for a two-party call", () => {
    expect(transcriptText(call, false)).toBe("Me: Hello there.\nClient: Hi, thanks for calling.");
    expect(transcriptText(call, true)).toBe("[0:00] Me: Hello there.\n[0:12] Client: Hi, thanks for calling.");
  });

  it("drops the label when there is a single speaker", () => {
    const note = [{ speaker: "Speaker", start_ms: 0, text: "A note." }, { speaker: "Speaker", start_ms: 1500, text: "More." }];
    expect(transcriptText(note, false)).toBe("A note.\nMore.");
    expect(transcriptText(note, true)).toBe("[0:00] A note.\n[0:01] More.");
    expect(transcriptText([], true)).toBe("");
  });
});

describe("prettyShortcut", () => {
  it("uses symbols on macOS and words elsewhere", () => {
    expect(prettyShortcut("CommandOrControl+Shift+Space", "macos")).toBe("⌘⇧Space");
    expect(prettyShortcut("CommandOrControl+Shift+R", "windows")).toBe("Ctrl + Shift + R");
    expect(prettyShortcut(undefined, "windows")).toBe("Not set");
  });
});

describe("acceleratorFromEvent", () => {
  it("builds an accelerator with at least one modifier", () => {
    expect(acceleratorFromEvent({ ctrlKey: true, metaKey: false, shiftKey: true, altKey: false, code: "KeyR" }, "windows")).toBe(
      "CommandOrControl+Shift+R",
    );
    expect(acceleratorFromEvent({ ctrlKey: false, metaKey: true, shiftKey: false, altKey: false, code: "Space" }, "macos")).toBe(
      "CommandOrControl+Space",
    );
  });

  it("rejects modifier-only presses and bare keys", () => {
    expect(acceleratorFromEvent({ ctrlKey: true, metaKey: false, shiftKey: false, altKey: false, code: "ShiftLeft" }, "windows")).toBeNull();
    expect(acceleratorFromEvent({ ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, code: "KeyR" }, "windows")).toBeNull();
  });
});

describe("shared labels", () => {
  it("cover every source device and app", () => {
    expect(SOURCE_DEVICE_LABELS).toMatchObject({ phone: "Phone", desktop: "Desktop", web: "Web" });
    expect(SOURCE_APP_LABELS.whatsapp_business).toBe("WhatsApp Business");
    expect(SOURCE_APP_LABELS.voice_note).toBe("Voice note");
  });
});
